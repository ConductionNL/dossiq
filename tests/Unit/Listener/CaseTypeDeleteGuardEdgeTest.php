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
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The case-type delete guard when OpenRegister cannot answer, when a setting
 * is missing, and on events it does not guard.
 *
 * @covers \OCA\Dossiq\Listener\CaseTypeDeleteGuard
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseTypeDeleteGuardEdgeTest extends TestCase {

	/**
	 * @var InMemoryRegister The register the guard reads.
	 */
	private InMemoryRegister $store;

	/**
	 * @var list<array{0: string, 1: array<string, mixed>}> The warnings logged.
	 */
	private array $warnings = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->warnings = [];
		$this->store->seed(schema: 'statusType', uuid: 'st-1', row: ['caseType' => 'ct-1', 'name' => 'Ontvangen']);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testCasesThatCannotBeReadRefuseTheDeleteAsUnchecked(): void {
		$this->store->missingSchemas = ['case'];
		$event = $this->deleteOf(schema: 'caseType');

		$this->guard()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('case-type-unchecked', $event->getErrors()['error']);
		$this->assertSame('Dossiq: could not read the cases of a case type', $this->warnings[0][0]);
		$this->assertSame('ct-1', $this->warnings[0][1]['caseType']);
		$this->assertNotSame([], $this->store->row('statusType', 'st-1'));
	}//end testCasesThatCannotBeReadRefuseTheDeleteAsUnchecked()

	/**
	 * @return void
	 */
	public function testStatusTypesThatCannotBeReadDoNotBlockTheDelete(): void {
		$this->store->missingSchemas = ['statusType'];
		$event = $this->deleteOf(schema: 'caseType');

		$this->guard()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
		$this->assertSame(['Dossiq: could not remove the status types of a deleted case type'], array_column($this->warnings, 0));
	}//end testStatusTypesThatCannotBeReadDoNotBlockTheDelete()

	/**
	 * @return void
	 */
	public function testWithoutAStatusTypeSchemaNothingIsRemoved(): void {
		$event = $this->deleteOf(schema: 'caseType');

		$this->guard(config: ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'])->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertNotSame([], $this->store->row('statusType', 'st-1'));
		$this->assertSame([], $this->warnings);
	}//end testWithoutAStatusTypeSchemaNothingIsRemoved()

	/**
	 * @return void
	 */
	public function testAnObjectWithoutASchemaAndAnotherEventAreLeftAlone(): void {
		$event = $this->deleteOf(schema: '');
		$guard = $this->guard();

		$guard->handle($event);
		$guard->handle(new Event());

		$this->assertFalse($event->isPropagationStopped());
		$this->assertNotSame([], $this->store->row('statusType', 'st-1'));
	}//end testAnObjectWithoutASchemaAndAnotherEventAreLeftAlone()

	/**
	 * @param string $schema The schema of the deleted draft.
	 *
	 * @return ObjectDeletingEvent The event.
	 */
	private function deleteOf(string $schema): ObjectDeletingEvent {
		$entity = new ObjectEntity();
		$entity->setObject(['title' => 'Melding openbare ruimte', 'isDraft' => true]);
		$entity->setSchema($schema);
		$entity->setUuid('ct-1');

		return new ObjectDeletingEvent($entity);
	}//end deleteOf()

	/**
	 * @param array<string, string>|null $config The app settings; all set when null.
	 *
	 * @return CaseTypeDeleteGuard The guard over the in-memory register.
	 */
	private function guard(?array $config = null): CaseTypeDeleteGuard {
		$config ??= ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType', 'status_type_schema' => 'statusType'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn($this->store);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new CaseTypeDeleteGuard(settingsService: $settings, l10n: $l10n, logger: $logger);
	}//end guard()
}//end class
