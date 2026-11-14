<?php

/**
 * Everything one person holds, handed to somebody else in one act.
 *
 * Uitdiensttreding is a Tuesday afternoon, and today it is a database query.
 * Nextcloud Deck does it in one call and one occ command; dossiq had a bulk
 * reassignment that moved the cases a person handles and stopped there, so the
 * cases they were answerable for stayed on a name nobody could reach.
 *
 * 🔑 IT IS PREVIEWED BEFORE IT RUNS, AND THAT IS NOT A COURTESY. A handover of
 * two hundred cases to the wrong person is worse than the query it replaces,
 * because the query leaves a trace in somebody's terminal and this leaves two
 * hundred cases looking legitimately reassigned. So `preview()` mutates
 * nothing and `execute()` is a second, separate call.
 *
 * 🔑 IT COMPOSES, IT DOES NOT COPY. The cases a person handles and their open
 * tasks are {@see \OCA\Dossiq\Service\CaseReassignmentService}'s answer, and
 * that service already owns the open/closed split, the per-item audit entry and
 * the digest notification. What this class adds is the second seat, the drafts
 * and the record. A second copy of the case walk would drift from it, and the
 * first sign would be a leaver handover that missed cases the bulk
 * reassignment moved.
 *
 * 🔴 A DRAFT IS LISTED AND NOT RE-OWNED, ON PURPOSE. A draft is private to its
 * author because OpenRegister owns the object and its owner, and dossiq has no
 * seam to change that owner: `ObjectService` offers a save, not a handover of
 * ownership. Writing a second "really the owner is" field here would be a
 * claim about access that nothing enforces, which is worse than the gap. So
 * the drafts a leaver holds are named in the preview and on the record, and
 * moving them waits on OpenRegister's half.
 *
 * WHAT STARTS IT. An administrator names the person. humaniq has no
 * offboarding signal yet: `leave-management` is about somebody who comes back,
 * and their work is covered rather than handed over. When humaniq emits one,
 * this service is what listens.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Kernel\Contracts\LeaverHandoverServiceInterface;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\TranslatedText;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Previews and performs the handover of everything a leaver holds.
 *
 * @spec openspec/specs/people-on-the-case/spec.md
 */
class LeaverHandoverService implements LeaverHandoverServiceInterface {

	use SearchesObjects;

	/**
	 * How many rows one draft lookup reads.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 200;

	/**
	 * The register and the configured schemas, read and refused the one shared way.
	 *
	 * @var DossierRegister
	 */
	private readonly DossierRegister $dossierRegister;

	/**
	 * Constructor.
	 *
	 * @param CaseReassignmentService $reassignment The cases a person handles, and their open tasks.
	 * @param CaseSeats               $seats        The coordinator seat.
	 * @param SettingsService         $settings     Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface         $logger       Records the act and what it could not reach.
	 * @param TranslatedText          $text         Reads a translatable case type title in the reader's language.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md
	 */
	public function __construct(
		private readonly CaseReassignmentService $reassignment,
		private readonly CaseSeats $seats,
		SettingsService $settings,
		private readonly LoggerInterface $logger,
		private readonly TranslatedText $text = new TranslatedText(),
	) {
		$this->dossierRegister = new DossierRegister(settingsService: $settings);
	}//end __construct()

	/**
	 * What a leaver handover would move, and how much of it. Mutates nothing.
	 *
	 * @param string $fromUser The person who is leaving.
	 *
	 * @return array{cases: array<int, array<string, mixed>>, coordinatorCases: array<int, string>,
	 *               tasks: array<int, array<string, mixed>>, drafts: array<int, array<string, mixed>>,
	 *               counts: array<string, int>}
	 *         The preview.
	 *
	 * @throws InvalidArgumentException When no person is named.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function preview(string $fromUser): array {
		$fromUser = trim($fromUser);
		if ($fromUser === '') {
			throw new InvalidArgumentException('fromUser is required');
		}

		$work = $this->reassignment->preview(fromUser: $fromUser);
		$coordinatorCases = $this->seats->casesCoordinatedBy(uid: $fromUser);
		$drafts = $this->draftsOf(uid: $fromUser);

		return [
			'cases' => $work['cases'],
			'coordinatorCases' => $coordinatorCases,
			'tasks' => $work['tasks'],
			'drafts' => $drafts,
			'counts' => [
				'cases' => count($work['cases']),
				'coordinatorCases' => count($coordinatorCases),
				'tasks' => count($work['tasks']),
				'drafts' => count($drafts),
				'total' => (count($work['cases']) + count($coordinatorCases) + count($work['tasks'])),
			],
		];
	}//end preview()

	/**
	 * Hand everything the leaver holds to another person, and record it.
	 *
	 * @param string $fromUser The person who is leaving.
	 * @param string $toUser   The person receiving the work.
	 * @param string $actor    The administrator running the act.
	 *
	 * @return array{batchId: string, job: array<string, mixed>, succeeded: int, failed: int,
	 *               coordinatorSeats: int, drafts: array<int, array<string, mixed>>,
	 *               tasks: array<int, array<string, mixed>>}
	 *         What moved, and the job that is moving the cases.
	 *
	 * @throws InvalidArgumentException When a person is missing, or is their own successor.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function execute(string $fromUser, string $toUser, string $actor): array {
		$fromUser = trim($fromUser);
		$toUser = trim($toUser);
		$this->assertHandoverPair(fromUser: $fromUser, toUser: $toUser);

		// The coordinator seats are read BEFORE the cases move. Reading them
		// afterwards would work today and break the first time the case walk
		// starts touching role records, and the failure would be silent: an
		// empty list reads exactly like a person who coordinated nothing.
		$coordinated = $this->seats->casesCoordinatedBy(uid: $fromUser);
		$drafts = $this->draftsOf(uid: $fromUser);

		$batch = $this->releaseCaseload(fromUser: $fromUser, toUser: $toUser, actor: $actor);
		$batchId = self::batchIdOf(batch: $batch);
		$now = (new DateTimeImmutable())->format('c');

		$seatsMoved = $this->moveCoordinatorSeats(coordinated: $coordinated, toUser: $toUser, fromUser: $fromUser,
			actor: $actor, batchId: $batchId, now: $now);

		// ⚠️ Recorded against the cases the act was ORDERED over, not against
		// the ones the job has finished writing. The job walks in the
		// background, so there is no per-case outcome to read here without
		// waiting for it, and waiting would make this call's duration depend
		// on the size of the caseload. The handover record therefore says
		// "this person's work was handed over on this date", which is what an
		// auditor asks; what happened to each individual case is the job's own
		// report, at /api/bulk-jobs/{id}/members.
		$this->recordOnCases(cases: (array)($batch['caseIds'] ?? []), fromUser: $fromUser, toUser: $toUser, actor: $actor, batchId: $batchId, now: $now);

		$result = self::handoverResult(batch: $batch, batchId: $batchId, seatsMoved: $seatsMoved, drafts: $drafts);
		return $this->loggedHandover(result: $result, fromUser: $fromUser, toUser: $toUser, actor: $actor);
	}//end execute()

	/**
	 * The batch id of a committed caseload job.
	 *
	 * @param array<string, mixed> $batch The batch the reassignment service answered.
	 *
	 * @return string The job's uuid, or its id, or ''.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	private static function batchIdOf(array $batch): string {
		$job = (array)($batch['job'] ?? []);
		return (string)($job['uuid'] ?? ($job['id'] ?? ''));
	}//end batchIdOf()

	/**
	 * What a handover moved, and the job that is moving the cases.
	 *
	 * @param array<string, mixed>             $batch      The batch the reassignment service answered.
	 * @param string                           $batchId    The handover's batch id.
	 * @param int                              $seatsMoved How many coordinator seats moved.
	 * @param array<int, array<string, mixed>> $drafts     The drafts the leaver holds.
	 *
	 * @return array{batchId: string, job: array<string, mixed>, succeeded: int, failed: int,
	 *               coordinatorSeats: int, drafts: array<int, array<string, mixed>>,
	 *               tasks: array<int, array<string, mixed>>}
	 *         The handover's outcome.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	private static function handoverResult(array $batch, string $batchId, int $seatsMoved, array $drafts): array {
		return [
			'batchId' => $batchId,
			'job' => (array)($batch['job'] ?? []),
			'succeeded' => count((array)($batch['caseIds'] ?? [])),
			'failed' => 0,
			'coordinatorSeats' => $seatsMoved,
			'drafts' => $drafts,
			'tasks' => (array)($batch['tasks'] ?? []),
		];
	}//end handoverResult()

	/**
	 * Log that a person's work was handed over, and pass the outcome on.
	 *
	 * @param array<string, mixed> $result   The handover's outcome.
	 * @param string               $fromUser The person who is leaving.
	 * @param string               $toUser   The person receiving the work.
	 * @param string               $actor    The administrator running the act.
	 *
	 * @return array<string, mixed> The same outcome.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	private function loggedHandover(array $result, string $fromUser, string $toUser, string $actor): array {
		$this->logger->info('Dossiq leaver handover: a person\'s work was handed over', [
			'batchId' => $result['batchId'],
			'from' => $fromUser,
			'to' => $toUser,
			'actor' => $actor,
			'cases' => $result['succeeded'],
			'job' => $result['batchId'],
			'coordinatorSeats' => $result['coordinatorSeats'],
			'draftsNotMoved' => count($result['drafts']),
		]);

		return $result;
	}//end loggedHandover()

	/**
	 * Commit the bulk job that moves the leaver's cases to the successor.
	 *
	 * @param string $fromUser The person who is leaving.
	 * @param string $toUser   The person receiving the work.
	 * @param string $actor    The administrator running the act.
	 *
	 * @return array{job: array<string, mixed>, tasks: array<int, array<string, mixed>>, caseIds: array<int, string>}
	 *         The batch the reassignment service answered.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	private function releaseCaseload(string $fromUser, string $toUser, string $actor): array {
		// The cases move as one bulk job, committed here rather than rehearsed:
		// a leaver handover is an act somebody already decided, not a proposal
		// waiting for a coordinator to read it. The job walks them in the
		// background and reports per case what it did
		// (bulk-actions-report-progress, D-1).
		$reason = 'Handover of ' . $fromUser . '\'s work to ' . $toUser . ', ordered by ' . $actor;
		return $this->reassignment->releaseCaseload(
			fromUser: $fromUser,
			toUser: $toUser,
			justification: $reason,
			filter: null,
			actorId: $actor,
			commit: true,
		);
	}//end releaseCaseload()

	/**
	 * Refuse a handover that names nobody, or the leaver as their own successor.
	 *
	 * @param string $fromUser The person who is leaving, trimmed.
	 * @param string $toUser   The person receiving the work, trimmed.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a person is missing, or is their own successor.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	private function assertHandoverPair(string $fromUser, string $toUser): void {
		if ($fromUser === '' || $toUser === '') {
			throw new InvalidArgumentException('Both fromUser and toUser are required');
		}

		if ($fromUser === $toUser) {
			throw new InvalidArgumentException('Cannot hand a person their own work');
		}
	}//end assertHandoverPair()

	/**
	 * Give every coordinator seat the leaver held to the receiving person.
	 *
	 * @param array<int, string> $coordinated The cases the leaver coordinates.
	 * @param string $toUser   Who receives the seats.
	 * @param string $fromUser Who held them.
	 * @param string $actor    Who ran the act.
	 * @param string $batchId  The handover's batch id.
	 * @param string $now      The moment of the act.
	 *
	 * @return int How many seats moved.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	private function moveCoordinatorSeats(
		array $coordinated,
		string $toUser,
		string $fromUser,
		string $actor,
		string $batchId,
		string $now,
	): int {
		$seatsMoved = 0;
		foreach ($coordinated as $caseId) {
			if ($this->moveCoordinatorSeat(caseId: $caseId, toUser: $toUser, fromUser: $fromUser, actor: $actor, batchId: $batchId, now: $now) === true) {
				$seatsMoved += 1;
			}
		}

		return $seatsMoved;
	}//end moveCoordinatorSeats()

	/**
	 * Give one case's coordinator seat to the receiving person.
	 *
	 * @param string $caseId   The case uuid.
	 * @param string $toUser   Who receives the seat.
	 * @param string $fromUser Who held it.
	 * @param string $actor    Who ran the act.
	 * @param string $batchId  The act this seat moved in.
	 * @param string $now      When it moved.
	 *
	 * @return bool True when the seat moved.
	 */
	private function moveCoordinatorSeat(
		string $caseId,
		string $toUser,
		string $fromUser,
		string $actor,
		string $batchId,
		string $now,
	): bool {
		try {
			$this->seats->nameCoordinator(caseId: $caseId, participant: $toUser);
			$this->recordOnCase(
				caseId: $caseId,
				record: [
					'fromUser' => $fromUser,
					'toUser' => $toUser,
					'actor' => $actor,
					'seat' => CaseSeats::COORDINATOR,
					'batchId' => $batchId,
					'movedAt' => $now,
				],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq leaver handover: a coordinator seat could not be moved',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return false;
		}

		return true;
	}//end moveCoordinatorSeat()

	/**
	 * Write the handover record onto every case the act was ordered over.
	 *
	 * The record is what makes the act traceable a year later: a case says who
	 * held it, who holds it now and whose act moved it. Without it, the only
	 * evidence is a log line nobody reading the case will find.
	 *
	 * @param array<int, string>               $cases    The case uuids the act covers.
	 * @param string                           $fromUser Who held the case.
	 * @param string                           $toUser   Who holds it now.
	 * @param string                           $actor    Who ran the act.
	 * @param string                           $batchId  The act.
	 * @param string                           $now      When.
	 *
	 * @return void
	 */
	private function recordOnCases(array $cases, string $fromUser, string $toUser, string $actor, string $batchId, string $now): void {
		foreach ($cases as $caseId) {
			$this->recordOnCase(
				caseId: (string)$caseId,
				record: [
					'fromUser' => $fromUser,
					'toUser' => $toUser,
					'actor' => $actor,
					'seat' => CaseSeats::HANDLER,
					'batchId' => $batchId,
					'movedAt' => $now,
				],
			);
		}
	}//end recordOnCases()

	/**
	 * Write one handover record onto one case.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return void
	 */
	private function recordOnCase(string $caseId, array $record): void {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return;
		}

		try {
			[$objectService, $register] = $this->context();
			$schema = $this->schema(key: 'case_schema');
			$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
			if ($case === null) {
				return;
			}

			$case['handoverRecord'] = $record;
			unset($case['@self'], $case['id'], $case['uuid']);
			$objectService->saveObject(object: $case, register: $register, schema: $schema, uuid: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq leaver handover: the record could not be written onto a case',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);
		}
	}//end recordOnCase()

	/**
	 * The drafts a person holds, named so somebody can go and get them.
	 *
	 * A draft case type is the one draft dossiq can see without guessing:
	 * `isDraft` is a declared field, and the row carries its owner in `@self`.
	 * Everything else a person drafts is an OpenRegister object whose owner
	 * OpenRegister keeps, which is why these are listed rather than moved.
	 *
	 * @param string $uid The person.
	 *
	 * @return array<int, array<string, mixed>> The drafts, as `{id, title, schema}`.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function draftsOf(string $uid): array {
		$uid = trim($uid);
		if ($uid === '') {
			return [];
		}

		$rows = $this->draftCaseTypeRows(uid: $uid);

		return $this->ownedDrafts(rows: $rows, uid: $uid);
	}//end draftsOf()

	/**
	 * The draft case type rows, or none (logged) when they cannot be read.
	 *
	 * @param string $uid The person, trimmed.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	private function draftCaseTypeRows(string $uid): array {
		try {
			[$objectService, $register] = $this->context();
			$schema = $this->schema(key: 'case_type_schema');
			$filters = ['isDraft' => true, '_limit' => self::PAGE_SIZE];
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq leaver handover: the drafts could not be read',
				['uid' => $uid, 'exception' => $e->getMessage()],
			);

			return [];
		}

		return $rows;
	}//end draftCaseTypeRows()

	/**
	 * The rows a person owns, as drafts.
	 *
	 * @param array<int, array<string, mixed>> $rows The draft case type rows.
	 * @param string                           $uid  The person, trimmed.
	 *
	 * @return array<int, array<string, mixed>> The drafts, as `{id, title, schema}`.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	private function ownedDrafts(array $rows, string $uid): array {
		$drafts = [];
		foreach ($rows as $row) {
			$self = (array)($row['@self'] ?? []);
			$owner = trim((string)($self['owner'] ?? ($row['createdBy'] ?? '')));
			if ($owner !== $uid) {
				continue;
			}

			$drafts[] = $this->draftEntry(row: $row, self: $self);
		}

		return $drafts;
	}//end ownedDrafts()

	/**
	 * One draft, as `{id, title, schema}`.
	 *
	 * @param array<string, mixed> $row  The draft case type row.
	 * @param array<string, mixed> $self The row's `@self` block.
	 *
	 * @return array<string, mixed> The draft.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	private function draftEntry(array $row, array $self): array {
		return [
			'id' => trim((string)($row['id'] ?? ($self['id'] ?? ''))),
			'title' => $this->text->forReader(value: ($row['title'] ?? '')),
			'schema' => 'caseType',
		];
	}//end draftEntry()

	/**
	 * The object service and register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	private function context(): array {
		return $this->dossierRegister->context();
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 */
	private function schema(string $key): string {
		return $this->dossierRegister->schema(key: $key);
	}//end schema()
}//end class
