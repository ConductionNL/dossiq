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
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Previews and performs the handover of everything a leaver holds.
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */
class LeaverHandoverService {

	use SearchesObjects;

	/**
	 * How many rows one draft lookup reads.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param CaseReassignmentService $reassignment The cases a person handles, and their open tasks.
	 * @param CaseSeats               $seats        The coordinator seat.
	 * @param SettingsService         $settings     Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface         $logger       Records the act and what it could not reach.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
	 */
	public function __construct(
		private readonly CaseReassignmentService $reassignment,
		private readonly CaseSeats $seats,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
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
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
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
	 * @return array{batchId: string, succeeded: int, failed: int, coordinatorSeats: int,
	 *               drafts: array<int, array<string, mixed>>, results: array<int, array<string, mixed>>}
	 *         What moved.
	 *
	 * @throws InvalidArgumentException When a person is missing, or is their own successor.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function execute(string $fromUser, string $toUser, string $actor): array {
		$fromUser = trim($fromUser);
		$toUser = trim($toUser);
		if ($fromUser === '' || $toUser === '') {
			throw new InvalidArgumentException('Both fromUser and toUser are required');
		}

		if ($fromUser === $toUser) {
			throw new InvalidArgumentException('Cannot hand a person their own work');
		}

		// The coordinator seats are read BEFORE the cases move. Reading them
		// afterwards would work today and break the first time the case walk
		// starts touching role records, and the failure would be silent: an
		// empty list reads exactly like a person who coordinated nothing.
		$coordinated = $this->seats->casesCoordinatedBy(uid: $fromUser);
		$drafts = $this->draftsOf(uid: $fromUser);

		$batch = $this->reassignment->execute(fromUser: $fromUser, toUser: $toUser, filter: null, actorId: $actor);
		$batchId = (string)($batch['batchId'] ?? '');
		$now = (new DateTimeImmutable())->format('c');

		$seatsMoved = 0;
		foreach ($coordinated as $caseId) {
			if ($this->moveCoordinatorSeat(caseId: $caseId, toUser: $toUser, fromUser: $fromUser, actor: $actor, batchId: $batchId, now: $now) === true) {
				$seatsMoved += 1;
			}
		}

		$this->recordOnCases(
			cases: (array)($batch['results'] ?? []),
			fromUser: $fromUser,
			toUser: $toUser,
			actor: $actor,
			batchId: $batchId,
			now: $now,
		);

		$this->logger->info(
			'Dossiq leaver handover: a person\'s work was handed over',
			[
				'batchId' => $batchId,
				'from' => $fromUser,
				'to' => $toUser,
				'actor' => $actor,
				'cases' => (int)($batch['succeeded'] ?? 0),
				'coordinatorSeats' => $seatsMoved,
				'draftsNotMoved' => count($drafts),
			],
		);

		return [
			'batchId' => $batchId,
			'succeeded' => (int)($batch['succeeded'] ?? 0),
			'failed' => (int)($batch['failed'] ?? 0),
			'coordinatorSeats' => $seatsMoved,
			'drafts' => $drafts,
			'results' => (array)($batch['results'] ?? []),
		];
	}//end execute()

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
	 * Write the handover record onto every case the batch moved.
	 *
	 * The record is what makes the act traceable a year later: a case that
	 * moved says who held it, who holds it now and whose act moved it. Without
	 * it, the only evidence is a log line nobody reading the case will find.
	 *
	 * @param array<int, array<string, mixed>> $cases    The batch's per-item results.
	 * @param string                           $fromUser Who held the case.
	 * @param string                           $toUser   Who holds it now.
	 * @param string                           $actor    Who ran the act.
	 * @param string                           $batchId  The act.
	 * @param string                           $now      When.
	 *
	 * @return void
	 */
	private function recordOnCases(array $cases, string $fromUser, string $toUser, string $actor, string $batchId, string $now): void {
		foreach ($cases as $result) {
			if (is_array($result) === false || ($result['type'] ?? '') !== 'case' || ($result['success'] ?? false) !== true) {
				continue;
			}

			$this->recordOnCase(
				caseId: (string)($result['id'] ?? ''),
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
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function draftsOf(string $uid): array {
		$uid = trim($uid);
		if ($uid === '') {
			return [];
		}

		try {
			[$objectService, $register] = $this->context();
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_type_schema'),
				filters: ['isDraft' => true, '_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq leaver handover: the drafts could not be read',
				['uid' => $uid, 'exception' => $e->getMessage()],
			);

			return [];
		}

		$drafts = [];
		foreach ($rows as $row) {
			$self = (array)($row['@self'] ?? []);
			$owner = trim((string)($self['owner'] ?? ($row['createdBy'] ?? '')));
			if ($owner !== $uid) {
				continue;
			}

			$drafts[] = [
				'id' => trim((string)($row['id'] ?? ($self['id'] ?? ''))),
				'title' => trim((string)($row['title'] ?? '')),
				'schema' => 'caseType',
			];
		}

		return $drafts;
	}//end draftsOf()

	/**
	 * The object service and register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	private function context(): array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settings->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
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
		$schema = $this->settings->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
