<?php

/**
 * Unit tests for ZtcPublishAndDeleteChecks.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\ZgwRuleLookups;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Service\Zgw\ZtcPublishAndDeleteChecks;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What a case type needs before it is published or deleted, asked of the class itself.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZtcPublishAndDeleteChecks
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\DeadlineMonitoringSeedDataService
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ZtcPublishAndDeleteChecksTest extends TestCase {
	private const DEFAULT = ['blocked' => false, 'requiresConfirmation' => false, 'message' => ''];

	private const UNVERIFIED = [
		'blocked' => true,
		'requiresConfirmation' => false,
		'unverified' => true,
		'message' => 'Cannot delete case type: the check for cases that use this type could not be completed. Nothing was deleted. Try again later.',
	];

	/**
	 * The context of every error logged.
	 *
	 * @var array<int, array>
	 */
	private array $errors = [];

	/**
	 * Build the checks over a store answering searches per schema and one case type row.
	 *
	 * @param array<string, array|\Throwable>|null $rows Search answer per schema, or null for no context.
	 * @param array|\Throwable|null $caseType The case type row, a failure, or null for none.
	 *
	 * @return ZtcPublishAndDeleteChecks
	 */
	private function checks(?array $rows, array|\Throwable|null $caseType = null): ZtcPublishAndDeleteChecks {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $key);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = $context;
			}
		);
		$lookups = new ZgwRuleLookups(settingsService: $settings);
		$checks  = new ZtcPublishAndDeleteChecks(lookups: $lookups, results: new ZgwRuleResults(logger: $logger), settingsService: $settings);
		if ($rows === null) {
			return $checks;
		}

		$store = new class($rows, $caseType) {
			public function __construct(
				private array $rows,
				private array|\Throwable|null $caseType,
			) {
			}//end __construct()

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$key = $schema;
				if (isset($filters['isFinal']) === true) {
					$key .= ':final';
				}

				$answer = ($this->rows[$key] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}//end searchObjectsBySlug()

			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): array {
				if ($this->caseType === null) {
					throw new DoesNotExistException('gone');
				}

				if ($this->caseType instanceof \Throwable) {
					throw $this->caseType;
				}

				return $this->caseType;
			}//end find()
		};
		$lookups->setContext(objectService: $store, mappingConfig: []);

		return $checks;
	}//end checks()

	/**
	 * A case type with a final status type and a valid-from date may be published.
	 *
	 * @return void
	 */
	public function testACompleteCaseTypeMayBePublished(): void {
		$checks = $this->checks(
			rows: ['status_type_schema' => [['isFinal' => false], ['isFinal' => true]]],
			caseType: ['validFrom' => '2026-01-01']
		);

		$this->assertSame([], $checks->validatePublish(register: 'reg', caseTypeId: 'ct-1'));
	}//end testACompleteCaseTypeMayBePublished()

	/**
	 * Every missing precondition is named; a case type that cannot be read has no valid-from date.
	 *
	 * @return void
	 */
	public function testWhatIsMissingIsNamed(): void {
		$validFrom = "'Valid from' date must be set before publishing";

		$this->assertSame(
			['At least one status type must be defined before publishing', $validFrom],
			$this->checks(rows: [])->validatePublish(register: 'reg', caseTypeId: 'ct-1')
		);
		$this->assertSame(
			['At least one status type must be marked as final', $validFrom],
			$this->checks(rows: ['status_type_schema' => [['isFinal' => false], 'no row']], caseType: new \RuntimeException('down'))
				->validatePublish(register: 'reg', caseTypeId: 'ct-1')
		);
		$this->assertSame(
			[$validFrom],
			$this->checks(rows: ['status_type_schema' => [['isFinal' => 1]]], caseType: ['validFrom' => ''])
				->validatePublish(register: 'reg', caseTypeId: 'ct-1')
		);
	}//end testWhatIsMissingIsNamed()

	/**
	 * Without a store, an id or readable status types the check stops with one reason.
	 *
	 * @return void
	 */
	public function testThePublishCheckStopsWhenItCannotRun(): void {
		$this->assertSame(
			['Cannot validate publish: OpenRegister object service unavailable'],
			$this->checks(rows: null)->validatePublish(register: 'reg', caseTypeId: 'ct-1')
		);
		$this->assertSame(
			['Cannot validate publish: case type id is empty'],
			$this->checks(rows: [])->validatePublish(register: 'reg', caseTypeId: '')
		);
		$this->assertSame(
			['Could not load status types for case type'],
			$this->checks(rows: ['status_type_schema' => new \RuntimeException('down')])->validatePublish(register: 'reg', caseTypeId: 'ct-1')
		);
	}//end testThePublishCheckStopsWhenItCannotRun()

	/**
	 * A case type nobody uses may be deleted, and without an id there is nothing to check.
	 *
	 * @return void
	 */
	public function testAnUnusedCaseTypeMayBeDeleted(): void {
		$this->assertSame(self::DEFAULT, $this->checks(rows: [])->validateDeletion(register: 'reg', caseTypeId: 'ct-1'));
		$this->assertSame(self::DEFAULT, $this->checks(rows: ['case_schema' => [['status' => 'open']]])->validateDeletion(register: 'reg', caseTypeId: ''));
		$this->assertSame([], $this->errors);
	}//end testAnUnusedCaseTypeMayBeDeleted()

	/**
	 * A delete check that cannot run blocks the delete as unverified, and the log names the check and
	 * the case type: no store, cases that cannot be read, final statuses that cannot be read.
	 *
	 * Control: testAnUnusedCaseTypeMayBeDeleted, where the same read answers no cases.
	 *
	 * @return void
	 */
	public function testACheckThatCannotRunBlocksTheDelete(): void {
		$down = new \RuntimeException('down');

		$this->assertSame(self::UNVERIFIED, $this->checks(rows: null)->validateDeletion(register: 'reg', caseTypeId: 'ct-1'));
		$this->assertSame(self::UNVERIFIED, $this->checks(rows: ['case_schema' => $down])->validateDeletion(register: 'reg', caseTypeId: 'ct-1'));
		$this->assertSame(
			self::UNVERIFIED,
			$this->checks(rows: ['case_schema' => [['status' => 'final']], 'status_type_schema:final' => $down])
				->validateDeletion(register: 'reg', caseTypeId: 'ct-1')
		);
		$this->assertSame(
			[
				['rule' => 'ztc case type delete check (REQ-CT-01d)', 'object' => 'case type ct-1', 'cause' => 'No object service in the request context'],
				['rule' => 'ztc case type delete check (REQ-CT-01d)', 'object' => 'case type ct-1', 'cause' => 'down'],
				['rule' => 'ztc case type delete check (REQ-CT-01d)', 'object' => 'case type ct-1', 'cause' => 'down'],
			],
			$this->errors
		);
	}//end testACheckThatCannotRunBlocksTheDelete()

	/**
	 * Active cases block the delete; a case is closed only on a final status.
	 *
	 * @return void
	 */
	public function testActiveCasesBlockTheDelete(): void {
		$cases = [['status' => 'final'], ['status' => 'open'], ['status' => ''], []];

		$this->assertSame(
			[
				'blocked' => true,
				'requiresConfirmation' => false,
				'message' => 'Cannot delete case type: 3 active case(s) still use this type. Close or reassign all cases first.',
			],
			$this->checks(rows: ['case_schema' => $cases, 'status_type_schema:final' => [['id' => 'final'], 'no row', ['name' => 'no id']]])
				->validateDeletion(register: 'reg', caseTypeId: 'ct-1')
		);
	}//end testActiveCasesBlockTheDelete()

	/**
	 * Closed cases only: the delete asks for confirmation and says how many it affects.
	 *
	 * @return void
	 */
	public function testClosedCasesAskForConfirmation(): void {
		$this->assertSame(
			['blocked' => false, 'requiresConfirmation' => true, 'message' => 'Deleting will affect 2 closed case(s). Confirm to proceed.'],
			$this->checks(rows: ['case_schema' => [['status' => 'final'], ['status' => 'final']], 'status_type_schema:final' => [['id' => 'final']]])
				->validateDeletion(register: 'reg', caseTypeId: 'ct-1')
		);
	}//end testClosedCasesAskForConfirmation()
}//end class
