<?php

/**
 * Dossiq term carry-over
 *
 * A case that arrives from another system arrives with a clock that was
 * already running there. Writing the case binds a fresh statutory term from
 * the receipt moment (DeadlineCaseCreatedListener), which is right for a
 * request nobody extended or suspended; for one that was, the source already
 * knows a later end date, and the requester was told that date. This class
 * puts the case's statutory term on the source's state: it cancels the fresh
 * timer, writes the source's end date, extension count and status onto the
 * instance, records each extension on the trail, re-arms through the same
 * arming code every in-flight term uses (REQ-TOT-006, SLA from the start date
 * to the current end) and suspends at once when the source was suspended. A
 * source that is already closed completes the term.
 *
 * Generic: any case type that imports records (case-record-import) uses it,
 * and nothing in it knows which procedure the case follows.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-an-imported-records-running-term-is-carried-onto-the-case-req-cri-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;

/**
 * Puts a case's statutory term on the state a source system reported.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-an-imported-records-running-term-is-carried-onto-the-case-req-cri-003
 */
class TermCarryOver {

	/**
	 * The states a source term can be in.
	 */
	public const STATE_RUNNING = 'running';

	public const STATE_SUSPENDED = 'suspended';

	public const STATE_CLOSED = 'closed';

	/**
	 * Outcomes.
	 */
	public const KEPT = 'kept';

	public const CARRIED = 'carried';

	public const COMPLETED = 'completed';

	public const NOT_ARMED = 'not-armed';

	public const MISSING = 'missing';

	/**
	 * Why, as the engine and the trail will read it.
	 */
	private const REASON = 'Termijn overgenomen uit het bronsysteem';

	/**
	 * Constructor.
	 *
	 * @param TermijnService      $terms  The one writer of a term instance.
	 * @param TermijnTimerService $timers The engine timer mapping.
	 * @param CaseDateNormaliser  $dates  The one reader of a date.
	 */
	public function __construct(
		private readonly TermijnService $terms,
		private readonly TermijnTimerService $timers,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * Carry a source's term state onto the case's statutory term.
	 *
	 * @param string               $caseId         The case.
	 * @param array<string, mixed> $term           `{state, endDate, extensions, extensionReason, closedOn}`:
	 *                                             state is running, suspended or closed; the dates `Y-m-d` or ''.
	 * @param string               $definitionSlug The term definition the case's statutory term runs under.
	 * @param int                  $extensionDays  What one extension is worth, from the case type, for the trail.
	 *
	 * @return array{outcome: string, instance: string, timer: string} What happened to the clock.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-an-imported-records-running-term-is-carried-onto-the-case-req-cri-003
	 */
	public function carry(string $caseId, array $term, string $definitionSlug, int $extensionDays = 0): array {
		$instance = $this->statutoryInstance(caseId: $caseId);
		if ($instance === null) {
			return ['outcome' => self::MISSING, 'instance' => '', 'timer' => ''];
		}

		$instanceId = (string)($instance['id'] ?? '');
		$state = (string)($term['state'] ?? self::STATE_RUNNING);
		if ($state === self::STATE_CLOSED) {
			// Completing the term cancels every open timer of the instance in
			// the same operation (markTermijnCompleted, REQ-TOT-001), so the fresh timer goes with it.
			$this->terms->markTermijnCompleted(
				termInstanceId: $instanceId,
				voltooiDatum: $this->dates->tryParse(($term['closedOn'] ?? null)),
				rationale: self::REASON . ': daar al afgesloten',
			);

			return ['outcome' => self::COMPLETED, 'instance' => $instanceId, 'timer' => ''];
		}

		$deadline = (string)$this->dates->toCalendarDateOrNull(value: ($term['endDate'] ?? null));
		$extensions = max(0, (int)($term['extensions'] ?? 0));
		$suspended = ($state === self::STATE_SUSPENDED);
		if ($this->unchanged(instance: $instance, deadline: $deadline, extensions: $extensions, suspended: $suspended) === true) {
			return ['outcome' => self::KEPT, 'instance' => $instanceId, 'timer' => (string)($instance['engineTimerId'] ?? '')];
		}

		$definitie = ($this->terms->getTermijnDefinitie(caseType: $definitionSlug) ?? []);
		$carried = $this->rearm(instance: $instance, definitie: $definitie, deadline: $deadline, extensions: $extensions, suspended: $suspended);
		$this->recordExtensions(
			instanceId: $instanceId,
			extensions: $extensions,
			reason: trim((string)($term['extensionReason'] ?? '')),
			basis: (string)($definitie['legalBasis'] ?? ''),
			days: max(0, $extensionDays),
		);

		return $carried;
	}//end carry()

	/**
	 * Cancel the fresh timer, write the source's term and arm it again.
	 *
	 * @param array<string, mixed> $instance   The statutory instance.
	 * @param array<string, mixed> $definitie  The term definition, or [].
	 * @param string               $deadline   The source's end date, `Y-m-d`, or ''.
	 * @param int                  $extensions How often the source was extended.
	 * @param bool                 $suspended  Whether the source was suspended.
	 *
	 * @return array{outcome: string, instance: string, timer: string}
	 */
	private function rearm(array $instance, array $definitie, string $deadline, int $extensions, bool $suspended): array {
		$instanceId = (string)($instance['id'] ?? '');
		$this->timers->cancelForInstance(instanceId: $instanceId, reason: self::REASON);

		$patch = [
			'countExtensions' => $extensions,
			'status' => $this->statusFor(extensions: $extensions, suspended: $suspended),
			'engineTimerId' => '',
		];
		if ($deadline !== '') {
			$patch['endDateCurrent'] = $deadline;
		}

		$written = ($this->terms->updateTermijnInstance(termInstanceId: $instanceId, patch: $patch) ?? (array_merge($instance, $patch)));
		$timerId = $this->timers->armBeslistermijn(instance: $written, definitie: $definitie);
		if ($timerId === null) {
			return ['outcome' => self::NOT_ARMED, 'instance' => $instanceId, 'timer' => ''];
		}

		$this->terms->updateTermijnInstance(
			termInstanceId: $instanceId,
			patch: ['engineTimerId' => $timerId, 'timerBreachesAfterLastDay' => true]
		);

		if ($suspended === true) {
			$written['engineTimerId'] = $timerId;
			$this->timers->suspendBeslistermijn(instance: $written, reason: self::REASON . ': opgeschort', until: null);
		}

		return ['outcome' => self::CARRIED, 'instance' => $instanceId, 'timer' => $timerId];
	}//end rearm()

	/**
	 * Put the source's extensions on the term's trail, so the case reads why its end moved.
	 *
	 * The end date itself is already the source's; these rows carry the reason
	 * and keep a later rebind from losing the extension (TermRearm replays them).
	 *
	 * @param string $instanceId The statutory instance.
	 * @param int    $extensions How often the source was extended.
	 * @param string $reason     The source's extension reason, or ''.
	 * @param string $basis      The definition's legal basis, or ''.
	 * @param int    $days       What one extension is worth.
	 *
	 * @return void
	 */
	private function recordExtensions(string $instanceId, int $extensions, string $reason, string $basis, int $days): void {
		if ($reason === '') {
			$reason = self::REASON;
		}

		for ($i = 0; $i < $extensions; $i++) {
			$this->terms->recordEvent(
				termInstanceId: $instanceId,
				type: 'verdaging',
				basis: $basis,
				rationale: $reason,
				daysImpact: $days,
			);
		}
	}//end recordExtensions()

	/**
	 * Whether the term already says what the source says.
	 *
	 * A second run over a half-finished import finds the term it carried the
	 * first time; reading the instance against the source, rather than against
	 * "no extension, not suspended", is what keeps that run from arming the
	 * same term twice.
	 *
	 * @param array<string, mixed> $instance   The statutory instance.
	 * @param string               $deadline   The source's end date, or ''.
	 * @param int                  $extensions How often the source was extended.
	 * @param bool                 $suspended  Whether the source was suspended.
	 *
	 * @return bool True when nothing needs to move.
	 */
	private function unchanged(array $instance, string $deadline, int $extensions, bool $suspended): bool {
		$current = (string)$this->dates->toCalendarDateOrNull(value: ($instance['endDateCurrent'] ?? null));

		return (string)($instance['status'] ?? '') === $this->statusFor(extensions: $extensions, suspended: $suspended)
			&& (int)($instance['countExtensions'] ?? 0) === $extensions
			&& ($deadline === '' || $deadline === $current)
			&& (string)($instance['engineTimerId'] ?? '') !== '';
	}//end unchanged()

	/**
	 * The instance status a source's term state reads as.
	 *
	 * @param int  $extensions How often the source was extended.
	 * @param bool $suspended  Whether the source was suspended.
	 *
	 * @return string `paused`, `verlengd` or `lopend`.
	 */
	private function statusFor(int $extensions, bool $suspended): string {
		if ($suspended === true) {
			return 'paused';
		}

		if ($extensions > 0) {
			return 'verlengd';
		}

		return 'lopend';
	}//end statusFor()

	/**
	 * The case's statutory instance that is not completed, when there is one.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null The instance.
	 */
	private function statutoryInstance(string $caseId): ?array {
		try {
			$instances = $this->terms->instancesForCase(caseId: $caseId);
		} catch (\Throwable) {
			return null;
		}

		foreach ($instances as $instance) {
			// An absent or unknown kind reads as statutory, as TermKind::ofInstance() reads it.
			$kind = (string)($instance['kind'] ?? '');
			$statutory = ($kind === TermKind::STATUTORY || in_array($kind, TermKind::ALL, true) === false);
			if ($statutory === true
				&& (string)($instance['status'] ?? '') !== 'completed'
				&& (string)($instance['id'] ?? '') !== ''
			) {
				return $instance;
			}
		}

		return null;
	}//end statutoryInstance()
}//end class
