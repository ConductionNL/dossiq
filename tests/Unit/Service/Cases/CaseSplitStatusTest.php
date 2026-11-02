<?php

/**
 * The status a split's second case starts in and ends in.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseSplitExecutor;
use OCA\Dossiq\Service\Cases\CaseSplitPlan;
use OCA\Dossiq\Service\Cases\CaseSplitPolicy;
use OCA\Dossiq\Service\Cases\CaseSplitStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseMoveLedger;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A split opens the second case the way OpenRegister lets a handler create
 * one, in its case type's initial status, and then moves it to the status of
 * the original through the status engine's own write step.
 *
 * The register below refuses a create in any other status, as OpenRegister
 * does once a lifecycle schema declares its initial state (openregister#5400).
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitExecutor
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitStore
 * @uses \OCA\Dossiq\Service\Cases\CaseSplitPlan
 * @uses \OCA\Dossiq\Service\Cases\CaseSplitPolicy
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Transitions\CaseMoveLedger
 * @uses \OCA\Dossiq\Service\Transitions\CaseStatusStore
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitStatusTest extends TestCase {

	/**
	 * The register, refusing a create outside the initial status.
	 *
	 * @var InMemoryRegister&object{states: array<string, array<int, string>>, rbac: array<int, bool>, mayWriteCases: bool}
	 */
	private InMemoryRegister $register;

	/**
	 * A case under review, of a case type that starts in intake.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new class extends InMemoryRegister {
			/**
			 * Every status each case was saved in, in order.
			 *
			 * @var array<string, array<int, string>>
			 */
			public array $states = [];

			/**
			 * The RBAC flag of every case write.
			 *
			 * @var array<int, bool>
			 */
			public array $rbac = [];

			/**
			 * Whether the handler holds write rights on the case type.
			 *
			 * @var bool
			 */
			public bool $mayWriteCases = true;

			/**
			 * Save a row, starting a new case in its case type's initial status.
			 *
			 * @param array<string, mixed> $object        The row.
			 * @param int|string           $register      Ignored.
			 * @param int|string           $schema        The schema slug.
			 * @param string|null          $uuid          The uuid to update, or null.
			 * @param bool                 $_rbac         Whether the caller's rights apply.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(
				array $object,
				int|string $register = '',
				int|string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($schema !== 'case') {
					return parent::saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid, _rbac: $_rbac);
				}

				$this->rbac[] = $_rbac;
				if ($_rbac === true && $this->mayWriteCases === false) {
					throw new RuntimeException("User 'jan' does not have permission to 'create' objects in schema 'case'");
				}

				if ($uuid === null && isset($object['id']) === true) {
					$uuid = (string)$object['id'];
				}

				if ($uuid === null || isset($this->rows['case'][$uuid]) === false) {
					$initial = (string)($this->rows['caseType'][(string)($object['caseType'] ?? '')]['initialStatus'] ?? '');
					$asked = (string)($object['status'] ?? '');
					if ($asked !== '' && $asked !== $initial) {
						throw new RuntimeException('lifecycle-invalid-initial: a case starts in ' . $initial . ', not ' . $asked);
					}

					$object['status'] = $initial;
				}

				$saved = parent::saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid, _rbac: $_rbac);
				$this->states[(string)$saved['id']][] = (string)$saved['status'];

				return $saved;
			}
		};

		$this->register->seed(schema: 'caseType', uuid: 'ct-1', row: ['title' => 'Melding', 'initialStatus' => 'st-intake']);
		$this->register->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Twee klachten', 'caseType' => 'ct-1', 'identifier' => 'ZAAK-1', 'status' => 'st-review'],
		);
		$this->register->seed(schema: 'caseDocument', uuid: 'doc-1', row: ['case' => 'case-1', 'title' => 'Brief']);
	}//end setUp()

	/**
	 * The second case first exists in its initial status, then ends in the original's.
	 *
	 * @return void
	 */
	public function testTheSecondCaseOpensInItsInitialStatusAndTakesTheOriginals(): void {
		$answer = $this->split();

		$newId = (string)$answer['case']['id'];
		self::assertSame(expected: ['st-intake', 'st-review'], actual: $this->register->states[$newId]);
		self::assertSame(expected: 'st-review', actual: $answer['case']['status']);
		self::assertSame(expected: $newId, actual: $this->register->row(schema: 'caseDocument', uuid: 'doc-1')['case']);
	}//end testTheSecondCaseOpensInItsInitialStatusAndTakesTheOriginals()

	/**
	 * The move is in the second case's history, naming the original and who split it.
	 *
	 * @return void
	 */
	public function testTheMoveIsRecordedAsSplitFromTheOriginal(): void {
		$answer = $this->split();

		$records = $this->register->all(schema: 'statusRecord');
		self::assertCount(expectedCount: 1, haystack: $records);
		self::assertSame(expected: (string)$answer['case']['id'], actual: $records[0]['case']);
		self::assertSame(expected: 'st-review', actual: $records[0]['statusType']);
		self::assertSame(expected: 'st-intake', actual: $records[0]['fromStatus']);
		self::assertSame(expected: 'Split from ZAAK-1', actual: $records[0]['transitionLabel']);
		self::assertSame(expected: 'jan', actual: $records[0]['actor']);
		self::assertTrue(condition: $records[0]['noWorkflowTemplate']);
	}//end testTheMoveIsRecordedAsSplitFromTheOriginal()

	/**
	 * Every case write runs with the handler's own rights.
	 *
	 * @return void
	 */
	public function testNoCaseWriteIsElevated(): void {
		$this->split();

		self::assertNotSame(expected: [], actual: $this->register->rbac);
		self::assertNotContains(needle: false, haystack: $this->register->rbac);
	}//end testNoCaseWriteIsElevated()

	/**
	 * A handler who may not write cases of this type is refused, and nothing moves.
	 *
	 * @return void
	 */
	public function testAHandlerWhoMayNotWriteCasesIsRefused(): void {
		$this->register->mayWriteCases = false;

		try {
			$this->split();
			self::fail(message: 'A handler without write rights split a case.');
		} catch (RefusedException $refused) {
			self::assertSame(expected: CaseSplitExecutor::UNWRITABLE, actual: $refused->getRule());
		}

		self::assertSame(expected: 'case-1', actual: $this->register->row(schema: 'caseDocument', uuid: 'doc-1')['case']);
		self::assertSame(expected: [], actual: $this->register->all(schema: 'statusRecord'));
	}//end testAHandlerWhoMayNotWriteCasesIsRefused()

	/**
	 * An original still in the initial status needs no move and no history entry.
	 *
	 * @return void
	 */
	public function testAnOriginalInTheInitialStatusIsNotMovedAgain(): void {
		$this->register->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Twee klachten', 'caseType' => 'ct-1', 'identifier' => 'ZAAK-1', 'status' => 'st-intake'],
		);

		$answer = $this->split();

		self::assertSame(expected: ['st-intake'], actual: $this->register->states[(string)$answer['case']['id']]);
		self::assertSame(expected: [], actual: $this->register->all(schema: 'statusRecord'));
	}//end testAnOriginalInTheInitialStatusIsNotMovedAgain()

	/**
	 * A move the history cannot record is refused before anything moves.
	 *
	 * @return void
	 */
	public function testAMoveThatCannotBeRecordedIsRefused(): void {
		$this->register->refusedSchemas = ['statusRecord'];

		try {
			$this->split();
			self::fail(message: 'A split whose status move failed went on.');
		} catch (RefusedException $refused) {
			self::assertSame(expected: CaseSplitExecutor::UNWRITABLE, actual: $refused->getRule());
			self::assertInstanceOf(expected: RuntimeException::class, actual: $refused->getPrevious());
		}

		self::assertSame(expected: 'case-1', actual: $this->register->row(schema: 'caseDocument', uuid: 'doc-1')['case']);
	}//end testAMoveThatCannotBeRecordedIsRefused()

	/**
	 * Split case-1, moving doc-1, as jan.
	 *
	 * @return array<string, mixed> The executor's answer.
	 */
	private function split(): array {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'case_document_schema' => 'caseDocument',
					'role_schema' => 'role',
					'status_record_schema' => 'statusRecord',
				];

				return ($map[$key] ?? $default);
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$results = $this->createMock(originalClassName: CaseResultWriter::class);
		$results->method('isFinalStatus')->willReturn(false);
		$ledger = new CaseMoveLedger(
			store: new CaseStatusStore(
				settingsService: $settings,
				statusTypeLookup: $this->createMock(originalClassName: StatusTypeLookup::class),
				logger: $logger,
			),
			resultWriter: $results,
			dispatcher: $this->createMock(originalClassName: SideEffectDispatcher::class),
			logger: $logger,
		);

		$executor = new CaseSplitExecutor(
			policy: new CaseSplitPolicy(),
			plan: new CaseSplitPlan(),
			logger: $logger,
			store: new CaseSplitStore(settingsService: $settings, ledger: $ledger),
		);

		return $executor->split(caseId: 'case-1', title: 'De tweede klacht', chosen: ['documents' => ['doc-1']], actor: 'jan');
	}//end split()
}//end class
