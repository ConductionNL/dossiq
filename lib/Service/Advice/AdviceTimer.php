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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps one engine timer per open advice request in step with the request.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class AdviceTimer {

	/**
	 * The engine class, resolved lazily so dossiq runs without OpenRegister.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Flow\\Timer\\FlowTimerService';

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
	 * Outcome: a timer is armed.
	 *
	 * @var string
	 */
	public const ARMED = 'armed';

	/**
	 * Outcome: the request is closed and its timers are cancelled.
	 *
	 * @var string
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Outcome: the deadline is already past; the caller expires the request.
	 *
	 * @var string
	 */
	public const OVERDUE = 'overdue';

	/**
	 * Outcome: nothing to time (no id, no deadline, or a status this ignores).
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Outcome: the engine is absent or refused.
	 *
	 * @var string
	 */
	public const UNAVAILABLE = 'unavailable';

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
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
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
		$adviceId = (string) ($advice['id'] ?? ($advice['uuid'] ?? ($advice['@self']['id'] ?? '')));
		$status   = (string) ($advice['status'] ?? '');
		if ($adviceId === '') {
			return self::SKIPPED;
		}

		if (in_array($status, self::CLOSED_STATUSES, true) === true) {
			return $this->cancel(adviceId: $adviceId, reason: 'Advice request '.$status) ?? self::CANCELLED;
		}

		$deadline = $this->dates->tryParse($advice['deadline'] ?? null);
		if ($status !== self::OPEN_STATUS || $deadline === null) {
			return self::SKIPPED;
		}

		$today    = $this->dates->today();
		$deadline = $deadline->setTimezone($this->dates->timeZone())->setTime(0, 0, 0);
		$daysLeft = (int) $today->diff($deadline)->format('%r%a');

		$cancelled = $this->cancel(adviceId: $adviceId, reason: 'Superseded by the current deadline');
		if ($cancelled !== null) {
			return $cancelled;
		}

		if ($daysLeft < 0) {
			return self::OVERDUE;
		}

		return $this->arm(advice: $advice, adviceId: $adviceId, anchor: $today, slaDays: ($daysLeft + 1));
	}//end sync()

	/**
	 * Arm the timer.
	 *
	 * @param array<string, mixed>   $advice   The request.
	 * @param string                 $adviceId Its id.
	 * @param \DateTimeImmutable     $anchor   Midnight today.
	 * @param int                    $slaDays  Days from the anchor to the breach.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function arm(array $advice, string $adviceId, \DateTimeImmutable $anchor, int $slaDays): string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		$rules = [
			[
				'trigger'        => 'slaBreached',
				'offset'         => 0,
				'offsetUnit'     => 'calendarDays',
				'notifyRole'     => [],
				'escalateToRole' => [],
				'priority'       => 'normal',
				'message'        => 'advies-verlopen',
				'openIncident'   => false,
			],
		];

		// The rung sits reminderDays + 1 before the breach, which is the
		// deadline minus reminderDays. Only when that day is still ahead: the
		// job never reminded a request inside the window, and the engine
		// refuses a rung before its anchor.
		$lead = ($this->reminderDays() + 1);
		if ($lead < $slaDays) {
			$rules[] = [
				'trigger'        => 'preBreach',
				'offset'         => $lead,
				'offsetUnit'     => 'calendarDays',
				'notifyRole'     => [],
				'escalateToRole' => [],
				'priority'       => 'normal',
				'message'        => 'advies-herinnering',
				'openIncident'   => false,
			];
		}

		$config = [
			'subjectType'     => 'object',
			'subjectUuid'     => $adviceId,
			'appId'           => 'dossiq',
			'title'           => 'Adviestermijn '.(string) ($advice['subject'] ?? $adviceId),
			'purpose'         => 'due',
			'legalEffect'     => 'none',
			'sla'             => ['value' => $slaDays, 'unit' => 'calendarDays'],
			'escalationRules' => $rules,
			'anchorEvent'     => 'advice_requested',
			'anchorEventAt'   => $anchor,
			'metadata'        => [
				'source'   => self::METADATA_SOURCE,
				'adviceId' => $adviceId,
				'caseId'   => (string) ($advice['case'] ?? ''),
			],
		];

		try {
			$engine->arm(config: $config, actor: null);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq advice: the engine refused the advice timer',
				['advice' => $adviceId, 'error' => $e->getMessage()]
			);
			return self::UNAVAILABLE;
		}

		return self::ARMED;
	}//end arm()

	/**
	 * Cancel the request's open timers.
	 *
	 * @param string $adviceId The request id.
	 * @param string $reason   Why, recorded on each timer.
	 *
	 * @return string|null UNAVAILABLE when the engine is absent or refused, null when done.
	 */
	private function cancel(string $adviceId, string $reason): ?string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		try {
			$engine->cancelForSubject(subjectType: 'object', subjectUuid: $adviceId, reason: $reason, actor: null);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq advice: the engine refused to cancel the advice timer',
				['advice' => $adviceId, 'error' => $e->getMessage()]
			);
			return self::UNAVAILABLE;
		}

		return null;
	}//end cancel()

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
	 * The engine, or null when OpenRegister does not ship it.
	 *
	 * @return object|null
	 */
	private function engine(): ?object {
		return $this->settingsService->getOpenRegisterClass(self::ENGINE_CLASS);
	}//end engine()
}//end class
