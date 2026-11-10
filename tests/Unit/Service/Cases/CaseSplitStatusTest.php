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
use OCA\Dossiq\Service\Timeline\StatusMoveEntry;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
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
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitStatusTest extends TestCase {

	/**
	 * The register, refusing a create outside the initial status.
	 *
	 * @var InMemoryRegister&object{
	 *     states: array<string, array<int, string>>, rbac: array<int, bool>,
	 *     mayWriteCases: bool, mayUpdateCases: bool, mayDeleteCases: bool
	 * }
	 */
	private InMemoryRegister $register;

	/**
	 * The result type id of every closing result the engine was asked to resolve.
	 *
	 * @var array<int, string|null>
	 */
	private array $resolvedWith = [];

	/**
	 * Whether reading whether a status is final fails.
	 *
	 * @var bool
	 */
	private bool $finalUnreadable = false;

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
			 * Whether the handler may change a case once it exists.
			 *
			 * @var bool
			 */
			public bool $mayUpdateCases = true;

			/**
			 * Whether the handler may remove a case.
			 *
			 * @var bool
			 */
			public bool $mayDeleteCases = true;

			/**
			 * Remove one row, refusing a case when the handler may not.
			 *
			 * @param int|string $register Ignored.
			 * @param int|string $schema   The schema slug.
			 * @param string     $uuid     The uuid.
			 *
			 * @return bool True when a row was removed.
			 */
			public function deleteObject(int|string $register = '', int|string $schema = '', string $uuid = ''): bool {
				if ($schema === 'case' && $this->mayDeleteCases === false) {
					throw new RuntimeException("User 'jan' does not have permission to 'delete' objects in schema 'case'");
				}

				return parent::deleteObject(register: $register, schema: $schema, uuid: $uuid);
			}

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
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$register = ($options->target->register ?? '');
				$schema = ($options->target->schema ?? '');
				$uuid = $options->uuid;
				if ($schema !== 'case') {
					return parent::saveObject(object: $object, options: $options, _rbac: $_rbac);
				}

				$this->rbac[] = $_rbac;
				if ($_rbac === true && $this->mayWriteCases === false) {
					throw new RuntimeException("User 'jan' does not have permission to 'create' objects in schema 'case'");
				}

				if ($uuid === null && isset($object['id']) === true) {
					$uuid = (string)$object['id'];
				}

				if ($this->mayUpdateCases === false && $uuid !== null && isset($this->rows['case'][$uuid]) === true) {
					throw new RuntimeException("User 'jan' does not have permission to 'update' objects in schema 'case'");
				}

				if ($uuid === null || isset($this->rows['case'][$uuid]) === false) {
					$initial = (string)($this->rows['caseType'][(string)($object['caseType'] ?? '')]['initialStatus'] ?? '');
					$asked = (string)($object['status'] ?? '');
					if ($asked !== '' && $asked !== $initial) {
						throw new RuntimeException('lifecycle-invalid-initial: a case starts in ' . $initial . ', not ' . $asked);
					}

					$object['status'] = $initial;
				}

				$options = new ObjectSaveOptions(
					target: $options->target,
					uuid: $uuid,
					extend: $options->extend,
					uploadedFiles: $options->uploadedFiles,
					policy: $options->policy,
					actor: $options->actor
				);
				$saved = parent::saveObject(object: $object, options: $options, _rbac: $_rbac);
				$this->states[(string)$saved['id']][] = (string)($saved['status'] ?? '');

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
		$this->assertNothingLeftBehind();
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
	 * An original without a status leaves the second case in its initial status.
	 *
	 * @return void
	 */
	public function testAnOriginalWithoutAStatusLeavesTheInitialStatus(): void {
		$this->register->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Twee klachten', 'caseType' => 'ct-1', 'identifier' => 'ZAAK-1'],
		);

		$answer = $this->split();

		self::assertSame(expected: ['st-intake'], actual: $this->register->states[(string)$answer['case']['id']]);
		self::assertSame(expected: [], actual: $this->register->all(schema: 'statusRecord'));
	}//end testAnOriginalWithoutAStatusLeavesTheInitialStatus()

	/**
	 * A move into a final status settles the end date, as every closing move does.
	 *
	 * @return void
	 */
	public function testAMoveIntoAFinalStatusSettlesTheEndDate(): void {
		$answer = $this->split(final: true);

		self::assertSame(expected: date('Y-m-d'), actual: $answer['case']['endDate']);
	}//end testAMoveIntoAFinalStatusSettlesTheEndDate()

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
	 * A move whose record fails removes the second case again, and says so.
	 *
	 * @return void
	 */
	public function testAMoveThatCannotBeRecordedRemovesTheSecondCase(): void {
		$this->register->refusedSchemas = ['statusRecord'];

		$refused = $this->refusal();

		self::assertSame(expected: CaseSplitExecutor::UNWRITABLE, actual: $refused->getRule());
		self::assertStringContainsString(needle: 'removed again', haystack: $refused->getSentence());
		$this->assertNothingLeftBehind();
	}//end testAMoveThatCannotBeRecordedRemovesTheSecondCase()

	/**
	 * A failure after the record is written removes the record with the case.
	 *
	 * @return void
	 */
	public function testAFailureAfterTheRecordRemovesTheRecordToo(): void {
		$refused = $this->refusal(timeline: false);

		self::assertSame(expected: CaseSplitExecutor::UNWRITABLE, actual: $refused->getRule());
		$this->assertNothingLeftBehind();
	}//end testAFailureAfterTheRecordRemovesTheRecordToo()

	/**
	 * A handler who may create but not update cases is left with nothing.
	 *
	 * @return void
	 */
	public function testAHandlerWhoMayCreateButNotUpdateIsLeftWithNothing(): void {
		$this->register->mayUpdateCases = false;

		$refused = $this->refusal();

		self::assertStringContainsString(needle: 'removed again', haystack: $refused->getSentence());
		$this->assertNothingLeftBehind();
	}//end testAHandlerWhoMayCreateButNotUpdateIsLeftWithNothing()

	/**
	 * A closed case whose type closes with a result is refused before anything opens, every time.
	 *
	 * @return void
	 */
	public function testAClosedCaseNeedingAResultIsRefusedBeforeAnythingOpens(): void {
		foreach ([1, 2] as $attempt) {
			$refused = $this->refusal(final: true, results: true);

			self::assertSame(expected: 'result-type-required', actual: $refused->getRule(), message: 'attempt ' . $attempt);
			self::assertStringContainsString(needle: 'cannot be split', haystack: $refused->getSentence());
			$this->assertNothingLeftBehind();
		}
	}//end testAClosedCaseNeedingAResultIsRefusedBeforeAnythingOpens()

	/**
	 * A second case that cannot be removed is titled as a failed split, and the handler is told.
	 *
	 * @return void
	 */
	public function testASecondCaseThatCannotBeRemovedIsMarked(): void {
		$this->register->refusedSchemas = ['statusRecord'];
		$this->register->mayDeleteCases = false;

		$refused = $this->refusal();

		self::assertStringContainsString(needle: 'titled as a failed split', haystack: $refused->getSentence());
		$left = $this->secondCases();
		self::assertCount(expectedCount: 1, haystack: $left);
		self::assertSame(expected: 'Failed split: De tweede klacht', actual: $left[0]['title']);
		$this->assertTheOriginalIsUntouched();
	}//end testASecondCaseThatCannotBeRemovedIsMarked()

	/**
	 * A second case that can be neither removed nor marked is named in the refusal.
	 *
	 * @return void
	 */
	public function testASecondCaseThatCannotBeRemovedOrMarkedIsNamed(): void {
		$this->register->mayUpdateCases = false;
		$this->register->mayDeleteCases = false;

		$refused = $this->refusal();

		$left = $this->secondCases();
		self::assertCount(expectedCount: 1, haystack: $left);
		self::assertStringContainsString(needle: 'Remove it by hand', haystack: $refused->getSentence());
		self::assertStringContainsString(needle: (string)$left[0]['id'], haystack: $refused->getSentence());
		self::assertSame(expected: 'De tweede klacht', actual: $left[0]['title']);
		$this->assertTheOriginalIsUntouched();
	}//end testASecondCaseThatCannotBeRemovedOrMarkedIsNamed()

	/**
	 * Removing a failed second case leaves every other case and status record alone.
	 *
	 * @return void
	 */
	public function testCleanupTouchesOnlyTheNewCase(): void {
		$this->register->seed(
			schema: 'case',
			uuid: 'case-9',
			row: ['title' => 'Ander', 'caseType' => 'ct-1', 'status' => 'st-review'],
		);
		$this->register->seed(schema: 'statusRecord', uuid: 'sr-1', row: ['case' => 'case-1', 'statusType' => 'st-review']);
		$this->register->seed(schema: 'statusRecord', uuid: 'sr-9', row: ['case' => 'case-9', 'statusType' => 'st-review']);
		$this->register->seed(schema: 'statusRecord', uuid: 'sr-0', row: ['case' => '', 'statusType' => 'st-review']);

		$this->refusal(timeline: false);

		$ids = static fn (array $rows): array => array_map(static fn (array $row): string => (string)$row['id'], $rows);
		self::assertSame(expected: ['sr-1', 'sr-9', 'sr-0'], actual: $ids($this->register->all(schema: 'statusRecord')));
		self::assertSame(expected: ['case-1', 'case-9'], actual: $ids($this->register->all(schema: 'case')));
	}//end testCleanupTouchesOnlyTheNewCase()

	/**
	 * The check before a split names no result, so it can write none.
	 *
	 * @return void
	 */
	public function testTheCheckBeforeASplitNamesNoResult(): void {
		$this->split(final: true);

		self::assertNotSame(expected: [], actual: $this->resolvedWith);
		foreach ($this->resolvedWith as $resultTypeId) {
			self::assertNull(actual: $resultTypeId);
		}
	}//end testTheCheckBeforeASplitNamesNoResult()

	/**
	 * A status that cannot be read as final or not refuses the split before anything opens.
	 *
	 * @return void
	 */
	public function testAnUnreadableStatusRefusesBeforeAnythingOpens(): void {
		$this->finalUnreadable = true;

		$refused = $this->refusal();

		self::assertSame(expected: CaseSplitExecutor::UNWRITABLE, actual: $refused->getRule());
		self::assertStringContainsString(needle: 'could not check the status', haystack: $refused->getSentence());
		$this->assertNothingLeftBehind();
	}//end testAnUnreadableStatusRefusesBeforeAnythingOpens()

	/**
	 * The refusal of a split expected to fail.
	 *
	 * @param bool $final    Whether every status is final.
	 * @param bool $results  Whether the case type closes with a result.
	 * @param bool $timeline Whether the timeline entry succeeds.
	 *
	 * @return RefusedException The refusal.
	 */
	private function refusal(bool $final = false, bool $results = false, bool $timeline = true): RefusedException {
		try {
			$this->split(final: $final, results: $results, timeline: $timeline);
		} catch (RefusedException $refused) {
			return $refused;
		}

		self::fail(message: 'The split went on.');
	}//end refusal()

	/**
	 * The cases other than the original.
	 *
	 * @return array<int, array<string, mixed>> The cases.
	 */
	private function secondCases(): array {
		return array_values(
			array_filter(
				$this->register->all(schema: 'case'),
				static fn (array $row): bool => (string)($row['id'] ?? '') !== 'case-1',
			)
		);
	}//end secondCases()

	/**
	 * Only the original case exists, with no status records, and it is as it was.
	 *
	 * @return void
	 */
	private function assertNothingLeftBehind(): void {
		self::assertSame(expected: [], actual: $this->secondCases());
		self::assertSame(expected: [], actual: $this->register->all(schema: 'statusRecord'));
		$this->assertTheOriginalIsUntouched();
	}//end assertNothingLeftBehind()

	/**
	 * The original keeps its status and its document, and records no split.
	 *
	 * @return void
	 */
	private function assertTheOriginalIsUntouched(): void {
		$original = $this->register->row(schema: 'case', uuid: 'case-1');
		self::assertSame(expected: 'st-review', actual: $original['status']);
		self::assertArrayNotHasKey(key: 'splitInto', array: $original);
		self::assertSame(expected: 'case-1', actual: $this->register->row(schema: 'caseDocument', uuid: 'doc-1')['case']);
	}//end assertTheOriginalIsUntouched()

	/**
	 * Split case-1, moving doc-1, as jan.
	 *
	 * @param bool $final    Whether every status is final.
	 * @param bool $results  Whether the case type closes with a result.
	 * @param bool $timeline Whether the timeline entry of a move fails after its record is written.
	 *
	 * @return array<string, mixed> The executor's answer.
	 */
	private function split(bool $final = false, bool $results = false, bool $timeline = true): array {
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
		$writer = $this->createMock(originalClassName: CaseResultWriter::class);
		if ($this->finalUnreadable === true) {
			$writer->method('isFinalStatus')->willThrowException(new RuntimeException('status types unavailable'));
		} else {
			$writer->method('isFinalStatus')->willReturn($final);
		}

		if ($results === true) {
			$writer->method('resolveClosingResult')->willThrowException(
				new RefusedException(
					rule: 'result-type-required',
					sentence: 'Pick a result before closing this case.',
					status: RefusedException::STATUS_UNPROCESSABLE,
				)
			);
		} else {
			$writer->method('resolveClosingResult')->willReturnCallback(
				function (string $caseId, string $caseTypeId, ?string $resultTypeId): ?string {
					$this->resolvedWith[] = $resultTypeId;

					return null;
				}
			);
		}

		$entry = null;
		if ($timeline === false) {
			$entry = $this->createMock(originalClassName: StatusMoveEntry::class);
			$entry->method('record')->willThrowException(new RuntimeException('timeline unavailable'));
		}
		$ledger = new CaseMoveLedger(
			store: new CaseStatusStore(
				settingsService: $settings,
				statusTypeLookup: $this->createMock(originalClassName: StatusTypeLookup::class),
				logger: $logger,
				moveEntry: $entry,
			),
			resultWriter: $writer,
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
