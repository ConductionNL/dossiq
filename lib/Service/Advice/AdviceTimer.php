<?php

/**
 * Dossiq advice deadline on an armed engine timer.
 *
 * An advice request (`adviesAanvraag`) waiting for its advisor gets one
 * OpenRegister FlowTimer: it fires a reminder rung on the day
 * `advice_reminder_days` before the deadline and a breach rung at the start
 * of the day after it. {@see \OCA\Dossiq\Listener\AdviceTimerFiredListener}
 * turns those into the reminder and the expiry. This replaces
 * AdviceDeadlineJob, which walked every open request once a day: the engine's
 * one sweep decides WHEN, dossiq still decides WHAT.
 *
 * 🔑 THE DAYS ARE THE JOB'S DAYS. The job expired a request on the first day
 * after its deadline (`deadline < today`) and reminded on `deadline -
 * reminderDays`. The timer is anchored at midnight today in the administered
 * zone with an SLA of the days left plus one, so it breaches at exactly that
 * midnight, and the reminder rung sits `reminderDays + 1` before it.
 *
 * Arming SUPERSEDES: the request's open timers are cancelled first, so a
 * moved deadline never leaves the old timer running beside the new one, and
 * running the repair step twice arms one timer, not two.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Advice
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

namespace OCA\Dossiq\Service\Advice;

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
 * Keeps one engine timer per open advice request in step with the request.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class AdviceTimer implements EngineTimerOutcomes {

	/**
	 * The metadata source the fired listener recognises the timer by.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-advice';

	/**
	 * The status of a request still waiting for its advisor.
	 *
	 * @var string
	 */
	public const OPEN_STATUS = 'requested';

	/**
	 * Outcome: the deadline is already past; the caller expires the request.
	 *
	 * @var string
	 */
	public const OVERDUE = 'overdue';

	/**
	 * The statuses that end the wait.
	 *
	 * @var string[]
	 */
	private const CLOSED_STATUSES = ['received', 'expired'];

	/**
	 * The reminder lead the job used when the setting was unusable.
	 *
	 * @var int
	 */
	private const DEFAULT_REMINDER_DAYS = 3;

	/**
	 * Build the service.
	 *
	 * @param SettingsService    $settingsService Resolves the engine and the reminder setting.
	 * @param CaseDateNormaliser $dates           Today, in the administered zone.
	 * @param LoggerInterface    $logger          Logs engine refusals.
	 * @param DayCount           $days            Counts the days between two moments.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * Bring the request's timer in step with the request.
	 *
	 * @param array<string, mixed> $advice The adviesAanvraag object.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function sync(array $advice): string {
		$adviceId = EngineTimer::objectId(object: $advice);
		$status   = (string) ($advice['status'] ?? '');
		if ($adviceId === '') {
			return self::SKIPPED;
		}

		if (in_array($status, self::CLOSED_STATUSES, true) === true) {
			return $this->adviceTimer()->cancel(ownerId: $adviceId, reason: 'Advice request '.$status) ?? self::CANCELLED;
		}

		$deadline = $this->dates->tryParse($advice['deadline'] ?? null);
		if ($status !== self::OPEN_STATUS || $deadline === null) {
			return self::SKIPPED;
		}

		return $this->rearm(advice: $advice, deadline: $deadline);
	}//end sync()

	/**
	 * Replace an open request's timer by one on its current deadline.
	 *
	 * @param array<string, mixed> $advice   The open request, with an id.
	 * @param \DateTimeImmutable   $deadline Its parsed deadline.
	 *
	 * @return string ARMED, OVERDUE, or UNAVAILABLE.
	 */
	private function rearm(array $advice, \DateTimeImmutable $deadline): string {
		$adviceId = EngineTimer::objectId(object: $advice);
		$today    = $this->dates->today();
		$deadline = $deadline->setTimezone($this->dates->timeZone())->setTime(0, 0, 0);
		$daysLeft = $this->days->signed(from: $today, to: $deadline);

		$cancelled = $this->adviceTimer()->cancel(ownerId: $adviceId, reason: 'Superseded by the current deadline');
		if ($cancelled !== null) {
			return $cancelled;
		}

		if ($daysLeft < 0) {
			return self::OVERDUE;
		}

		$window = new TimerWindow(ownerId: $adviceId, anchor: $today, slaDays: ($daysLeft + 1));
		return $this->arm(advice: $advice, window: $window);
	}//end rearm()

	/**
	 * Arm the timer.
	 *
	 * @param array<string, mixed> $advice The request.
	 * @param TimerWindow          $window Its id, midnight today and the days from it to the breach.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function arm(array $advice, TimerWindow $window): string {
		return $this->adviceTimer()->arm(
			config: fn (): array => $this->adviceConfig(advice: $advice, window: $window)->toArray(),
			ownerId: $window->ownerId
		);
	}//end arm()

	/**
	 * The advice timer's configuration: the expiry and, when ahead, the reminder.
	 *
	 * @param array<string, mixed> $advice The request.
	 * @param TimerWindow          $window The timer's window.
	 *
	 * @return TimerConfig
	 */
	private function adviceConfig(array $advice, TimerWindow $window): TimerConfig {
		return new TimerConfig(
			subjectUuid: $window->ownerId,
			title: 'Adviestermijn '.(string) ($advice['subject'] ?? $window->ownerId),
			window: $window,
			rungs: $this->adviceRungs(slaDays: $window->slaDays),
			anchorEvent: 'advice_requested',
			metadata: [
				'source'   => self::METADATA_SOURCE,
				'adviceId' => $window->ownerId,
				'caseId'   => (string) ($advice['case'] ?? ''),
			],
		);
	}//end adviceConfig()

	/**
	 * The expiry rung, and the reminder rung when its day is still ahead.
	 *
	 * @param int $slaDays Days from the anchor to the breach.
	 *
	 * @return array<int, EscalationRung>
	 */
	private function adviceRungs(int $slaDays): array {
		$rungs = [new EscalationRung(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'advies-verlopen', priority: 'medium')];

		// The rung sits reminderDays + 1 before the breach, which is the
		// deadline minus reminderDays. Only when that day is still ahead: the
		// job never reminded a request inside the window, and the engine
		// refuses a rung before its anchor.
		$lead = ($this->reminderDays() + 1);
		if ($lead < $slaDays) {
			$rungs[] = new EscalationRung(trigger: 'preBreach', offset: $lead, unit: 'calendarDays', message: 'advies-herinnering', priority: 'medium');
		}

		return $rungs;
	}//end adviceRungs()

	/**
	 * The reminder lead, read as the job read it.
	 *
	 * @return int Days before the deadline, three when unusable.
	 */
	private function reminderDays(): int {
		$days = (int) $this->settingsService->getConfigValue('advice_reminder_days', (string) self::DEFAULT_REMINDER_DAYS);
		if ($days <= 0) {
			return self::DEFAULT_REMINDER_DAYS;
		}

		return $days;
	}//end reminderDays()

	/**
	 * The engine calls for this timer.
	 *
	 * @return EngineTimer
	 */
	private function adviceTimer(): EngineTimer {
		return new EngineTimer(
			settingsService: $this->settingsService,
			logger: $this->logger,
			ownerKey: 'advice',
			armRefused: 'Dossiq advice: the engine refused the advice timer',
			cancelRefused: 'Dossiq advice: the engine refused to cancel the advice timer'
		);
	}//end adviceTimer()
}//end class
