<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\BulkAction
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BulkAction;

use InvalidArgumentException;
use OCA\Dossiq\BulkAction\SetCaseAttributeAction;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The attribute write: the object OpenRegister receives, the rehearsal and
 * the configuration it needs.
 *
 * @covers \OCA\Dossiq\BulkAction\SetCaseAttributeAction
 */
class SetCaseAttributeActionTest extends TestCase {

	/**
	 * A localisation double that answers its own input.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}//end l10n()

	/**
	 * An object service double that records its updates, or throws.
	 *
	 * @param \Throwable|null $throw What updateObject throws, if anything.
	 *
	 * @return object The double; its `updates` property lists the calls.
	 */
	private function objectService(?\Throwable $throw = null): object {
		return new class($throw) {
			/**
			 * The recorded updates.
			 *
			 * @var list<array{objectId: string, data: array<string, mixed>}>
			 */
			public array $updates = [];

			/**
			 * Constructor.
			 *
			 * @param \Throwable|null $throw What updateObject throws.
			 */
			public function __construct(private readonly ?\Throwable $throw) {
			}//end __construct()

			/**
			 * Record one update.
			 *
			 * @param string               $objectId The object id.
			 * @param array<string, mixed> $data     The object data.
			 *
			 * @return void
			 */
			public function updateObject(string $objectId, array $data): void {
				if ($this->throw !== null) {
					throw $this->throw;
				}

				$this->updates[] = ['objectId' => $objectId, 'data' => $data];
			}//end updateObject()
		};
	}//end objectService()

	/**
	 * Settings that hand out the given object service and configuration.
	 *
	 * @param object|null           $objectService The object service.
	 * @param array<string, string> $config        The config values.
	 *
	 * @return SettingsService The double.
	 */
	private function settings(?object $objectService, array $config = ['register' => '3', 'case_schema' => '7']): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		return $settings;
	}//end settings()

	/**
	 * Run the action over one case.
	 *
	 * @param SettingsService      $settings   The settings double.
	 * @param array<string, mixed> $case       The case payload.
	 * @param array<string, mixed> $parameters The job parameters.
	 * @param bool                 $commit     Commit or rehearse.
	 * @param string               $uuid       The case uuid.
	 *
	 * @return BulkActionResult The outcome.
	 */
	private function applyTo(SettingsService $settings, array $case, array $parameters, bool $commit = true, string $uuid = 'case-1'): BulkActionResult {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($case);

		return (new SetCaseAttributeAction(settingsService: $settings, l10n: $this->l10n()))->apply(
			step: new BulkStep(object: $object, parameters: $parameters, commit: $commit, actor: null)
		);
	}//end applyTo()

	/**
	 * A committed write sends the whole case with the one field changed.
	 *
	 * @return void
	 */
	public function testACommittedWriteSendsTheCaseWithTheNewValue(): void {
		$objectService = $this->objectService();

		$result = $this->applyTo(
			$this->settings($objectService),
			['title' => 'Permit', 'confidentiality' => 'vertrouwelijk'],
			['property' => ' confidentiality ', 'value' => 'openbaar']
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
		$this->assertSame(
			expected: [['objectId' => 'case-1', 'data' => ['id' => 'case-1', 'title' => 'Permit', 'confidentiality' => 'openbaar']]],
			actual: $objectService->updates
		);
	}//end testACommittedWriteSendsTheCaseWithTheNewValue()

	/**
	 * A field the case does not carry yet is added, also with a null value.
	 *
	 * @return void
	 */
	public function testAnAbsentFieldIsAddedEvenAsNull(): void {
		$objectService = $this->objectService();

		$result = $this->applyTo($this->settings($objectService), ['title' => 'Permit'], ['property' => 'note', 'value' => null]);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
		$this->assertSame(expected: ['id' => 'case-1', 'title' => 'Permit', 'note' => null], actual: $objectService->updates[0]['data']);
	}//end testAnAbsentFieldIsAddedEvenAsNull()

	/**
	 * A value that differs only in type is not the same value.
	 *
	 * @return void
	 */
	public function testAValueOfAnotherTypeIsWritten(): void {
		$objectService = $this->objectService();

		$result = $this->applyTo($this->settings($objectService), ['priority' => '1'], ['property' => 'priority', 'value' => 1]);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
		$this->assertSame(expected: 1, actual: $objectService->updates[0]['data']['priority']);
	}//end testAValueOfAnotherTypeIsWritten()

	/**
	 * A rehearsal is applied without touching OpenRegister.
	 *
	 * @return void
	 */
	public function testARehearsalWritesNothing(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$result = $this->applyTo($settings, ['priority' => 'low'], ['property' => 'priority', 'value' => 'high'], false);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testARehearsalWritesNothing()

	/**
	 * A missing register or schema fails the member; nothing is written.
	 *
	 * @return void
	 */
	public function testAMissingRegisterOrSchemaIsFailed(): void {
		foreach ([['register' => '', 'case_schema' => '7'], ['register' => '3', 'case_schema' => '']] as $config) {
			$objectService = $this->objectService();

			$result = $this->applyTo($this->settings($objectService, $config), [], ['property' => 'priority', 'value' => 'high']);

			$this->assertSame(expected: BulkJobMember::OUTCOME_FAILED, actual: $result->getOutcome());
			$this->assertSame(expected: 'OpenRegister is not available', actual: $result->getReason());
			$this->assertSame(expected: [], actual: $objectService->updates);
		}
	}//end testAMissingRegisterOrSchemaIsFailed()

	/**
	 * An update that throws fails the member with its message.
	 *
	 * @return void
	 */
	public function testAnUpdateThatThrowsIsFailed(): void {
		$result = $this->applyTo(
			$this->settings($this->objectService(new RuntimeException('validation failed'))),
			[],
			['property' => 'priority', 'value' => 'high']
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_FAILED, actual: $result->getOutcome());
		$this->assertSame(expected: 'validation failed', actual: $result->getReason());
	}//end testAnUpdateThatThrowsIsFailed()

	/**
	 * A case without an id is skipped.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdIsSkipped(): void {
		$result = $this->applyTo($this->settings($this->objectService()), [], ['property' => 'p', 'value' => 'v'], true, '');

		$this->assertSame(expected: 'no_case_id', actual: $result->getReason());
	}//end testACaseWithoutAnIdIsSkipped()

	/**
	 * The parameters need a property and a value key; a null value is a value.
	 *
	 * @return void
	 */
	public function testTheParametersNeedAPropertyAndAValue(): void {
		$action = new SetCaseAttributeAction(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			l10n: $this->l10n()
		);

		$this->assertSame(expected: 'Set one field on the cases', actual: $action->getLabel());
		$this->assertFalse($action->requiresJustification());
		$action->validateParameters(['property' => 'note', 'value' => null]);

		foreach ([[['value' => 'x'], 'property is required'], [['property' => 'note'], 'value is required']] as [$parameters, $message]) {
			try {
				$action->validateParameters($parameters);
				$this->fail('accepted ' . json_encode($parameters));
			} catch (InvalidArgumentException $e) {
				$this->assertSame(expected: $message, actual: $e->getMessage());
			}
		}
	}//end testTheParametersNeedAPropertyAndAValue()
}//end class
