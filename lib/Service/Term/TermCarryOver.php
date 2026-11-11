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

		if ((string)($term['state'] ?? self::STATE_RUNNING) === self::STATE_CLOSED) {
			return $this->closed(instance: $instance, term: $term);
		}

		$carried = $this->carriedOf(term: $term, extensionDays: $extensionDays);
		if ($this->unchanged(instance: $instance, carried: $carried) === true) {
			return ['outcome' => self::KEPT, 'instance' => (string)($instance['id'] ?? ''), 'timer' => (string)($instance['engineTimerId'] ?? '')];
		}

		$definitie = ($this->terms->getTermijnDefinitie(caseType: $definitionSlug) ?? []);
		return $this->carryOnto(instance: $instance, carried: $carried->withDefinitie(definitie: $definitie));
	}//end carry()

	/**
	 * Complete the case's term: the source closed it. Completing it cancels every open timer of the instance in
	 * the same operation (markTermijnCompleted, REQ-TOT-001), so the fresh timer goes with it.
	 *
	 * @param array<string, mixed> $instance The case's statutory term instance.
	 * @param array<string, mixed> $term     The source's term state.
	 *
	 * @return array{outcome: string, instance: string, timer: string} Completed.
	 */
	private function closed(array $instance, array $term): array {
		$instanceId = (string)($instance['id'] ?? '');
		$this->terms->markTermijnCompleted(
			termInstanceId: $instanceId,
			voltooiDatum: $this->dates->tryParse(($term['closedOn'] ?? null)),
			rationale: self::REASON . ': daar al afgesloten',
		);

		return ['outcome' => self::COMPLETED, 'instance' => $instanceId, 'timer' => ''];
	}//end closed()

	/**
	 * The term state the source declares.
	 *
	 * @param array<string, mixed> $term          The source's term state.
	 * @param int                  $extensionDays What one extension is worth, from the case type.
	 *
	 * @return CarriedTerm The carried term.
	 */
	private function carriedOf(array $term, int $extensionDays): CarriedTerm {
		return new CarriedTerm(
			deadline: (string)$this->dates->toCalendarDateOrNull(value: ($term['endDate'] ?? null)),
			extensions: max(0, (int)($term['extensions'] ?? 0)),
			suspended: ((string)($term['state'] ?? self::STATE_RUNNING) === self::STATE_SUSPENDED),
			reason: trim((string)($term['extensionReason'] ?? '')),
			days: max(0, $extensionDays),
		);
	}//end carriedOf()

	/**
	 * Re-arm the case's term on the carried state and record its extensions.
	 *
	 * @param array<string, mixed> $instance The case's statutory term instance.
	 * @param CarriedTerm          $carried  The carried state, under its definition.
	 *
	 * @return array{outcome: string, instance: string, timer: string} Carried, or not armed.
	 */
	private function carryOnto(array $instance, CarriedTerm $carried): array {
		$outcome = $this->rearm(instance: $instance, carried: $carried);
		$this->recordExtensions(instanceId: (string)($instance['id'] ?? ''), carried: $carried);

		return $outcome;
	}//end carryOnto()

	/**
	 * Cancel the instance's timers, write the carried state, and arm a fresh timer on it.
	 *
	 * @param array<string, mixed> $instance The case's statutory term instance.
	 * @param CarriedTerm          $carried  The carried state, under its definition.
	 *
	 * @return array{outcome: string, instance: string, timer: string} Carried, or not armed.
	 */
	private function rearm(array $instance, CarriedTerm $carried): array {
		$instanceId = (string)($instance['id'] ?? '');
		$this->timers->cancelForInstance(instanceId: $instanceId, reason: self::REASON);
		$written = $this->rewritten(instance: $instance, carried: $carried);
		$timerId = $this->timers->armBeslistermijn(instance: $written, definitie: $carried->definitie);
		if ($timerId === null) {
			return ['outcome' => self::NOT_ARMED, 'instance' => $instanceId, 'timer' => ''];
		}

		$this->terms->updateTermijnInstance(termInstanceId: $instanceId, patch: ['engineTimerId' => $timerId, 'timerBreachesAfterLastDay' => true]);
		if ($carried->suspended === true) {
			$written['engineTimerId'] = $timerId;
			$this->timers->suspendBeslistermijn(instance: $written, reason: self::REASON . ': opgeschort', until: null);
		}

		return ['outcome' => self::CARRIED, 'instance' => $instanceId, 'timer' => $timerId];
	}//end rearm()

	/**
	 * Write the carried state onto the instance, and answer the instance as written.
	 *
	 * @param array<string, mixed> $instance The case's statutory term instance.
	 * @param CarriedTerm          $carried  The carried state.
	 *
	 * @return array<string, mixed> The instance as written, or as patched when the store answered nothing.
	 */
	private function rewritten(array $instance, CarriedTerm $carried): array {
		$patch = ['countExtensions' => $carried->extensions, 'status' => $carried->status(), 'engineTimerId' => ''];
		if ($carried->deadline !== '') {
			$patch['endDateCurrent'] = $carried->deadline;
		}

		return ($this->terms->updateTermijnInstance(termInstanceId: (string)($instance['id'] ?? ''), patch: $patch) ?? (array_merge($instance, $patch)));
	}//end rewritten()

	/**
	 * Record each carried extension as a verdaging on the instance.
	 *
	 * @param string      $instanceId The instance.
	 * @param CarriedTerm $carried    The carried state, under its definition.
	 *
	 * @return void
	 */
	private function recordExtensions(string $instanceId, CarriedTerm $carried): void {
		$reason = $carried->reason;
		if ($reason === '') {
			$reason = self::REASON;
		}

		for ($i = 0; $i < $carried->extensions; $i++) {
			$this->terms->recordEvent(
				termInstanceId: $instanceId,
				type: 'verdaging',
				basis: (string)($carried->definitie['legalBasis'] ?? ''),
				rationale: $reason,
				daysImpact: $carried->days,
			);
		}
	}//end recordExtensions()

	/**
	 * Whether the instance already carries the state, with a timer armed on it.
	 *
	 * @param array<string, mixed> $instance The case's statutory term instance.
	 * @param CarriedTerm          $carried  The carried state.
	 *
	 * @return bool True when nothing needs rewriting.
	 */
	private function unchanged(array $instance, CarriedTerm $carried): bool {
		$current = (string)$this->dates->toCalendarDateOrNull(value: ($instance['endDateCurrent'] ?? null));

		return (string)($instance['status'] ?? '') === $carried->status()
			&& (int)($instance['countExtensions'] ?? 0) === $carried->extensions
			&& ($carried->deadline === '' || $carried->deadline === $current)
			&& (string)($instance['engineTimerId'] ?? '') !== '';
	}//end unchanged()

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
