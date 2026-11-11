<?php

/**
 * Dossiq milestone stall on an armed engine timer.
 *
 * A case waiting on a milestone gets one OpenRegister FlowTimer that
 * breaches at the start of the day after the milestone's scheduled deadline,
 * the first day StalledCaseDetector calls the case stalled (`daysOverdue > 0`).
 * {@see \OCA\Dossiq\Listener\MilestoneStallTimerFiredListener} then tells the
 * assignee, once. This replaces BottleneckDetectionJob, which scanned every
 * case daily and told the assignee again every day the case stayed stalled.
 *
 * The deadline comes from {@see StalledCaseDetector::waitingOn()}, the same
 * schedule the stalled list reads, so the timer and the list agree on which
 * milestone a case waits on. The subject is `<case id>:milestone`, apart from
 * the status dwell timers armed on the case id.
 *
 * Working days stay dossiq's own calculation here; moving them onto the
 * engine's calendar is task 3.2.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Milestone
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
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Milestone;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\DayCount;
use OCA\Dossiq\Service\Timer\EngineTimer;
use OCA\Dossiq\Service\Timer\EngineTimerOutcomes;
use OCA\Dossiq\Service\Timer\EscalationRung;
use OCA\Dossiq\Service\Timer\TimerConfig;
use OCA\Dossiq\Service\Timer\TimerWindow;
use Psr\Log\LoggerInterface;

/**
 * Keeps one engine timer per case on the milestone it waits on.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class MilestoneStallTimer implements EngineTimerOutcomes {

	/**
	 * The metadata source the fired listener recognises the timer by.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-milestone';

	/**
	 * The suffix that keeps the timer's subject apart from the case's own timers.
	 *
	 * @var string
	 */
	public const SUBJECT_SUFFIX = ':milestone';

	/**
	 * Outcome: the case is already stalled; the caller tells the assignee now.
	 *
	 * @var string
	 */
	public const DUE = 'due';

	/**
	 * Build the service.
	 *
	 * @param SettingsService     $settingsService Resolves the engine.
	 * @param StalledCaseDetector $detector        Names the milestone a case waits on and its deadline.
	 * @param CaseDateNormaliser  $dates           Today, in the administered zone.
	 * @param LoggerInterface     $logger          Logs engine refusals.
	 * @param DayCount            $days            Counts the days between two moments.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StalledCaseDetector $detector,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * Bring the case's milestone timer in step with the case.
	 *
	 * @param array<string, mixed> $case The case object.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function sync(array $case): string {
		$caseId = EngineTimer::objectId(object: $case);
		if ($caseId === '') {
			return self::SKIPPED;
		}

		$waiting  = $this->detector->waitingOn(case: $case);
		$deadline = $this->dates->tryParse($waiting['deadline'] ?? null);
		if ($waiting === null || $deadline === null) {
			return $this->stallTimer()->cancel(ownerId: $caseId, reason: 'The case waits on no milestone') ?? self::CANCELLED;
		}

		return $this->rearm(caseId: $caseId, waiting: $waiting);
	}//end sync()

	/**
	 * Replace the case's milestone timer by one on the milestone it waits on.
	 *
	 * @param string               $caseId  The case id.
	 * @param array<string, mixed> $waiting The milestone row, with a deadline that parses.
	 *
	 * @return string ARMED, DUE, or UNAVAILABLE.
	 */
	private function rearm(string $caseId, array $waiting): string {
		$cancelled = $this->stallTimer()->cancel(ownerId: $caseId, reason: 'Superseded by the milestone the case waits on now');
		if ($cancelled !== null) {
			return $cancelled;
		}

		$today = $this->dates->today();
		$stall = $this->dates->tryParse($waiting['deadline'] ?? null)->setTimezone($this->dates->timeZone())->setTime(0, 0, 0)->modify('+1 day');
		if ($stall <= $today) {
			return self::DUE;
		}

		$window = new TimerWindow(ownerId: $caseId, anchor: $today, slaDays: $this->days->between(from: $today, to: $stall));
		return $this->arm(waiting: $waiting, window: $window);
	}//end rearm()

	/**
	 * Arm the timer.
	 *
	 * @param array<string, mixed> $waiting The milestone row.
	 * @param TimerWindow          $window  The case id, midnight today and the calendar days to the day after the deadline.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function arm(array $waiting, TimerWindow $window): string {
		return $this->stallTimer()->arm(
			config: fn (): array => $this->stallConfig(waiting: $waiting, window: $window)->toArray(),
			ownerId: $window->ownerId
		);
	}//end arm()

	/**
	 * The stall timer's configuration: one rung on the day after the deadline.
	 *
	 * @param array<string, mixed> $waiting The milestone row.
	 * @param TimerWindow          $window  The timer's window.
	 *
	 * @return TimerConfig
	 */
	private function stallConfig(array $waiting, TimerWindow $window): TimerConfig {
		return new TimerConfig(
			subjectUuid: $window->ownerId.self::SUBJECT_SUFFIX,
			title: 'Mijlpaal '.(string) ($waiting['milestoneLabel'] ?? ''),
			window: $window,
			rungs: [new EscalationRung(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'mijlpaal-vertraagd')],
			anchorEvent: 'milestone_waiting',
			metadata: [
				'source'              => self::METADATA_SOURCE,
				'caseId'              => $window->ownerId,
				'milestoneIdentifier' => (string) ($waiting['milestoneIdentifier'] ?? ''),
			],
		);
	}//end stallConfig()

	/**
	 * The engine calls for this timer.
	 *
	 * @return EngineTimer
	 */
	private function stallTimer(): EngineTimer {
		return new EngineTimer(
			settingsService: $this->settingsService,
			logger: $this->logger,
			ownerKey: 'case',
			armRefused: 'Dossiq milestone: the engine refused the stall timer',
			cancelRefused: 'Dossiq milestone: the engine refused to cancel the stall timer',
			subjectSuffix: self::SUBJECT_SUFFIX
		);
	}//end stallTimer()
}//end class
