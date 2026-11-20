<?php

/**
 * Unit tests for CaseReadStateTriggersListener.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\Dossiq\Kernel\Contracts\CaseTypeResolverInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\CaseReadStateTriggersListener;
use OCA\Dossiq\Service\UnreadTriggerService;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectReadStatePropertiesEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A case's type narrows which changes make it unread (task 3.1b).
 */
#[CoversClass(CaseReadStateTriggersListener::class)]
#[UsesClass(UnreadTriggerService::class)]
final class CaseReadStateTriggersListenerTest extends TestCase {

	private const FLOOR = ['status', 'assignee', 'deadline', 'result'];

	private SettingsServiceInterface&MockObject $settings;

	private CaseTypeResolverInterface&MockObject $caseTypes;

	protected function setUp(): void {
		$this->settings  = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$this->caseTypes = $this->createMock(originalClassName: CaseTypeResolverInterface::class);
		$this->settings->method('getConfigValue')->willReturnMap([['case_schema', '', '12']]);
	}//end setUp()

	private function listener(): CaseReadStateTriggersListener {
		return new CaseReadStateTriggersListener(
			settingsService: $this->settings,
			caseTypes: $this->caseTypes,
			triggers: new UnreadTriggerService(l10n: new SourceL10n())
		);
	}//end listener()

	private static function event(int $schemaId, string $slug, array $body): ObjectReadStatePropertiesEvent {
		$schema = new Schema();
		$schema->setId($schemaId);
		$schema->setSlug($slug);
		$object = new ObjectEntity();
		$object->setObject($body);

		return new ObjectReadStatePropertiesEvent(object: $object, schema: $schema, declared: self::FLOOR);
	}//end event()

	public function testACaseKeepsOnlyWhatItsTypeDeclares(): void {
		$this->caseTypes->expects($this->once())->method('effectiveCaseType')->with('type-1')
			->willReturn(['unreadTriggers' => ['status', 'deadline', 'documents']]);
		$event = self::event(schemaId: 12, slug: 'zaak', body: ['caseType' => 'type-1']);

		$this->listener()->handle(event: $event);

		$this->assertSame(expected: ['status', 'deadline'], actual: $event->getProperties());
	}//end testACaseKeepsOnlyWhatItsTypeDeclares()

	public function testATypeThatDeclaresNothingGetsTheDefaultStatus(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn(['title' => 'Woo-verzoek']);
		$event = self::event(schemaId: 3, slug: 'case', body: ['caseType' => 'type-1']);

		$this->listener()->handle(event: $event);

		$this->assertSame(expected: ['status'], actual: $event->getProperties());
	}//end testATypeThatDeclaresNothingGetsTheDefaultStatus()

	public function testATypeNamingOnlySubResourcesCountsNoProperty(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn(['unreadTriggers' => ['documents', 'messages']]);
		$event = self::event(schemaId: 12, slug: 'case', body: ['caseType' => 'type-1']);

		$this->listener()->handle(event: $event);

		$this->assertSame(expected: [], actual: $event->getProperties());
	}//end testATypeNamingOnlySubResourcesCountsNoProperty()

	public function testAnUnreadableTypeKeepsTheSchemasList(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn([]);
		$event = self::event(schemaId: 12, slug: 'case', body: ['caseType' => 'gone']);

		$this->listener()->handle(event: $event);

		$this->assertSame(expected: self::FLOOR, actual: $event->getProperties());
	}//end testAnUnreadableTypeKeepsTheSchemasList()

	public function testACaseWithoutATypeIsLeftAlone(): void {
		$this->caseTypes->expects($this->never())->method('effectiveCaseType');
		$event = self::event(schemaId: 12, slug: 'case', body: ['caseType' => ['not', 'a', 'uuid']]);

		$this->listener()->handle(event: $event);

		$this->assertSame(expected: self::FLOOR, actual: $event->getProperties());
	}//end testACaseWithoutATypeIsLeftAlone()

	public function testAnotherSchemaIsLeftAlone(): void {
		$this->caseTypes->expects($this->never())->method('effectiveCaseType');
		$event = self::event(schemaId: 40, slug: 'contactmoment', body: ['caseType' => 'type-1']);

		$this->listener()->handle(event: $event);

		$this->assertSame(expected: self::FLOOR, actual: $event->getProperties());
	}//end testAnotherSchemaIsLeftAlone()

	public function testAnotherEventIsIgnored(): void {
		$this->caseTypes->expects($this->never())->method('effectiveCaseType');

		$this->listener()->handle(event: new Event());

		$this->assertSame(expected: 'OCA\OpenRegister\Event\ObjectReadStatePropertiesEvent', actual: CaseReadStateTriggersListener::EVENT);
	}//end testAnotherEventIsIgnored()
}//end class
