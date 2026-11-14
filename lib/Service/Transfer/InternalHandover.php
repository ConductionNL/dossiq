<?php

/**
 * Handing a case to the team next door, on the record the federated transfer
 * already writes.
 *
 * 🔑 THE SAME RECORD SHAPE, WITH THE BOUNDARY REMOVED (D-1). `casetransfer`
 * already carries a reason, an acceptance, a refusal with a reason and an
 * append-only custody trail. All of it assumed two organisations. This class
 * writes the same record with a TEAM in place of the target organisation, so
 * "where has this case been" has one answer whether the case crossed a
 * gemeente boundary or a corridor. A second mechanism beside it would give
 * two custody trails that disagree, and ADR-011 exists to stop that.
 *
 * 🔴 THE CASE MOVES WHEN IT IS HANDED, AND THE HANDOVER STAYS OUTSTANDING
 * UNTIL SOMEBODY PICKS IT UP. Those are two different facts and this class
 * keeps them apart on purpose. `assignedGroup` becomes the receiving team
 * straight away, because a case that legally sits with Toezicht while the list
 * still says Vergunningen is a case nobody is answerable for. `handoverPending`
 * stays true until the receiving team accepts, which is what keeps the move on
 * the sending team's outstanding list: a handover nobody looked at must not
 * disappear from the sender's screen the moment they made it.
 *
 * WHAT IT DOES NOT DO. It evaluates no grant. Who may hand a case on is
 * OpenRegister's answer, read through
 * {@see \OCA\Dossiq\Service\Access\OpenRegisterGrantsGateway} at the surfaces
 * that offer the act. It does not touch the case number, the history, the
 * documents or the running terms, which is what makes this a handover rather
 * than a new case: a new case would restart the Awb clock, and that is not a
 * transfer, it is a way to hide a late case.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transfer
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transfer;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\Routing\RoutingNote;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Initiates, accepts and refuses a handover between two teams of one organisation.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class InternalHandover {

	use SearchesObjects;

	/**
	 * The value `handoverScope` carries for a move inside this organisation.
	 *
	 * @var string
	 */
	public const SCOPE_TEAM = 'team';

	/**
	 * The rule slug a handover of an unreadable case refuses under.
	 *
	 * @var string
	 */
	public const CASE_UNREADABLE = 'handover-case-unreadable';

	/**
	 * The rule slug an accept or refuse of an unknown handover refuses under.
	 *
	 * @var string
	 */
	public const HANDOVER_UNKNOWN = 'handover-not-found';

	/**
	 * The rule slug a second completion of one handover refuses under.
	 *
	 * @var string
	 */
	public const HANDOVER_SETTLED = 'handover-already-settled';

	/**
	 * How many outstanding handovers one read answers with.
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
	 * @param SettingsService     $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param TeamDirectory       $teams           Resolves the receiving team.
	 * @param CaseSeatReconciler  $seats           Empties the seats the receiving team cannot fill.
	 * @param DoorzendingNotifier $doorzending     Tells the applicant, when Awb 2:3 says we must.
	 * @param LoggerInterface     $logger          Records every move and every refusal.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		SettingsService $settingsService,
		private readonly TeamDirectory $teams,
		private readonly CaseSeatReconciler $seats,
		private readonly DoorzendingNotifier $doorzending,
		private readonly LoggerInterface $logger,
	) {
		$this->dossierRegister = new DossierRegister(settingsService: $settingsService);
	}//end __construct()

	/**
	 * Hand a case to another team.
	 *
	 * @param string $caseId      The case uuid.
	 * @param string $targetTeam  The receiving team's Nextcloud group id.
	 * @param string $reason      Why the case is moving.
	 * @param string $initiatedBy Who handed it on.
	 * @param bool   $doorzending Whether this is a doorzending under Awb 2:3.
	 *
	 * @return array<string, mixed> The transfer record, carrying `announcement`.
	 *
	 * @throws RefusedException When the case cannot be read or the team cannot be resolved.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `doorzending` is a
	 * DECLARATION the handler makes, not a mode switch: it is the fact Awb 2:3
	 * hangs on, it is stored on the record, and splitting it into two methods
	 * would duplicate the whole act for one boolean nobody reads twice.
	 */
	public function initiate(
		string $caseId,
		string $targetTeam,
		string $reason,
		string $initiatedBy,
		bool $doorzending = false,
	): array {
		$case = $this->requireCase(caseId: $caseId);
		$team = $this->teams->require(team: $targetTeam);

		$reconciled = $this->seats->plan(case: $case, team: $team);
		$record = $this->recordHeader(caseId: $caseId, case: $case, team: $team)
			+ $this->recordAct(reason: $reason, doorzending: $doorzending, initiatedBy: $initiatedBy, emptied: $reconciled['emptied']);

		return $this->applyHandover(case: $case, record: $record, reconciled: $reconciled);
	}//end initiate()

	/**
	 * The case and team fields a new handover record opens with.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $case   The case.
	 * @param string               $team   The receiving team.
	 *
	 * @return array<string, mixed> The leading fields of the record.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	private function recordHeader(string $caseId, array $case, string $team): array {
		return [
			'caseId' => $caseId,
			'handoverScope' => self::SCOPE_TEAM,
			'sourceTeam' => trim((string)($case['assignedGroup'] ?? '')),
			'targetTeam' => $team,
			'targetOrganization' => $team,
			'sourceOrganization' => trim((string)($case['sourceOrganisation'] ?? '')),
		];
	}//end recordHeader()

	/**
	 * The fields of a new handover record that describe the act itself.
	 *
	 * @param string                            $reason      Why the case is moving.
	 * @param bool                              $doorzending Whether this is a doorzending under Awb 2:3.
	 * @param string                            $initiatedBy Who handed it on.
	 * @param array<int, array<string, string>> $emptied     The seats the reconciliation empties.
	 *
	 * @return array<string, mixed> The trailing fields of the record.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `doorzending` is the declaration stored on the record.
	 */
	private function recordAct(string $reason, bool $doorzending, string $initiatedBy, array $emptied): array {
		$now = (new DateTimeImmutable())->format('c');

		return [
			'reason' => $reason,
			'doorzending' => $doorzending,
			'requestedDate' => (new DateTimeImmutable())->format('Y-m-d'),
			'status' => 'pending',
			'initiatedBy' => $initiatedBy,
			'emptiedSeats' => $emptied,
			'custodyAuditTrail' => $this->initiatedTrail(initiatedBy: $initiatedBy, timestamp: $now),
		];
	}//end recordAct()

	/**
	 * Record the handover, then move the case and its seats.
	 *
	 * @param array<string, mixed>                                                            $case       The case.
	 * @param array<string, mixed>                                                            $record     The new transfer record.
	 * @param array{emptied: array<int, array<string, string>>, changes: array<string, mixed>} $reconciled The seat reconciliation.
	 *
	 * @return array<string, mixed> The transfer record, carrying `announcement`.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	private function applyHandover(array $case, array $record, array $reconciled): array {
		$caseId = (string) $record['caseId'];
		$team = (string) $record['targetTeam'];

		// RECORDED FIRST, THEN APPLIED. A seat emptied before the handover is
		// on the record leaves a case with nobody on it and no trace of who had
		// been, the day the register refuses the write. This order leaves only
		// the visible failure instead: a seat still filled with the record
		// saying it should not be.
		$saved = $this->saveTransfer(record: $record, uuid: null);
		$this->seats->apply(caseId: $caseId, emptied: $reconciled['emptied']);

		// ONE write, carrying the team and the seats together. Two writes would
		// leave a window in which the case has moved and still names a handler
		// who cannot open it.
		$changes = $this->withHandoverTo(changes: $reconciled['changes'], team: $team);
		$this->writeCase(case: $case, changes: $changes);

		$saved['announcement'] = $this->doorzending->announce(case: $case, transfer: $saved);

		$this->logInitiated(record: $record);

		return $saved;
	}//end applyHandover()

	/**
	 * Log a handover to another team.
	 *
	 * @param array<string, mixed> $record The new transfer record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	private function logInitiated(array $record): void {
		$this->logger->info(
			'Dossiq handover: a case was handed to another team',
			[
				'caseId' => $record['caseId'],
				'from' => $record['sourceTeam'],
				'to' => $record['targetTeam'],
				'doorzending' => $record['doorzending'],
				'emptiedSeats' => count($record['emptiedSeats']),
			],
		);
	}//end logInitiated()

	/**
	 * The custody trail a new handover starts with.
	 *
	 * @param string $initiatedBy Who handed the case on.
	 * @param string $timestamp   When, as an ISO 8601 string.
	 *
	 * @return array<int, array<string, string>> The trail with its one `initiated` entry.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	private function initiatedTrail(string $initiatedBy, string $timestamp): array {
		return [
			[
				'event' => 'initiated',
				'actor' => $initiatedBy,
				'actorType' => 'local',
				'cloudId' => '',
				'timestamp' => $timestamp,
			],
		];
	}//end initiatedTrail()

	/**
	 * The reconciled seat changes plus the team the case moves to.
	 *
	 * @param array<string, mixed> $changes The seat changes from the reconciliation.
	 * @param string               $team    The receiving team.
	 *
	 * @return array<string, mixed> The one write to make on the case.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	private function withHandoverTo(array $changes, string $team): array {
		return array_merge(
			$changes,
			[
				'assignedGroup' => $team,
				'handoverPending' => true,
				'handoverTo' => $team,
			],
		);
	}//end withHandoverTo()

	/**
	 * Accept a handover on the receiving team's behalf.
	 *
	 * @param string $transferId The handover's uuid.
	 * @param string $acceptedBy Who accepted it.
	 *
	 * @return array<string, mixed> The settled transfer record.
	 *
	 * @throws RefusedException When the handover is unknown or already settled.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function accept(string $transferId, string $acceptedBy): array {
		return $this->settle(
			settlement: new HandoverSettlement(
				transferId: $transferId,
				status: 'accepted',
				actor: $acceptedBy,
				reason: '',
			)
		);
	}//end accept()

	/**
	 * Refuse a handover back, with a reason, returning the case to the sender.
	 *
	 * @param string $transferId The handover's uuid.
	 * @param string $reason     Why the receiving team will not take it.
	 * @param string $refusedBy  Who refused it.
	 *
	 * @return array<string, mixed> The settled transfer record.
	 *
	 * @throws RefusedException When the handover is unknown or already settled.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function refuse(string $transferId, string $reason, string $refusedBy): array {
		return $this->settle(
			settlement: new HandoverSettlement(
				transferId: $transferId,
				status: 'rejected',
				actor: $refusedBy,
				reason: $reason,
			)
		);
	}//end refuse()

	/**
	 * The handovers a team sent that nobody has picked up.
	 *
	 * Read off the transfer records rather than off the cases: the case has
	 * already moved to the receiving team, so a query over the sending team's
	 * cases would answer nothing at all. That is the whole reason this list
	 * exists.
	 *
	 * @param string $team The sending team's Nextcloud group id.
	 *
	 * @return array<int, array<string, mixed>> The outstanding handovers.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function outstandingFor(string $team): array {
		$team = trim($team);
		if ($team === '') {
			return [];
		}

		try {
			$rows = $this->pendingFrom(team: $team);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq handover: the outstanding handovers could not be read', ['team' => $team, 'exception' => $e->getMessage()]);

			return [];
		}

		return $this->teamScoped(rows: $rows);
	}//end outstandingFor()

	/**
	 * The pending transfer records a team sent.
	 *
	 * @param string $team The sending team's Nextcloud group id.
	 *
	 * @return array<int, array<string, mixed>> The pending transfers.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	private function pendingFrom(string $team): array {
		[$objectService, $register] = $this->context();

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $this->schema(key: 'case_transfer_schema'),
			filters: ['sourceTeam' => $team, 'status' => 'pending', '_limit' => self::PAGE_SIZE],
		);
	}//end pendingFrom()

	/**
	 * The transfers handed over to a team, not to one person.
	 *
	 * @param array<int, array<string, mixed>> $rows The pending transfers.
	 *
	 * @return array<int, array<string, mixed>> The outstanding handovers.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	private function teamScoped(array $rows): array {
		$outstanding = [];
		foreach ($rows as $row) {
			if (trim((string)($row['handoverScope'] ?? '')) === self::SCOPE_TEAM) {
				$outstanding[] = $row;
			}
		}

		return $outstanding;
	}//end teamScoped()

	/**
	 * Settle a pending handover, accepted or refused, on the one custody trail.
	 *
	 * @param HandoverSettlement $settlement The handover, its new status, who settled it and why.
	 *
	 * @return array<string, mixed> The settled record.
	 *
	 * @throws RefusedException When the handover is unknown or already settled.
	 */
	private function settle(HandoverSettlement $settlement): array {
		$transferId = $settlement->transferId;
		$status = $settlement->status;
		$transfer = $this->requireTransfer(transferId: $transferId);
		$current = trim((string)($transfer['status'] ?? ''));

		if ($current === $status) {
			// A repeated call that already landed. Idempotent, like the
			// federated accept beside it.
			return $transfer;
		}

		$this->assertPending(current: $current);

		$transfer = $this->settledRecord(transfer: $transfer, settlement: $settlement);

		$saved = $this->saveTransfer(record: $transfer, uuid: $transferId);
		$this->settleCase(transfer: $saved, status: $status);

		$this->logSettled(transfer: $transfer, settlement: $settlement);

		return $saved;
	}//end settle()

	/**
	 * Refuse to change a handover that is no longer pending.
	 *
	 * @param string $current The handover's current status.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the handover is already settled.
	 */
	private function assertPending(string $current): void {
		if ($current !== 'pending') {
			throw new RefusedException(
				rule: self::HANDOVER_SETTLED,
				sentence: 'This handover was already settled, so it cannot be changed.',
				status: RefusedException::STATUS_REFUSED,
			);
		}
	}//end assertPending()

	/**
	 * The transfer record with the settlement written onto it.
	 *
	 * @param array<string, mixed> $transfer   The pending record.
	 * @param HandoverSettlement   $settlement The new status, who settled it and the refusal's reason.
	 *
	 * @return array<string, mixed> The settled record, not yet saved.
	 */
	private function settledRecord(array $transfer, HandoverSettlement $settlement): array {
		$status = $settlement->status;
		$actor = $settlement->actor;
		$now = (new DateTimeImmutable())->format('c');
		$trail = (array)($transfer['custodyAuditTrail'] ?? []);
		$trail[] = $this->trailEntry(event: $status, actor: $actor, timestamp: $now);

		$transfer['status'] = $status;
		$transfer['acceptedBy'] = $actor;
		$transfer['completedAt'] = $now;
		$transfer['custodyAuditTrail'] = $trail;
		if ($status === 'rejected') {
			$transfer['rejectionReason'] = $settlement->reason;
		}

		return $transfer;
	}//end settledRecord()

	/**
	 * One local entry on the custody trail.
	 *
	 * @param string $event     The event.
	 * @param string $actor     Who acted.
	 * @param string $timestamp When, as an ISO 8601 string.
	 *
	 * @return array<string, string> The trail entry.
	 */
	private function trailEntry(string $event, string $actor, string $timestamp): array {
		return [
			'event' => $event,
			'actor' => $actor,
			'actorType' => 'local',
			'cloudId' => '',
			'timestamp' => $timestamp,
		];
	}//end trailEntry()

	/**
	 * Log a settled handover.
	 *
	 * @param array<string, mixed> $transfer   The settled record.
	 * @param HandoverSettlement   $settlement The handover, its new status and who settled it.
	 *
	 * @return void
	 */
	private function logSettled(array $transfer, HandoverSettlement $settlement): void {
		$this->logger->info(
			'Dossiq handover: a handover was ' . $settlement->status,
			[
				'transferId' => $settlement->transferId,
				'caseId' => trim((string)($transfer['caseId'] ?? '')),
				'actor' => $settlement->actor,
			],
		);
	}//end logSettled()

	/**
	 * Bring the case in line with a settled handover.
	 *
	 * An acceptance only clears the pending marker: the case moved when it was
	 * handed. A refusal puts it back on the sending team, which is the one
	 * thing a refusal has to do.
	 *
	 * @param array<string, mixed> $transfer The settled record.
	 * @param string               $status   'accepted' or 'rejected'.
	 *
	 * @return void
	 */
	private function settleCase(array $transfer, string $status): void {
		$caseId = trim((string)($transfer['caseId'] ?? ''));
		if ($caseId === '') {
			return;
		}

		try {
			$case = $this->requireCase(caseId: $caseId);
		} catch (RefusedException $e) {
			$this->logger->warning(
				'Dossiq handover: the case could not be brought in line with its settled handover',
				['caseId' => $caseId, 'rule' => $e->getRule()],
			);

			return;
		}

		$changes = ['handoverPending' => false, 'handoverTo' => ''];
		if ($status === 'rejected') {
			$changes['assignedGroup'] = trim((string)($transfer['sourceTeam'] ?? ''));
			$changes['routingNote'] = RoutingNote::handoverRefused(
				team: trim((string)($transfer['targetTeam'] ?? '')),
				reason: (string)($transfer['rejectionReason'] ?? ''),
			);
		}

		$this->writeCase(case: $case, changes: $changes);
	}//end settleCase()

	/**
	 * The case, or a refusal.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The stored case.
	 *
	 * @throws RefusedException When it cannot be read.
	 */
	private function requireCase(string $caseId): array {
		try {
			[$objectService, $register] = $this->context();
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				id: $caseId,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::CASE_UNREADABLE,
				sentence: 'We could not read that case, so it was not handed on.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($case === null) {
			throw new RefusedException(
				rule: self::CASE_UNREADABLE,
				sentence: 'We could not read that case, so it was not handed on.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end requireCase()

	/**
	 * The handover record, or a refusal.
	 *
	 * @param string $transferId The handover's uuid.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @throws RefusedException When it cannot be read.
	 */
	private function requireTransfer(string $transferId): array {
		try {
			[$objectService, $register] = $this->context();
			$transfer = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_transfer_schema'),
				id: $transferId,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::HANDOVER_UNKNOWN,
				sentence: 'We could not read that handover, so nothing was changed.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($transfer === null) {
			throw new RefusedException(
				rule: self::HANDOVER_UNKNOWN,
				sentence: 'That handover could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $transfer;
	}//end requireTransfer()

	/**
	 * Store a transfer record, new or existing.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string|null          $uuid   The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored record.
	 *
	 * @throws RefusedException When it could not be stored.
	 */
	private function saveTransfer(array $record, ?string $uuid): array {
		unset($record['@self'], $record['announcement']);

		try {
			$saved = $this->storeTransferRow(record: $record, uuid: $uuid);
		} catch (Throwable $e) {
			throw $this->unrecorded(previous: $e);
		}

		if ($saved === null) {
			throw $this->unrecorded(previous: null);
		}

		return $saved;
	}//end saveTransfer()

	/**
	 * Write a transfer record.
	 *
	 * @param array<string, mixed> $record The record, without `@self` and `announcement`.
	 * @param string|null          $uuid   The uuid to update, or null to create.
	 *
	 * @return array<string, mixed>|null The stored record, or null when the store answered nothing.
	 */
	private function storeTransferRow(array $record, ?string $uuid): ?array {
		[$objectService, $register] = $this->context();

		return $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schema(key: 'case_transfer_schema'),
			object: $record,
			uuid: $uuid,
		);
	}//end storeTransferRow()

	/**
	 * The refusal when a transfer record could not be stored.
	 *
	 * @param Throwable|null $previous The failure, when the store threw.
	 *
	 * @return RefusedException The refusal.
	 */
	private function unrecorded(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: self::CASE_UNREADABLE,
			sentence: 'The handover could not be recorded, so the case stays where it is.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unrecorded()

	/**
	 * Apply changes to the stored case.
	 *
	 * @param array<string, mixed> $case    The case as it was read.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return void
	 */
	private function writeCase(array $case, array $changes): void {
		if ($changes === []) {
			return;
		}

		$caseId = trim((string)($case['id'] ?? ($case['uuid'] ?? '')));
		if ($caseId === '') {
			return;
		}

		$payload = array_merge($case, $changes);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				uuid: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq handover: the case could not be written',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);
		}
	}//end writeCase()

	/**
	 * The object service and the register, or an exception.
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
