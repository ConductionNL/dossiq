<?php

/**
 * Ending a case is four acts, and the difference is what is archived.
 *
 * The store double writes back into the case it hands out, because these acts
 * are only meaningful in sequence: finish then archive, abort then read the
 * record. A store that always answered the same case would let a broken
 * `endingOf()` pass every test in this file.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\CaseArchiveState;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseIncompleteness;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Lifecycle\LifecycleActorGate;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Finish, abort and archive.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseEndingActs
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Lifecycle\CaseIncompleteness
 * @uses \OCA\Dossiq\Service\Lifecycle\CaseJournal
 */
class CaseEndingActsTest extends TestCase {

	/**
	 * The case as the store currently holds it.
	 *
	 * @var array<string, mixed>
	 */
	private array $case;

	/**
	 * The store.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $store;

	/**
	 * The result writer.
	 *
	 * @var CaseResultWriter&MockObject
	 */
	private CaseResultWriter $results;

	/**
	 * The status rows of the case type.
	 *
	 * @var StatusTypeLookup&MockObject
	 */
	private StatusTypeLookup $statuses;

	/**
	 * The role gate.
	 *
	 * @var LifecycleActorGate&MockObject
	 */
	private LifecycleActorGate $gate;

	/**
	 * The service under test.
	 *
	 * @var CaseEndingActs
	 */
	private CaseEndingActs $acts;

	/**
	 * The platform's archive marker, doubled.
	 *
	 * @var (CaseArchiveState&MockObject)|null
	 */
	private ?CaseArchiveState $archive = null;

	/**
	 * Every marker write the acts asked the platform for.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $marked = [];

	/**
	 * Every marker the acts asked the platform to clear.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $cleared = [];

	/**
	 * A case in phase two of a five-phase process.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->archive = null;
		$this->marked = [];
		$this->cleared = [];
		$this->case = [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'status' => 'st-2',
		];

		$this->store = $this->createMock(originalClassName: CaseStatusStore::class);
		$this->store->method('loadCase')->willReturnCallback(fn (): array => $this->case);
		$this->store->method('saveCase')->willReturnCallback(
			function (array $case): array {
				$this->case = $case;
				return $case;
			}
		);

		$this->results = $this->createMock(originalClassName: CaseResultWriter::class);
		$this->results->method('resolveClosingResult')->willReturn('result-1');
		$this->results->method('archivalFuture')->willReturn(
			['archiveNomination' => 'vernietigen', 'archiveActionDate' => '2031-09-15']
		);

		$this->statuses = $this->createMock(originalClassName: StatusTypeLookup::class);
		$this->statuses->method('rowsOf')->willReturn(
			[
				['id' => 'st-1', 'name' => 'Ontvangen', 'order' => 1],
				['id' => 'st-2', 'name' => 'In behandeling', 'order' => 2],
				['id' => 'st-3', 'name' => 'Advies', 'order' => 3],
				['id' => 'st-4', 'name' => 'Besluit', 'order' => 4],
				['id' => 'st-5', 'name' => 'Afgerond', 'order' => 5, 'isFinal' => true],
			]
		);

		$this->gate = $this->createMock(originalClassName: LifecycleActorGate::class);
		$this->gate->method('may')->willReturn(true);

		$this->acts = new CaseEndingActs(
			store: $this->store,
			results: $this->results,
			statuses: $this->statuses,
			gate: $this->gate,
			incompleteness: $this->incompleteness(),
			journal: new CaseJournal(userSession: $this->session(uid: 'ahmed')),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			archiveState: $this->archiveState(),
		);
	}//end setUp()

	/**
	 * A session naming one user.
	 *
	 * @param string $uid The uid to answer with.
	 *
	 * @return IUserSession&MockObject The session.
	 */
	private function session(string $uid): IUserSession {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * The archive state double, recording every marker write.
	 *
	 * Built once per test and reused by every construction site, so an
	 * assertion about what the platform was asked to do reads the same
	 * recorder the act wrote to.
	 *
	 * @return CaseArchiveState&MockObject The double.
	 */
	private function archiveState(): CaseArchiveState {
		if ($this->archive !== null) {
			return $this->archive;
		}

		$this->archive = $this->createMock(originalClassName: CaseArchiveState::class);
		$this->archive->method('mark')->willReturnCallback(
			function (string $caseId, string $reason): array {
				$this->marked[] = ['caseId' => $caseId, 'reason' => $reason];
				return ['uuid' => $caseId, 'archived' => ['by' => 'ahmed', 'at' => '2026-09-16T10:00:00+00:00', 'reason' => $reason]];
			}
		);
		$this->archive->method('clear')->willReturnCallback(
			function (string $caseId, string $reason): array {
				$this->cleared[] = ['caseId' => $caseId, 'reason' => $reason];
				return ['uuid' => $caseId, 'archived' => null];
			}
		);
		$this->archive->method('isArchived')->willReturnCallback(
			fn (): bool => ($this->marked !== [] && count($this->cleared) < count($this->marked))
		);

		return $this->archive;
	}//end archiveState()

	/**
	 * A real incompleteness service over the same store.
	 *
	 * Real and not a double, because the acts and the incompleteness record
	 * read the SAME case: a double would let a finish pass on a case the
	 * store says is missing a field, which is the disagreement the guard
	 * exists to prevent.
	 *
	 * @return CaseIncompleteness The service.
	 */
	private function incompleteness(): CaseIncompleteness {
		return new CaseIncompleteness(
			store: $this->store,
			journal: new CaseJournal(userSession: $this->session(uid: 'ahmed')),
		);
	}//end incompleteness()

	/**
	 * The journal as the case now holds it.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function journal(): array {
		return (array)json_decode((string)($this->case['activity'] ?? '[]'), true);
	}//end journal()

	/**
	 * An intrekking is recorded as an abort and is not a besluit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnAbortIsNotABesluit(): void {
		$this->acts->abort(caseId: 'case-1', reason: 'Ingetrokken door aanvrager', resultTypeId: 'rt-withdrawn');

		$this->assertSame(expected: 'abort', actual: $this->case['endingAct']);
		$entries = $this->journal();
		$entry = (array)end($entries);
		$this->assertSame(expected: 'abort', actual: $entry['type']);
		$this->assertFalse(condition: $entry['besluit'], message: 'an abort must never record a besluit');
		$this->assertArrayNotHasKey(
			key: 'besluitDocument',
			array: $this->case,
			message: 'aborting must not write a decision document onto the case');
	}//end testAnAbortIsNotABesluit()

	/**
	 * Finishing takes the nomination from the result type; aborting does not.
	 *
	 * The asymmetry is the requirement rather than an oversight: an abort has
	 * a result and no besluit, and its retention is committed by the archive
	 * act, where an archivist is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testFinishingWritesTheArchivalNominationAndAbortingDoesNot(): void {
		$this->acts->finish(caseId: 'case-1', reason: 'Vergunning verleend', resultTypeId: 'rt-granted');
		$this->assertSame(expected: 'vernietigen', actual: $this->case['archiveNomination']);

		$this->case = ['id' => 'case-2', 'caseType' => 'ct-1', 'status' => 'st-2'];
		$this->acts->abort(caseId: 'case-2', reason: 'Ingetrokken', resultTypeId: 'rt-withdrawn');
		$this->assertArrayNotHasKey(key: 'archiveNomination', array: $this->case);
	}//end testFinishingWritesTheArchivalNominationAndAbortingDoesNot()

	/**
	 * Closing in phase two records the phases that were never reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnEarlyCloseRecordsTheSkippedPhases(): void {
		$answer = $this->acts->abort(
			caseId: 'case-1',
			reason: 'Niet-ontvankelijk',
			resultTypeId: 'rt-inadmissible',
		);

		$this->assertSame(expected: ['Advies', 'Besluit'], actual: $answer['skippedPhases']);
		$this->assertSame(
			expected: ['Advies', 'Besluit'],
			actual: json_decode((string)$this->case['skippedPhases'], true),
			message: 'the skipped phases belong on the case, not only in the answer');
	}//end testAnEarlyCloseRecordsTheSkippedPhases()

	/**
	 * A close from the last phase skips nothing and records nothing.
	 *
	 * The control for the test above: without it, a `skippedPhases` that
	 * listed every phase every time would pass, because the assertion there
	 * only says the two it expects are present.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnOrdinaryCloseRecordsNoSkippedPhases(): void {
		$this->case['status'] = 'st-4';

		$answer = $this->acts->finish(caseId: 'case-1', reason: 'Klaar', resultTypeId: 'rt-granted');

		$this->assertSame(expected: [], actual: $answer['skippedPhases']);
		$this->assertArrayNotHasKey(key: 'skippedPhases', array: $this->case);
	}//end testAnOrdinaryCloseRecordsNoSkippedPhases()

	/**
	 * The result guard still runs on an early close.
	 *
	 * What an early close skips is the phases, never the checks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testTheResultGuardStillRunsOnAnEarlyClose(): void {
		$results = $this->createMock(originalClassName: CaseResultWriter::class);
		$results->method('resolveClosingResult')->willThrowException(
			new RefusedException(
				rule: 'result-type-required',
				sentence: 'Pick a result before closing this case.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			)
		);

		$acts = new CaseEndingActs(
			store: $this->store,
			results: $results,
			statuses: $this->statuses,
			gate: $this->gate,
			incompleteness: $this->incompleteness(),
			journal: new CaseJournal(userSession: $this->session(uid: 'ahmed')),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			archiveState: $this->archiveState(),
		);

		$this->store->expects($this->never())->method('writeStatusRecord');

		$this->expectException(exception: RefusedException::class);
		$this->expectExceptionMessage(message: 'result_type_required');

		$acts->finish(caseId: 'case-1', reason: 'Toch afronden', resultTypeId: '');
	}//end testTheResultGuardStillRunsOnAnEarlyClose()

	/**
	 * An act the handler's role forbids is refused, and the refusal names the role.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnActTheRoleForbidsNamesTheRole(): void {
		$gate = $this->createMock(originalClassName: LifecycleActorGate::class);
		$gate->method('may')->willReturn(false);
		$gate->method('roleFor')->willReturn('archivaris');
		$gate->method('refusalSentence')->willReturn('This act needs the archivaris group.');
		$gate->method('require')->willThrowException(
			new RefusedException(
				rule: 'archive-role-required',
				sentence: 'This act needs the archivaris group.',
				status: RefusedException::STATUS_FORBIDDEN,
			)
		);

		$acts = new CaseEndingActs(
			store: $this->store,
			results: $this->results,
			statuses: $this->statuses,
			gate: $gate,
			incompleteness: $this->incompleteness(),
			journal: new CaseJournal(userSession: $this->session(uid: 'ahmed')),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			archiveState: $this->archiveState(),
		);

		try {
			$acts->archive(caseId: 'case-1', reason: 'Naar het e-depot');
			$this->fail(message: 'an act the role forbids must be refused');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'archive-role-required', actual: $e->getRule());
			$this->assertStringContainsString(needle: 'archivaris', haystack: $e->getSentence());
			$this->assertSame(expected: RefusedException::STATUS_FORBIDDEN, actual: $e->getStatus());
		}
	}//end testAnActTheRoleForbidsNamesTheRole()

	/**
	 * An open case cannot be archived.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnOpenCaseCannotBeArchived(): void {
		$this->expectException(exception: RefusedException::class);
		$this->expectExceptionMessage(message: 'case_not_ended');

		$this->acts->archive(caseId: 'case-1', reason: 'Naar het e-depot');
	}//end testAnOpenCaseCannotBeArchived()

	/**
	 * Archiving a finished case writes the retention rule from the result type.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testArchivingWritesTheRetentionRule(): void {
		$this->acts->finish(caseId: 'case-1', reason: 'Verleend', resultTypeId: 'rt-granted');

		$answer = $this->acts->archive(caseId: 'case-1', reason: 'Naar het e-depot');

		$this->assertSame(expected: 'vernietigen', actual: $answer['archiveNomination']);
		$this->assertSame(expected: '2031-09-15', actual: $answer['archiveActionDate']);
		$this->assertSame(expected: 'archived', actual: $this->case['archiveStatus']);
	}//end testArchivingWritesTheRetentionRule()

	/**
	 * Archiving writes the platform's marker, which is what the lists read.
	 *
	 * `archiveStatus` is the ZGW fact beside it. A test that asserted only the
	 * field would pass on a case that never left a single lens, which is the
	 * whole of what this change is for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testArchivingMarksTheCaseOnThePlatform(): void {
		$this->acts->finish(caseId: 'case-1', reason: 'Verleend', resultTypeId: 'rt-granted');

		$answer = $this->acts->archive(caseId: 'case-1', reason: 'Naar het e-depot');

		$this->assertCount(expectedCount: 1, haystack: $this->marked);
		$this->assertSame(expected: 'case-1', actual: $this->marked[0]['caseId']);
		$this->assertSame(expected: 'Naar het e-depot', actual: $this->marked[0]['reason']);
		$this->assertSame(expected: 'ahmed', actual: $answer['archived']['by']);
	}//end testArchivingMarksTheCaseOnThePlatform()

	/**
	 * Restoring clears the marker and moves the ZGW field back, in that order.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testRestoringClearsTheMarkerAndTheZgwField(): void {
		$this->acts->finish(caseId: 'case-1', reason: 'Verleend', resultTypeId: 'rt-granted');
		$this->acts->archive(caseId: 'case-1', reason: 'Naar het e-depot');

		$answer = $this->acts->unarchive(caseId: 'case-1', reason: 'Te vroeg gearchiveerd');

		$this->assertCount(expectedCount: 1, haystack: $this->cleared);
		$this->assertSame(expected: 'Te vroeg gearchiveerd', actual: $this->cleared[0]['reason']);
		$this->assertSame(expected: 'nog_te_archiveren', actual: $answer['archiveStatus']);
		$this->assertSame(expected: 'nog_te_archiveren', actual: $this->case['archiveStatus']);
		$this->assertNull(actual: $answer['archived']);
	}//end testRestoringClearsTheMarkerAndTheZgwField()

	/**
	 * Restoring keeps the appraisal the archiving act derived.
	 *
	 * The nomination and the action date came from the result type and they
	 * are still true. Clearing them would make a restore read as an appraisal
	 * somebody had undone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testRestoringKeepsTheNomination(): void {
		$this->acts->finish(caseId: 'case-1', reason: 'Verleend', resultTypeId: 'rt-granted');
		$this->acts->archive(caseId: 'case-1', reason: 'Naar het e-depot');

		$this->acts->unarchive(caseId: 'case-1', reason: 'Te vroeg gearchiveerd');

		$this->assertSame(expected: 'vernietigen', actual: $this->case['archiveNomination']);
		$this->assertSame(expected: '2031-09-15', actual: $this->case['archiveActionDate']);
	}//end testRestoringKeepsTheNomination()

	/**
	 * A case that is not archived refuses the restore rather than no-opping.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testRestoringACaseThatIsNotArchivedIsRefused(): void {
		$this->expectException(exception: RefusedException::class);
		$this->expectExceptionMessage(message: 'case_not_archived');

		$this->acts->unarchive(caseId: 'case-1', reason: 'Terughalen');
	}//end testRestoringACaseThatIsNotArchivedIsRefused()

	/**
	 * A restore with no reason is refused, exactly as the archiving act is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testRestoringWithoutAReasonIsRefused(): void {
		$this->acts->finish(caseId: 'case-1', reason: 'Verleend', resultTypeId: 'rt-granted');
		$this->acts->archive(caseId: 'case-1', reason: 'Naar het e-depot');

		$this->expectException(exception: RefusedException::class);

		$this->acts->unarchive(caseId: 'case-1', reason: '   ');
	}//end testRestoringWithoutAReasonIsRefused()

	/**
	 * A result type that states no period archives with the date unknown.
	 *
	 * An archivist can see which cases carry no date, which is a better answer
	 * than refusing the act or inventing a destruction date.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnderivableRetentionDateIsSaidOutLoud(): void {
		$results = $this->createMock(originalClassName: CaseResultWriter::class);
		$results->method('resolveClosingResult')->willReturn('result-1');
		$results->method('archivalFuture')->willReturn(['archiveNomination' => 'blijvend_bewaren']);

		$acts = new CaseEndingActs(
			store: $this->store,
			results: $results,
			statuses: $this->statuses,
			gate: $this->gate,
			incompleteness: $this->incompleteness(),
			journal: new CaseJournal(userSession: $this->session(uid: 'ahmed')),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			archiveState: $this->archiveState(),
		);

		$acts->finish(caseId: 'case-1', reason: 'Verleend', resultTypeId: 'rt-granted');
		$acts->archive(caseId: 'case-1', reason: 'Naar het e-depot');

		$this->assertSame(expected: 'archived_retention_period_unknown', actual: $this->case['archiveStatus']);
	}//end testAnUnderivableRetentionDateIsSaidOutLoud()

	/**
	 * The ending a reopen has to keep names the act, the actor and the moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testTheEndingNamesWhoEndedItAndWhen(): void {
		$this->acts->finish(caseId: 'case-1', reason: 'Verleend', resultTypeId: 'rt-granted');

		$ending = $this->acts->endingOf(case: $this->case);

		$this->assertSame(expected: 'finish', actual: $ending['act']);
		$this->assertSame(expected: 'ahmed', actual: $ending['by']);
		$this->assertSame(expected: 'Verleend', actual: $ending['reason']);
		$this->assertNotSame(expected: '', actual: $ending['at'], message: 'an ending with no moment cannot answer when');
	}//end testTheEndingNamesWhoEndedItAndWhen()

	/**
	 * Finishing a case missing required data is refused, naming the field.
	 *
	 * REQ-LIFE-13's third clause, on the act this lane owns. Finishing says
	 * the case reached its result, and a case that never got its data did
	 * not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testFinishingAnIncompleteCaseIsRefusedByName(): void {
		$this->case['isIncomplete'] = true;
		$this->case['missingFields'] = json_encode(['applicantAddress']);

		try {
			$this->acts->finish(caseId: 'case-1', reason: 'Toch afronden', resultTypeId: 'rt-granted');
			$this->fail(message: 'finishing a case missing required data must be refused');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'incomplete-case', actual: $e->getRule());
			$this->assertStringContainsString(needle: 'applicantAddress', haystack: $e->getSentence());
		}
	}//end testFinishingAnIncompleteCaseIsRefusedByName()

	/**
	 * Aborting an incomplete case is NOT refused.
	 *
	 * The control, and a deliberate asymmetry: an intrekking is exactly what
	 * happens to a case whose data never arrived, and refusing it would
	 * strand the case that most needs ending.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAbortingAnIncompleteCaseStillWorks(): void {
		$this->case['isIncomplete'] = true;
		$this->case['missingFields'] = json_encode(['applicantAddress']);

		$answer = $this->acts->abort(caseId: 'case-1', reason: 'Nooit aangevuld', resultTypeId: 'rt-withdrawn');

		$this->assertSame(expected: 'abort', actual: $answer['act']);
	}//end testAbortingAnIncompleteCaseStillWorks()

	/**
	 * Every ending act refuses an empty reason before it touches anything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnEndingWithoutAReasonIsRefused(): void {
		$this->store->expects($this->never())->method('saveCase');

		$this->expectException(exception: RefusedException::class);
		$this->expectExceptionMessage(message: 'reason_required');

		$this->acts->finish(caseId: 'case-1', reason: '   ', resultTypeId: 'rt-granted');
	}//end testAnEndingWithoutAReasonIsRefused()
}//end class
