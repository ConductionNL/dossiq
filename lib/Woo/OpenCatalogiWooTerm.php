<?php

/**
 * Dossiq term carried over from an opencatalogi Woo request
 *
 * When dossiq takes a Woo request over (decision D1), writing the case binds a
 * fresh P28D statutory term from the receipt moment and arms its timer
 * (DeadlineCaseCreatedListener). That fresh term is right for a request that
 * was never extended or suspended; for one that was, the source already knows
 * a later end date, and the requester was told that date. This class puts the
 * statutory term on the source's date: it cancels the fresh timer, writes the
 * source's end date, extension count and status onto the instance, re-arms
 * through the same arming code every in-flight term uses (REQ-TOT-006, SLA
 * from the start date to the current end) and suspends at once when the source
 * waits on the requester. A source that is already closed completes the term.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;

/**
 * Puts a taken-over Woo case's statutory term on the source's date.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
 */
class OpenCatalogiWooTerm {

	/**
	 * The term definition the Woo case type's statutory term runs under.
	 */
	public const CASE_TYPE_SLUG = 'woo-verzoek';

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
	private const REASON = 'Termijn overgenomen uit opencatalogi bij de overname van het Woo-verzoek';

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
	 * Carry the source's term onto the case just written for it.
	 *
	 * @param string                                                                   $caseId The written case.
	 * @param array{case: array<string, mixed>, open: bool, suspended: bool, deadline: string} $mapped What {@see OpenCatalogiWooCase::fromSource()} answered.
	 *
	 * @return array{outcome: string, instance: string, timer: string} What happened to the clock.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
	 */
	public function carry(string $caseId, array $mapped): array {
		$instance = $this->statutoryInstance(caseId: $caseId);
		if ($instance === null) {
			return ['outcome' => self::MISSING, 'instance' => '', 'timer' => ''];
		}

		$instanceId = (string)($instance['id'] ?? '');
		if (($mapped['open'] ?? false) !== true) {
			// markTermijnCompleted() cancels every open timer of the instance in
			// the same operation (REQ-TOT-001), so the fresh timer goes with it.
			$this->terms->markTermijnCompleted(
				termInstanceId: $instanceId,
				voltooiDatum: $this->dates->tryParse(($mapped['case']['endDate'] ?? null)),
				rationale: self::REASON . ': het verzoek was daar al afgehandeld',
			);

			return ['outcome' => self::COMPLETED, 'instance' => $instanceId, 'timer' => ''];
		}

		$deadline = trim((string)($mapped['deadline'] ?? ''));
		$extensions = max(0, (int)($mapped['case']['extensionCount'] ?? 0));
		$suspended = (($mapped['suspended'] ?? false) === true);
		if ($this->unchanged(instance: $instance, deadline: $deadline, extensions: $extensions, suspended: $suspended) === true) {
			return ['outcome' => self::KEPT, 'instance' => $instanceId, 'timer' => (string)($instance['engineTimerId'] ?? '')];
		}

		return $this->rearm(instance: $instance, deadline: $deadline, extensions: $extensions, suspended: $suspended);
	}//end carry()

	/**
	 * Cancel the fresh timer, write the source's term and arm it again.
	 *
	 * @param array<string, mixed> $instance   The statutory instance.
	 * @param string               $deadline   The source's end date, `Y-m-d`, or ''.
	 * @param int                  $extensions How often the source was extended.
	 * @param bool                 $suspended  Whether the source waits on the requester.
	 *
	 * @return array{outcome: string, instance: string, timer: string}
	 */
	private function rearm(array $instance, string $deadline, int $extensions, bool $suspended): array {
		$instanceId = (string)($instance['id'] ?? '');
		$this->timers->cancelForInstance(instanceId: $instanceId, reason: self::REASON);

		$status = 'lopend';
		if ($extensions > 0) {
			$status = 'verlengd';
		}

		if ($suspended === true) {
			$status = 'paused';
		}

		$patch = ['countExtensions' => $extensions, 'status' => $status, 'engineTimerId' => ''];
		if ($deadline !== '') {
			$patch['endDateCurrent'] = $deadline;
		}

		$written = ($this->terms->updateTermijnInstance(termInstanceId: $instanceId, patch: $patch) ?? (array_merge($instance, $patch)));
		$timerId = $this->timers->armBeslistermijn(
			instance: $written,
			definitie: ($this->terms->getTermijnDefinitie(caseType: self::CASE_TYPE_SLUG) ?? [])
		);
		if ($timerId === null) {
			return ['outcome' => self::NOT_ARMED, 'instance' => $instanceId, 'timer' => ''];
		}

		$this->terms->updateTermijnInstance(
			termInstanceId: $instanceId,
			patch: ['engineTimerId' => $timerId, 'timerBreachesAfterLastDay' => true]
		);

		if ($suspended === true) {
			$written['engineTimerId'] = $timerId;
			$this->timers->suspendBeslistermijn(instance: $written, reason: self::REASON . ': wacht op de verzoeker', until: null);
		}

		return ['outcome' => self::CARRIED, 'instance' => $instanceId, 'timer' => $timerId];
	}//end rearm()

	/**
	 * Whether the fresh term already says what the source says.
	 *
	 * @param array<string, mixed> $instance   The statutory instance.
	 * @param string               $deadline   The source's end date, or ''.
	 * @param int                  $extensions How often the source was extended.
	 * @param bool                 $suspended  Whether the source waits.
	 *
	 * @return bool True when nothing needs to move.
	 */
	private function unchanged(array $instance, string $deadline, int $extensions, bool $suspended): bool {
		$current = (string)$this->dates->toCalendarDateOrNull(value: ($instance['endDateCurrent'] ?? null));

		return $suspended === false
			&& $extensions === 0
			&& ($deadline === '' || $deadline === $current)
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
