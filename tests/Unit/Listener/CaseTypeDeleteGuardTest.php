<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseTypeDeleteGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A case type is deleted only while it is an unused draft, and takes its
 * status types with it, on every path that deletes it.
 *
 * @covers \OCA\Dossiq\Listener\CaseTypeDeleteGuard
 */
class CaseTypeDeleteGuardTest extends TestCase {

	/**
	 * @var InMemoryRegister The register the guard reads.
	 */
	private InMemoryRegister $store;

	/**
	 * @var bool Whether OpenRegister answers.
	 */
	private bool $reachable = true;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->reachable = true;
		$this->store->seed(schema: 'statusType', uuid: 'st-1', row: ['caseType' => 'ct-1', 'name' => 'Ontvangen']);
		$this->store->seed(schema: 'statusType', uuid: 'st-2', row: ['caseType' => 'ct-1', 'name' => 'Afgehandeld']);
		$this->store->seed(schema: 'statusType', uuid: 'st-3', row: ['caseType' => 'ct-2', 'name' => 'Ontvangen']);
	}//end setUp()

	/**
	 * @param bool   $isDraft Whether the case type is a draft.
	 * @param string $schema  The schema of the deleted object.
	 *
	 * @return ObjectDeletingEvent The event.
	 */
	private function deleteOf(bool $isDraft, string $schema = 'caseType'): ObjectDeletingEvent {
		$entity = new ObjectEntity();
		$entity->setObject(['title' => 'Melding openbare ruimte', 'isDraft' => $isDraft]);
		$entity->setSchema($schema);
		$entity->setUuid('ct-1');

		return new ObjectDeletingEvent($entity);
	}//end deleteOf()

	/**
	 * @return CaseTypeDeleteGuard The guard over the in-memory register.
	 */
	private function guard(): CaseTypeDeleteGuard {
		$config = ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType', 'status_type_schema' => 'statusType'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturnCallback(fn (): ?InMemoryRegister => ($this->reachable === true ? $this->store : null));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		return new CaseTypeDeleteGuard(settingsService: $settings, l10n: $l10n, logger: new NullLogger());
	}//end guard()

	/**
	 * @return void
	 */
	public function testAPublishedCaseTypeIsNotDeleted(): void {
		$event = $this->deleteOf(isDraft: false);

		$this->guard()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('case-type-published', $event->getErrors()['error']);
		$this->assertCount(2, array_filter($this->store->all('statusType'), static fn (array $row): bool => $row['caseType'] === 'ct-1'));
	}//end testAPublishedCaseTypeIsNotDeleted()

	/**
	 * @return void
	 */
	public function testADraftThatCasesUseIsNotDeleted(): void {
		$this->store->seed(schema: 'case', uuid: 'c-1', row: ['caseType' => 'ct-1']);
		$event = $this->deleteOf(isDraft: true);

		$this->guard()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('case-type-in-use', $event->getErrors()['error']);
	}//end testADraftThatCasesUseIsNotDeleted()

	/**
	 * @return void
	 */
	public function testAnUnusedDraftGoesWithItsStatusTypes(): void {
		$this->store->seed(schema: 'case', uuid: 'c-1', row: ['caseType' => 'ct-2']);
		$event = $this->deleteOf(isDraft: true);

		$this->guard()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $this->store->row('statusType', 'st-1'));
		$this->assertSame([], $this->store->row('statusType', 'st-2'));
		$this->assertNotSame([], $this->store->row('statusType', 'st-3'));
	}//end testAnUnusedDraftGoesWithItsStatusTypes()

	/**
	 * @return void
	 */
	public function testAnUncheckableDeleteIsRefusedAndOtherSchemasPass(): void {
		$this->reachable = false;
		$event = $this->deleteOf(isDraft: true);
		$this->guard()->handle($event);
		$this->assertSame('case-type-unchecked', $event->getErrors()['error']);

		$other = $this->deleteOf(isDraft: false, schema: 'statusType');
		$this->guard()->handle($other);
		$this->assertFalse($other->isPropagationStopped());
	}//end testAnUncheckableDeleteIsRefusedAndOtherSchemasPass()
}//end class
