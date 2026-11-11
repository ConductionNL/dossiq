<?php

/**
 * Dossiq DSO decision term on an armed engine timer.
 *
 * An open DSO case (`dsoStatus` submitted or in_handling, with a
 * `deadlineDate`) gets one OpenRegister FlowTimer with three rungs: a
 * warning and a critical rung a number of WORKING days before the deadline,
 * and the breach at the start of the deadline day.
 * {@see \OCA\Dossiq\Listener\DsoDeadlineTimerFiredListener} turns them into
 * the assignee's notification and, on the breach, the overdue marker. This
 * replaces DsoDeadlineJob, which walked every open DSO case once a day.
 *
 * 🔑 TWO THINGS THE JOB DID ARE KEPT, AND ONE IS NOT.
 *  - The breach lands on the deadline day itself: the job counted the
 *    working days in (today, deadline] and called 0 overdue.
 *  - The two lead settings are read as WORKING DAYS, as the job read them,
 *    although their keys say weeks (`dso_deadline_warning_weeks_*`, defaults
 *    14 and 5). Renaming them is a migration of stored settings, not this.
 *  - NOT kept: the job sent the same notification every day while a case
 *    sat in a band. A rung fires once, so the handler is told once per band.
 *
 * The timer's subject is `<case id>:dso`, NOT the case id. Status dwell
 * timers are armed on the case id and cancelled by subject on every status
 * change (StatusDwellTimer), so a DSO timer on the same subject would be
 * cancelled by any status move, and its own supersede would cancel theirs.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dso
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

namespace OCA\Dossiq\Service\Dso;

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
 * Keeps one engine timer per open DSO case in step with the case.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class DsoDeadlineTimer implements EngineTimerOutcomes {

	/**
	 * The metadata source the fired listener recognises the timer by.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-dso';

	/**
	 * The suffix that keeps the timer's subject apart from the case's own timers.
	 *
	 * @var string
	 */
	public const SUBJECT_SUFFIX = ':dso';

	/**
	 * The DSO statuses whose decision term runs.
	 *
	 * @var string[]
	 */
	public const OPEN_STATUSES = ['submitted', 'in_handling'];

	/**
	 * The rung message of the warning band.
	 *
	 * @var string
	 */
	public const MESSAGE_WARNING = 'dso-termijn-waarschuwing';

	/**
	 * The rung message of the critical band.
	 *
	 * @var string
	 */
	public const MESSAGE_CRITICAL = 'dso-termijn-kritiek';

	/**
	 * Outcome: the deadline is today or past; the caller marks it overdue now.
	 *
	 * @var string
	 */
	public const DUE = 'due';

	/**
	 * Build the service.
	 *
	 * @param SettingsService    $settingsService Resolves the engine and the two lead settings.
	 * @param CaseDateNormaliser $dates           Today and the deadline, in the administered zone.
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
	 * Bring the case's DSO timer in step with the case.
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

		$open     = in_array((string) ($case['dsoStatus'] ?? ''), self::OPEN_STATUSES, true);
		$deadline = $this->dates->tryParse($case['deadlineDate'] ?? null);
		if ($open === false || $deadline === null) {
			return $this->termTimer()->cancel(ownerId: $caseId, reason: 'DSO term no longer running') ?? self::CANCELLED;
		}

		return $this->rearm(case: $case, deadline: $deadline);
	}//end sync()

	/**
	 * Replace a running term's timer by one on its current deadline.
	 *
	 * @param array<string, mixed> $case     The case, with an id.
	 * @param DateTimeImmutable    $deadline Its parsed DSO deadline.
	 *
	 * @return string ARMED, DUE, or UNAVAILABLE.
	 */
	private function rearm(array $case, DateTimeImmutable $deadline): string {
		$caseId    = EngineTimer::objectId(object: $case);
		$cancelled = $this->termTimer()->cancel(ownerId: $caseId, reason: 'Superseded by the current DSO deadline');
		if ($cancelled !== null) {
			return $cancelled;
		}

		$today    = $this->dates->today();
		$deadline = $deadline->setTimezone($this->dates->timeZone())->setTime(0, 0, 0);
		if ($deadline <= $today) {
			return self::DUE;
		}

		$window = new TimerWindow(ownerId: $caseId, anchor: $today, slaDays: $this->days->between(from: $today, to: $deadline));
		return $this->arm(case: $case, window: $window);
	}//end rearm()

	/**
	 * Arm the timer.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param TimerWindow          $window Its id, midnight today and the calendar days to the deadline day.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function arm(array $case, TimerWindow $window): string {
		return $this->termTimer()->arm(
			config: fn (): array => $this->termConfig(case: $case, window: $window)->toArray(),
			ownerId: $window->ownerId
		);
	}//end arm()

	/**
	 * The DSO term timer's configuration: the breach and the two lead rungs.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param TimerWindow          $window The timer's window.
	 *
	 * @return TimerConfig
	 */
	private function termConfig(array $case, TimerWindow $window): TimerConfig {
		$warning  = $this->lead(key: 'dso_deadline_warning_weeks_warning', fallback: 14);
		$critical = $this->lead(key: 'dso_deadline_warning_weeks_critical', fallback: 5);

		return new TimerConfig(
			subjectUuid: $window->ownerId.self::SUBJECT_SUFFIX,
			title: 'DSO-beslistermijn '.(string) ($case['identifier'] ?? $window->ownerId),
			window: $window,
			rungs: [
				new EscalationRung(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'dso-termijn-overschreden'),
				new EscalationRung(trigger: 'preBreach', offset: $warning, unit: 'businessDays', message: self::MESSAGE_WARNING),
				new EscalationRung(trigger: 'preBreach', offset: $critical, unit: 'businessDays', message: self::MESSAGE_CRITICAL),
			],
			anchorEvent: 'dso_term_running',
			metadata: ['source' => self::METADATA_SOURCE, 'caseId' => $window->ownerId],
		);
	}//end termConfig()

	/**
	 * A lead setting, read as the job read it.
	 *
	 * @param string $key      The app config key.
	 * @param int    $fallback The value for an unusable setting.
	 *
	 * @return int Working days before the deadline.
	 */
	private function lead(string $key, int $fallback): int {
		$value = (int) $this->settingsService->getConfigValue($key, (string) $fallback);
		if ($value <= 0) {
			return $fallback;
		}

		return $value;
	}//end lead()

	/**
	 * The engine calls for this timer.
	 *
	 * @return EngineTimer
	 */
	private function termTimer(): EngineTimer {
		return new EngineTimer(
			settingsService: $this->settingsService,
			logger: $this->logger,
			ownerKey: 'case',
			armRefused: 'Dossiq DSO: the engine refused the DSO term timer',
			cancelRefused: 'Dossiq DSO: the engine refused to cancel the DSO term timer',
			subjectSuffix: self::SUBJECT_SUFFIX
		);
	}//end termTimer()
}//end class
