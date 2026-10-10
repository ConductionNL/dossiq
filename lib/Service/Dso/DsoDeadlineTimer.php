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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps one engine timer per open DSO case in step with the case.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class DsoDeadlineTimer {

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
	 * Outcome: a timer is armed.
	 *
	 * @var string
	 */
	public const ARMED = 'armed';

	/**
	 * Outcome: the case is no longer open for DSO and its timer is cancelled.
	 *
	 * @var string
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Outcome: the deadline is today or past; the caller marks it overdue now.
	 *
	 * @var string
	 */
	public const DUE = 'due';

	/**
	 * Outcome: nothing to time.
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
	 * Build the service.
	 *
	 * @param SettingsService    $settingsService Resolves the engine and the two lead settings.
	 * @param CaseDateNormaliser $dates           Today and the deadline, in the administered zone.
	 * @param LoggerInterface    $logger          Logs engine refusals.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
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
		$caseId = (string) ($case['id'] ?? ($case['uuid'] ?? ($case['@self']['id'] ?? '')));
		if ($caseId === '') {
			return self::SKIPPED;
		}

		$open     = in_array((string) ($case['dsoStatus'] ?? ''), self::OPEN_STATUSES, true);
		$deadline = $this->dates->tryParse($case['deadlineDate'] ?? null);
		if ($open === false || $deadline === null) {
			return $this->cancel(caseId: $caseId, reason: 'DSO term no longer running') ?? self::CANCELLED;
		}

		$cancelled = $this->cancel(caseId: $caseId, reason: 'Superseded by the current DSO deadline');
		if ($cancelled !== null) {
			return $cancelled;
		}

		$today    = $this->dates->today();
		$deadline = $deadline->setTimezone($this->dates->timeZone())->setTime(0, 0, 0);
		if ($deadline <= $today) {
			return self::DUE;
		}

		return $this->arm(case: $case, caseId: $caseId, anchor: $today, slaDays: (int) $today->diff($deadline)->days);
	}//end sync()

	/**
	 * Arm the timer.
	 *
	 * @param array<string, mixed> $case    The case.
	 * @param string               $caseId  Its id.
	 * @param DateTimeImmutable    $anchor  Midnight today.
	 * @param int                  $slaDays Calendar days to the deadline day.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function arm(array $case, string $caseId, DateTimeImmutable $anchor, int $slaDays): string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		$config = [
			'subjectType'     => 'object',
			'subjectUuid'     => $caseId.self::SUBJECT_SUFFIX,
			'appId'           => 'dossiq',
			'title'           => 'DSO-beslistermijn '.(string) ($case['identifier'] ?? $caseId),
			'purpose'         => 'due',
			'legalEffect'     => 'none',
			'sla'             => ['value' => $slaDays, 'unit' => 'calendarDays'],
			'escalationRules' => [
				$this->rule(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'dso-termijn-overschreden'),
				$this->rule(trigger: 'preBreach', offset: $this->lead(key: 'dso_deadline_warning_weeks_warning', fallback: 14), unit: 'businessDays', message: self::MESSAGE_WARNING),
				$this->rule(trigger: 'preBreach', offset: $this->lead(key: 'dso_deadline_warning_weeks_critical', fallback: 5), unit: 'businessDays', message: self::MESSAGE_CRITICAL),
			],
			'anchorEvent'     => 'dso_term_running',
			'anchorEventAt'   => $anchor,
			'metadata'        => [
				'source' => self::METADATA_SOURCE,
				'caseId' => $caseId,
			],
		];

		try {
			$engine->arm(config: $config, actor: null);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq DSO: the engine refused the DSO term timer', ['case' => $caseId, 'error' => $e->getMessage()]);
			return self::UNAVAILABLE;
		}

		return self::ARMED;
	}//end arm()

	/**
	 * One escalation rule.
	 *
	 * @param string $trigger slaBreached or preBreach.
	 * @param int    $offset  The offset.
	 * @param string $unit    The offset unit.
	 * @param string $message The message identity.
	 *
	 * @return array<string, mixed>
	 */
	private function rule(string $trigger, int $offset, string $unit, string $message): array {
		return [
			'trigger'        => $trigger,
			'offset'         => $offset,
			'offsetUnit'     => $unit,
			'notifyRole'     => [],
			'escalateToRole' => [],
			'priority'       => 'normal',
			'message'        => $message,
			'openIncident'   => false,
		];
	}//end rule()

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
	 * Cancel the case's DSO timers (and only those).
	 *
	 * @param string $caseId The case id.
	 * @param string $reason Why.
	 *
	 * @return string|null UNAVAILABLE when the engine is absent or refused, null when done.
	 */
	private function cancel(string $caseId, string $reason): ?string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		try {
			$engine->cancelForSubject(subjectType: 'object', subjectUuid: $caseId.self::SUBJECT_SUFFIX, reason: $reason, actor: null);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq DSO: the engine refused to cancel the DSO term timer', ['case' => $caseId, 'error' => $e->getMessage()]);
			return self::UNAVAILABLE;
		}

		return null;
	}//end cancel()

	/**
	 * The engine, or null when OpenRegister does not ship it.
	 *
	 * @return object|null
	 */
	private function engine(): ?object {
		return $this->settingsService->getOpenRegisterClass(self::ENGINE_CLASS);
	}//end engine()
}//end class
