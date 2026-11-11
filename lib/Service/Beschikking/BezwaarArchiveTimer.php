<?php

/**
 * Dossiq bezwaartermijn on an armed engine timer.
 *
 * An active `bezwaarTrigger` gets one OpenRegister FlowTimer, anchored on
 * the bekendmaking (AWB 6:7: the objection term runs from the day after the
 * beschikking is announced) and breaching at the start of its `archiveDate`,
 * the day BezwaarTermijnJob would first have acted on it
 * (`archiveDate <= today`). {@see \OCA\Dossiq\Listener\BezwaarArchiveTimerFiredListener}
 * then hands the trigger to {@see BezwaarArchiveTrigger}.
 *
 * Arming SUPERSEDES: the trigger's open timers are cancelled first, so a
 * moved bekendmaking or archive date never leaves the old timer running, and
 * the repair step can run on every upgrade. The task's `supersede()` is the
 * engine's in-place variant of the same thing; cancel-then-arm is used here
 * because it is also what the advice timer does, and one shape is easier to
 * read than two.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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

namespace OCA\Dossiq\Service\Beschikking;

use DateTimeImmutable;
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
 * Keeps one engine timer per active bezwaar trigger in step with the trigger.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class BezwaarArchiveTimer implements EngineTimerOutcomes {

	/**
	 * The metadata source the fired listener recognises the timer by.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-bezwaartermijn';

	/**
	 * Outcome: the archive date is today or past; the caller acts now.
	 *
	 * @var string
	 */
	public const DUE = 'due';

	/**
	 * Build the service.
	 *
	 * @param SettingsService    $settingsService Resolves the engine.
	 * @param CaseDateNormaliser $dates           Today and the dates, in the administered zone.
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
	 * Bring the trigger's timer in step with the trigger.
	 *
	 * @param array<string, mixed> $trigger The bezwaarTrigger object.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function sync(array $trigger): string {
		$triggerId = EngineTimer::objectId(object: $trigger);
		if ($triggerId === '') {
			return self::SKIPPED;
		}

		if (($trigger['archiveTriggerActive'] ?? false) !== true) {
			return $this->archiveTimer()->cancel(ownerId: $triggerId, reason: 'Bezwaar trigger switched off') ?? self::CANCELLED;
		}

		$archive = $this->midnight(value: $trigger['archiveDate'] ?? null);
		if ($archive === null) {
			return self::SKIPPED;
		}

		return $this->rearm(trigger: $trigger, archive: $archive);
	}//end sync()

	/**
	 * Replace an active trigger's timer by one breaching on its archive date.
	 *
	 * @param array<string, mixed> $trigger The active trigger, with an id.
	 * @param DateTimeImmutable    $archive Midnight of its archive date.
	 *
	 * @return string ARMED, DUE, or UNAVAILABLE.
	 */
	private function rearm(array $trigger, DateTimeImmutable $archive): string {
		$triggerId = EngineTimer::objectId(object: $trigger);
		$cancelled = $this->archiveTimer()->cancel(ownerId: $triggerId, reason: 'Superseded by the current bekendmaking');
		if ($cancelled !== null) {
			return $cancelled;
		}

		$today = $this->dates->today();
		if ($archive <= $today) {
			return self::DUE;
		}

		// Anchored on the bekendmaking, as AWB 6:7 counts from it. A trigger
		// without a usable one, or with one after its archive date, is
		// anchored today, so the timer still breaches on the archive date.
		$anchor = ($this->bekendmaking(trigger: $trigger, archive: $archive) ?? $today);
		if ($anchor > $today) {
			$anchor = $today;
		}

		$window = new TimerWindow(ownerId: $triggerId, anchor: $anchor, slaDays: $this->days->between(from: $anchor, to: $archive));
		return $this->arm(trigger: $trigger, window: $window);
	}//end rearm()

	/**
	 * Midnight of the trigger's bekendmaking, when it parses and falls before the archive date.
	 *
	 * @param array<string, mixed> $trigger The trigger.
	 * @param DateTimeImmutable    $archive Midnight of its archive date.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function bekendmaking(array $trigger, DateTimeImmutable $archive): ?DateTimeImmutable {
		$announced = $this->midnight(value: $trigger['announcementDate'] ?? null);
		if ($announced === null || $announced >= $archive) {
			return null;
		}

		return $announced;
	}//end bekendmaking()

	/**
	 * Arm the timer.
	 *
	 * @param array<string, mixed> $trigger The trigger.
	 * @param TimerWindow          $window  Its id, midnight of the bekendmaking (or today) and the days to the archive date.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function arm(array $trigger, TimerWindow $window): string {
		return $this->archiveTimer()->arm(
			config: fn (): array => $this->archiveConfig(trigger: $trigger, window: $window)->toArray(),
			ownerId: $window->ownerId
		);
	}//end arm()

	/**
	 * The bezwaartermijn timer's configuration: one rung on the archive date.
	 *
	 * @param array<string, mixed> $trigger The trigger.
	 * @param TimerWindow          $window  The timer's window.
	 *
	 * @return TimerConfig
	 */
	private function archiveConfig(array $trigger, TimerWindow $window): TimerConfig {
		return new TimerConfig(
			subjectUuid: $window->ownerId,
			title: 'Bezwaartermijn '.(string) ($trigger['decisionId'] ?? $window->ownerId),
			window: $window,
			rungs: [new EscalationRung(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'bezwaartermijn-verstreken')],
			anchorEvent: 'bekendmaking',
			metadata: [
				'source'     => self::METADATA_SOURCE,
				'triggerId'  => $window->ownerId,
				'decisionId' => (string) ($trigger['decisionId'] ?? ''),
				'basis'      => 'Awb 6:7',
			],
		);
	}//end archiveConfig()

	/**
	 * A stored date as midnight in the administered zone.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null Midnight, or null when it does not parse.
	 */
	private function midnight(mixed $value): ?DateTimeImmutable {
		$parsed = $this->dates->tryParse($value);
		if ($parsed === null) {
			return null;
		}

		return $parsed->setTimezone($this->dates->timeZone())->setTime(0, 0, 0);
	}//end midnight()

	/**
	 * The engine calls for this timer.
	 *
	 * @return EngineTimer
	 */
	private function archiveTimer(): EngineTimer {
		return new EngineTimer(
			settingsService: $this->settingsService,
			logger: $this->logger,
			ownerKey: 'trigger',
			armRefused: 'Dossiq bezwaar: the engine refused the bezwaartermijn timer',
			cancelRefused: 'Dossiq bezwaar: the engine refused to cancel the bezwaartermijn timer'
		);
	}//end archiveTimer()
}//end class
