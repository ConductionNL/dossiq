<?php

/**
 * Suspending, resuming, extending and reopening a case.
 *
 * The four gestures that move a case without moving it to another status.
 * Each one is statutory: opschorting (Awb 4:5), hervatting, verlenging
 * (Awb 4:14) and heropening. They are collected here because they share one
 * shape — read the case, ask its case type whether the gesture is allowed,
 * move the statutory clock, and journal what happened on the case.
 *
 * THE CLOCK LIVES IN TWO PLACES, AND BOTH ARE WRITTEN. A case that has a
 * TermijnInstance keeps its statutory bookkeeping there (that is what the
 * daily scan, the dwangsom engine and the reports read); a case without one
 * — the ordinary state of a case type nobody has configured a TermijnDefinitie
 * for — still records the gesture on itself, so the case page can say the case
 * is suspended. Writing only the instance would leave every unconfigured case
 * with a Suspend button that does nothing visible.
 *
 * THE JOURNAL IS `case.activity`. The case schema has no `suspended` flag and
 * this change adds none: `activity` is the declared, writable activity log,
 * and suspension is exactly an activity. The current state is the last entry
 * of the journal, which makes "suspended" a derived fact rather than a second
 * field that can disagree with the events that produced it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The case lifecycle gestures the Actions menu offers.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseLifecycleService {

	/**
	 * The journal key on the case that carries the lifecycle events.
	 */
	private const JOURNAL_FIELD = 'activity';

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $store OpenRegister reads/writes for the case
	 * @param CaseTypeReader $caseTypes The case type's own rules
	 * @param TermijnService $termService Statutory-term instances
	 * @param DeadlinePauseService $pauseService Opschorten / hervatten (Awb 4:5)
	 * @param DeadlineExtensionService $extensionService Verlengen (Awb 4:14)
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseTypeReader $caseTypes,
		private readonly TermijnService $termService,
		private readonly DeadlinePauseService $pauseService,
		private readonly DeadlineExtensionService $extensionService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What the case allows right now.
	 *
	 * The case page reads this to decide which lifecycle affordances are
	 * honest to offer, and to show the suspended marker.
	 *
	 * @param string $caseId Case UUID
	 *
	 * @return array{suspended: bool, canSuspend: bool, canResume: bool,
	 *               canExtend: bool, canReopen: bool, isFinalStatus: bool,
	 *               extensionCount: int, deadline: string, statusName: string}
	 *
	 * @throws RuntimeException When the case cannot be read
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function state(string $caseId): array {
		$case = $this->requireCase(caseId: $caseId);
		$caseType = $this->caseTypes->read(caseTypeId: (string)($case['caseType'] ?? ''));

		$suspended = $this->isSuspended(case: $case);
		$isFinal = $this->caseTypes->isFinalStatus(statusTypeId: (string)($case['status'] ?? ''));

		return [
			'suspended' => $suspended,
			'canSuspend' => ($caseType['suspensionAllowed'] === true && $suspended === false && $isFinal === false),
			'canResume' => $suspended,
			'canExtend' => ($caseType['extensionAllowed'] === true && $isFinal === false),
			'canReopen' => $isFinal,
			'isFinalStatus' => $isFinal,
			'extensionCount' => (int)($case['extensionCount'] ?? 0),
			'deadline' => (string)($case['plannedEndDate'] ?? ($case['deadline'] ?? '')),
			'statusName' => $this->store->lookupStatusName(statusTypeId: (string)($case['status'] ?? '')),
		];
	}//end state()

	/**
	 * Suspend the case (opschorting, Awb 4:5).
	 *
	 * @param string $caseId Case UUID
	 * @param string $reason Why the case is suspended
	 * @param int $days How long the applicant is given, in days
	 *
	 * @return array<string, mixed> The lifecycle state after the write
	 *
	 * @throws RuntimeException When the case type forbids suspension, the case
	 *                          is already suspended, or the reason is empty
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function suspend(string $caseId, string $reason, int $days): array {
		$case = $this->requireCase(caseId: $caseId);
		$this->requireReason(reason: $reason);

		$caseType = $this->caseTypes->read(caseTypeId: (string)($case['caseType'] ?? ''));
		if ($caseType['suspensionAllowed'] !== true) {
			throw new RuntimeException('suspension_not_allowed');
		}

		if ($this->isSuspended(case: $case) === true) {
			throw new RuntimeException('already_suspended');
		}

		$duration = $days;
		if ($duration <= 0) {
			$duration = 14;
		}

		$this->onTermInstance(
			caseId: $caseId,
			gesture: 'suspend',
			apply: fn (string $instanceId): array => $this->pauseService->registerPauze(
				termInstanceId: $instanceId,
				durationDays: $duration,
				rationale: $reason,
			),
		);

		$this->journal(case: $case, entry: ['type' => 'suspend', 'reason' => $reason, 'days' => $duration]);

		return $this->state(caseId: $caseId);
	}//end suspend()

	/**
	 * Resume a suspended case (hervatting).
	 *
	 * @param string $caseId Case UUID
	 * @param string $reason Why the case resumes
	 *
	 * @return array<string, mixed> The lifecycle state after the write
	 *
	 * @throws RuntimeException When the case is not suspended or the reason is empty
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function resume(string $caseId, string $reason): array {
		$case = $this->requireCase(caseId: $caseId);
		$this->requireReason(reason: $reason);

		if ($this->isSuspended(case: $case) === false) {
			throw new RuntimeException('not_suspended');
		}

		$this->onTermInstance(
			caseId: $caseId,
			gesture: 'resume',
			apply: fn (string $instanceId): array => $this->pauseService->resumeAfterPauze(
				termInstanceId: $instanceId,
			),
		);

		$this->journal(case: $case, entry: ['type' => 'resume', 'reason' => $reason]);

		return $this->state(caseId: $caseId);
	}//end resume()

	/**
	 * Extend the processing term (verlenging, Awb 4:14).
	 *
	 * The new end date is the current one plus the case type's
	 * `extensionPeriod`, which the case type states as an ISO 8601 duration —
	 * unless the caller names an explicit `$newEndDate`, which the bulk
	 * Extend term action does. An explicit date still has to be LATER than
	 * the current end: an "extension" that shortens the term is a mistake,
	 * and refusing it per case is cheaper than explaining it afterwards.
	 * `extensionAllowed` and the reason are required either way, so naming a
	 * date buys the caller no authority the case type has not granted.
	 *
	 * @param string $caseId Case UUID
	 * @param string $reason Why the term is extended
	 * @param string $newEndDate Explicit new end date (Y-m-d); the case type's
	 *                           period is used when this is empty
	 *
	 * @return array<string, mixed> The lifecycle state after the write
	 *
	 * @throws RuntimeException When the case type forbids extension, states no
	 *                          period, the reason is empty, or the named date is
	 *                          unreadable or not after the current end date
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function extend(string $caseId, string $reason, string $newEndDate = ''): array {
		$case = $this->requireCase(caseId: $caseId);
		$this->requireReason(reason: $reason);

		$caseType = $this->caseTypes->read(caseTypeId: (string)($case['caseType'] ?? ''));
		if ($caseType['extensionAllowed'] !== true) {
			throw new RuntimeException('extension_not_allowed');
		}

		$current = (string)($case['plannedEndDate'] ?? ($case['deadline'] ?? ''));
		$newEnd = $this->resolveNewEndDate(
			current: $current,
			named: $newEndDate,
			period: (string)$caseType['extensionPeriod'],
		);

		$this->onTermInstance(
			caseId: $caseId,
			gesture: 'extend',
			apply: fn (string $instanceId): array => $this->extensionService->requestExtension(
				termInstanceId: $instanceId,
				rationale: $reason,
				newEndDate: $newEnd,
			),
		);

		$case['plannedEndDate'] = $newEnd;
		$case['extensionCount'] = ((int)($case['extensionCount'] ?? 0) + 1);
		$this->journal(case: $case, entry: ['type' => 'extend', 'reason' => $reason, 'newEndDate' => $newEnd]);

		return $this->state(caseId: $caseId);
	}//end extend()

	/**
	 * Reopen a closed case.
	 *
	 * The case goes back to its type's initial status and an audit row is
	 * written, so the reopening reads in the history like any other move.
	 * Whether the caller MAY reopen is the controller's question; this method
	 * refuses only what the case itself forbids.
	 *
	 * @param string $caseId Case UUID
	 * @param string $reason Why the case is reopened
	 *
	 * @return array<string, mixed> The lifecycle state after the write
	 *
	 * @throws RuntimeException When the case is not closed, the case type has no
	 *                          initial status, or the reason is empty
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function reopen(string $caseId, string $reason): array {
		$case = $this->requireCase(caseId: $caseId);
		$this->requireReason(reason: $reason);

		$currentId = (string)($case['status'] ?? '');
		if ($this->caseTypes->isFinalStatus(statusTypeId: $currentId) === false) {
			throw new RuntimeException('case_not_closed');
		}

		$caseTypeId = (string)($case['caseType'] ?? '');
		$caseType = $this->caseTypes->read(caseTypeId: $caseTypeId);
		$initial = (string)$caseType['initialStatus'];
		if ($initial === '') {
			throw new RuntimeException('initial_status_not_configured');
		}

		// The status must belong to THIS case type. The check reads the
		// statusType's own `caseType` field: the relation lives on the child,
		// which is the only side that can be filtered or trusted.
		if ($this->caseTypes->statusBelongsTo(statusTypeId: $initial, caseTypeId: $caseTypeId) === false) {
			throw new RuntimeException('initial_status_not_of_case_type');
		}

		$case['status'] = $initial;
		$case['endDate'] = '';
		// zrc-008: reopening withdraws the archival claim as well as the end
		// date. A case that is open again is not nominated for anything and has
		// no destruction date; leaving either standing would hand an archivist a
		// due date for a case still being worked, which is the shape of mistake
		// that gets a record destroyed early.
		$case['archiveNomination'] = null;
		$case['archiveActionDate'] = null;
		$this->journal(case: $case, entry: ['type' => 'reopen', 'reason' => $reason]);

		$this->store->writeStatusRecord(
			caseId: $caseId,
			toStatus: $initial,
			fromStatus: $currentId,
			label: 'Reopened',
			comment: $reason,
			evaluatedGuards: [],
			noWorkflowTemplate: true,
		);

		return $this->state(caseId: $caseId);
	}//end reopen()

	/**
	 * Whether the case's journal says it is suspended.
	 *
	 * @param array<string, mixed> $case The loaded case
	 *
	 * @return bool True when the last suspend/resume entry is a suspend
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function isSuspended(array $case): bool {
		$entries = $this->readJournal(case: $case);
		for ($i = (count($entries) - 1); $i >= 0; $i--) {
			$type = (string)($entries[$i]['type'] ?? '');
			if ($type === 'suspend') {
				return true;
			}

			if ($type === 'resume') {
				return false;
			}
		}

		return false;
	}//end isSuspended()

	/**
	 * Read the case's lifecycle journal.
	 *
	 * The field is declared as a string holding a JSON array; anything that
	 * does not parse is treated as an empty journal rather than an error, so
	 * a case whose activity log was written by something else still works.
	 *
	 * @param array<string, mixed> $case The loaded case
	 *
	 * @return array<int, array<string, mixed>> The journal entries, oldest first
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function readJournal(array $case): array {
		$raw = ($case[self::JOURNAL_FIELD] ?? '');
		$decoded = $raw;
		if (is_array($raw) === false) {
			$decoded = json_decode((string)$raw, true);
		}

		if (is_array($decoded) === false) {
			return [];
		}

		$entries = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) === true) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end readJournal()

	/**
	 * Append one entry to the journal and save the case.
	 *
	 * @param array<string, mixed> $case The loaded case (already carrying any other change)
	 * @param array<string, mixed> $entry The entry to append
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function journal(array $case, array $entry): void {
		$entries = $this->readJournal(case: $case);
		$entry['at'] = (new DateTimeImmutable())->format('c');
		$entries[] = $entry;

		$case[self::JOURNAL_FIELD] = json_encode($entries);
		$this->store->saveCase(case: $case);
	}//end journal()

	/**
	 * Run a term-instance gesture, when the case has an instance to run it on.
	 *
	 * A case with no TermijnInstance is the ordinary case of a case type
	 * nobody configured a TermijnDefinitie for. The gesture still happens on
	 * the case; only the statutory bookkeeping is skipped, and it is logged
	 * rather than swallowed silently.
	 *
	 * @param string $caseId Case UUID
	 * @param string $gesture The gesture name, for the log line
	 * @param callable(string): array<string, mixed> $apply What to do with the instance id
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function onTermInstance(string $caseId, string $gesture, callable $apply): void {
		$instance = $this->termService->getTermijnInstanceForZaak(caseId: $caseId);
		$instanceId = (string)($instance['id'] ?? ($instance['@self']['id'] ?? ''));
		if ($instanceId === '') {
			$this->logger->info(
				'CaseLifecycleService: no TermijnInstance for case, statutory clock untouched',
				['caseId' => $caseId, 'gesture' => $gesture],
			);
			return;
		}

		$apply($instanceId);
	}//end onTermInstance()

	/**
	 * The new end date an extension lands on: the one the caller named, or
	 * the current one plus the case type's statutory period.
	 *
	 * @param string $current The current end date (Y-m-d), possibly empty
	 * @param string $named An explicit new end date, or '' for the period
	 * @param string $period The case type's ISO 8601 extension period
	 *
	 * @return string The new end date as Y-m-d
	 *
	 * @throws RuntimeException When the named date is unreadable or not later,
	 *                          or when no period is configured and none was named
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	private function resolveNewEndDate(string $current, string $named, string $period): string {
		if ($named !== '') {
			return $this->requireLaterDate(current: $current, candidate: $named);
		}

		if ($period === '') {
			throw new RuntimeException('extension_period_not_configured');
		}

		return $this->addPeriod(date: $current, period: $period);
	}//end resolveNewEndDate()

	/**
	 * An explicit new end date, normalised, having checked it is later than
	 * the current one.
	 *
	 * @param string $current The current end date (Y-m-d), possibly empty
	 * @param string $candidate The date the caller named
	 *
	 * @return string The new end date as Y-m-d
	 *
	 * @throws RuntimeException When the date is unreadable or not later
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	private function requireLaterDate(string $current, string $candidate): string {
		try {
			$next = new DateTimeImmutable($candidate);
		} catch (\Throwable $e) {
			throw new RuntimeException('new_end_date_unreadable');
		}

		if ($current !== '') {
			try {
				$now = new DateTimeImmutable($current);
			} catch (\Throwable $e) {
				$now = null;
			}

			if ($now !== null && $next <= $now) {
				throw new RuntimeException('new_end_date_not_later');
			}
		}

		return $next->format('Y-m-d');
	}//end requireLaterDate()

	/**
	 * Add an ISO 8601 duration to a date.
	 *
	 * @param string $date The current end date (Y-m-d); today when empty
	 * @param string $period An ISO 8601 duration such as P14D
	 *
	 * @return string The new end date as Y-m-d
	 *
	 * @throws RuntimeException When the period is not a duration this can read
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function addPeriod(string $date, string $period): string {
		try {
			$start = new DateTimeImmutable($date);
		} catch (\Throwable $e) {
			$start = new DateTimeImmutable();
		}

		try {
			$interval = new DateInterval($period);
		} catch (\Throwable $e) {
			throw new RuntimeException('extension_period_unreadable');
		}

		return $start->add($interval)->format('Y-m-d');
	}//end addPeriod()

	/**
	 * Load the case, or refuse.
	 *
	 * @param string $caseId Case UUID
	 *
	 * @return array<string, mixed> The case
	 *
	 * @throws RuntimeException When the case cannot be read
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function requireCase(string $caseId): array {
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		return $case;
	}//end requireCase()

	/**
	 * Every gesture asks for a reason, and an empty one is not a reason.
	 *
	 * @param string $reason The reason the caller gave
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the reason is empty
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function requireReason(string $reason): void {
		if (trim($reason) === '') {
			throw new RuntimeException('reason_required');
		}
	}//end requireReason()
}//end class
