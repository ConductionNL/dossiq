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

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps one engine timer per case on the milestone it waits on.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class MilestoneStallTimer {

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
	public const METADATA_SOURCE = 'dossiq-milestone';

	/**
	 * The suffix that keeps the timer's subject apart from the case's own timers.
	 *
	 * @var string
	 */
	public const SUBJECT_SUFFIX = ':milestone';

	/**
	 * Outcome: a timer is armed.
	 *
	 * @var string
	 */
	public const ARMED = 'armed';

	/**
	 * Outcome: the case waits on no milestone and its timer is cancelled.
	 *
	 * @var string
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Outcome: the case is already stalled; the caller tells the assignee now.
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
	 * @param SettingsService     $settingsService Resolves the engine.
	 * @param StalledCaseDetector $detector        Names the milestone a case waits on and its deadline.
	 * @param CaseDateNormaliser  $dates           Today, in the administered zone.
	 * @param LoggerInterface     $logger          Logs engine refusals.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StalledCaseDetector $detector,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
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
		$caseId = (string) ($case['id'] ?? ($case['uuid'] ?? ($case['@self']['id'] ?? '')));
		if ($caseId === '') {
			return self::SKIPPED;
		}

		$waiting  = $this->detector->waitingOn(case: $case);
		$deadline = $this->dates->tryParse($waiting['deadline'] ?? null);
		if ($waiting === null || $deadline === null) {
			return $this->cancel(caseId: $caseId, reason: 'The case waits on no milestone') ?? self::CANCELLED;
		}

		$cancelled = $this->cancel(caseId: $caseId, reason: 'Superseded by the milestone the case waits on now');
		if ($cancelled !== null) {
			return $cancelled;
		}

		$today = $this->dates->today();
		$stall = $deadline->setTimezone($this->dates->timeZone())->setTime(0, 0, 0)->modify('+1 day');
		if ($stall <= $today) {
			return self::DUE;
		}

		return $this->arm(caseId: $caseId, waiting: $waiting, anchor: $today, slaDays: (int) $today->diff($stall)->days);
	}//end sync()

	/**
	 * Arm the timer.
	 *
	 * @param string               $caseId  The case id.
	 * @param array<string, mixed> $waiting The milestone row.
	 * @param DateTimeImmutable    $anchor  Midnight today.
	 * @param int                  $slaDays Calendar days to the day after the deadline.
	 *
	 * @return string ARMED, or UNAVAILABLE.
	 */
	private function arm(string $caseId, array $waiting, DateTimeImmutable $anchor, int $slaDays): string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		$config = [
			'subjectType'     => 'object',
			'subjectUuid'     => $caseId.self::SUBJECT_SUFFIX,
			'appId'           => 'dossiq',
			'title'           => 'Mijlpaal '.(string) ($waiting['milestoneLabel'] ?? ''),
			'purpose'         => 'due',
			'legalEffect'     => 'none',
			'sla'             => ['value' => $slaDays, 'unit' => 'calendarDays'],
			'escalationRules' => [
				[
					'trigger'        => 'slaBreached',
					'offset'         => 0,
					'offsetUnit'     => 'calendarDays',
					'notifyRole'     => [],
					'escalateToRole' => [],
					'priority'       => 'normal',
					'message'        => 'mijlpaal-vertraagd',
					'openIncident'   => false,
				],
			],
			'anchorEvent'     => 'milestone_waiting',
			'anchorEventAt'   => $anchor,
			'metadata'        => [
				'source'              => self::METADATA_SOURCE,
				'caseId'              => $caseId,
				'milestoneIdentifier' => (string) ($waiting['milestoneIdentifier'] ?? ''),
			],
		];

		try {
			$engine->arm(config: $config, actor: null);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq milestone: the engine refused the stall timer', ['case' => $caseId, 'error' => $e->getMessage()]);
			return self::UNAVAILABLE;
		}

		return self::ARMED;
	}//end arm()

	/**
	 * Cancel the case's milestone timers (and only those).
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
			$this->logger->error('Dossiq milestone: the engine refused to cancel the stall timer', ['case' => $caseId, 'error' => $e->getMessage()]);
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
