<?php

/**
 * Dossiq TermijnTimerService.
 *
 * The arm mapping between the AWB termijnbewaking and OpenRegister's
 * business-timer engine (flow-business-timers). One `due`/`wettelijk`
 * FlowTimer measures each active TermijnInstance; opschorting maps onto
 * engine suspend/resume; verdaging onto extend/extendWithOverride;
 * completion cancels. The engine decides WHEN, dossiq keeps the domain
 * WHAT (escalation bookkeeping, dwangsom arithmetic, case data).
 *
 * OpenRegister is an optional runtime dependency: every call resolves the
 * engine lazily through {@see SettingsService::getOpenRegisterClass()} and
 * degrades to a logged no-op when it is unavailable, so the domain flow
 * never breaks on an absent engine.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Term\ThresholdShares;
use OCA\Dossiq\Service\Termijn\TermEndRoll;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use Psr\Log\LoggerInterface;

/**
 * Arms, suspends, resumes, extends and cancels engine timers for AWB terms.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
class TermijnTimerService {
	/**
	 * The engine service class, resolved lazily.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\FlowTimerService';

	/**
	 * The engine's working-calendar resolver, resolved lazily.
	 *
	 * @var string
	 */
	public const CALENDAR_SERVICE_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService';

	/**
	 * The engine's business-time calculator, resolved lazily. Its
	 * `businessDays` walk IS the Algemene termijnenwet roll: adding zero
	 * business days from a date returns that date when the calendar calls
	 * it a working day, and the next ordinary day when it does not.
	 *
	 * @var string
	 */
	public const SLA_CALCULATOR_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\SlaCalculator';

	/**
	 * The unit whose walk skips non-working days.
	 *
	 * @var string
	 */
	public const UNIT_BUSINESS_DAYS = 'businessDays';

	/**
	 * The seeded 14/7/2/0 escalation ladder — the same matrix
	 * {@see DeadlineEscalationService::DEFAULT_MATRIX} hardcodes.
	 *
	 * @var string
	 */
	public const LADDER_DEFAULT = 'nl-termijn-default';

	/**
	 * Metadata marker the listener filters on.
	 *
	 * @var string
	 */
	public const METADATA_SOURCE = 'dossiq-termijn';

	/**
	 * Metadata kinds.
	 */
	public const KIND_BESLISTERMIJN = 'beslistermijn';

	public const KIND_HERSTELTERMIJN = 'hersteltermijn';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Lazy OpenRegister access.
	 * @param LoggerInterface $logger Logger.
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param WorkingDayCalculator|null $fallbackCalendar The documented fallback for
	 *        an absent engine; built here when the container does not supply one.
	 * @param TermCalendarGuard|null $calendarGuard Refuses a term whose NAMED calendar
	 *        does not resolve. The container always supplies it; the parameter is
	 *        nullable so a test that builds this service by hand and never names a
	 *        calendar keeps working, which is every such test written before
	 *        REQ-TERM-060.
	 * @param ThresholdShares|null $thresholdShares The rungs a term notifies on, as
	 *        shares of its own length. Defaulted rather than required for the same
	 *        reason as the two above it.
	 * @param WorkingDayRoll|null $roll Counts a term in working days when its
	 *        definition asks for them, and answers null when the calendar is absent.
	 * @param TermEndRoll|null $endRoll The Algemene termijnenwet roll. Defaulted for
	 *        the same reason as the three above it; built here when it was not wired.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly CaseDateNormaliser $dates,
		private readonly ?WorkingDayCalculator $fallbackCalendar = null,
		private readonly ?TermCalendarGuard $calendarGuard = null,
		private readonly ?ThresholdShares $thresholdShares = null,
		private readonly ?WorkingDayRoll $roll = null,
		private readonly ?TermEndRoll $endRoll = null,
	) {
		$this->shares = ($thresholdShares ?? new ThresholdShares());
		$this->ends = ($endRoll ?? new TermEndRoll(
			settingsService: $settingsService,
			logger: $logger,
			fallbackCalendar: $fallbackCalendar,
			calendarGuard: $calendarGuard,
		));
	}//end __construct()

	/**
	 * The Algemene termijnenwet roll, resolved once.
	 *
	 * @var TermEndRoll
	 */
	private TermEndRoll $ends;

	/**
	 * Roll a computed end date onto the first ordinary day, when the term rolls.
	 *
	 * @param DateTimeImmutable $date         The computed end date.
	 * @param bool              $roll         Whether the term declares the roll.
	 * @param string|null       $calendarSlug The calendar named on the term, when any.
	 * @param string|null       $organisation The subject's organisation, when any.
	 *
	 * @return DateTimeImmutable The day the term actually ends on.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The declared roll flag, passed
	 *  through to {@see TermEndRoll::rollTermEnd()}. See that method.
	 *
	 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function rollTermEnd(
		DateTimeImmutable $date,
		bool $roll = true,
		?string $calendarSlug = null,
		?string $organisation = null,
	): DateTimeImmutable {
		return $this->ends->rollTermEnd(
			date: $date,
			roll: $roll,
			calendarSlug: $calendarSlug,
			organisation: $organisation,
		);
	}//end rollTermEnd()

	/**
	 * The call every term site makes: roll this end date if the term declares it.
	 *
	 * @param DateTimeImmutable    $date         The computed end date.
	 * @param array<string, mixed> $definitie    The term definition, when one is known.
	 * @param string|null          $calendarSlug The calendar named on the term, when any.
	 * @param string|null          $organisation The subject's organisation, when any.
	 *
	 * @return DateTimeImmutable The day the term actually ends on.
	 *
	 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function rollTermEndFor(
		DateTimeImmutable $date,
		array $definitie = [],
		?string $calendarSlug = null,
		?string $organisation = null,
	): DateTimeImmutable {
		return $this->ends->rollTermEndFor(
			date: $date,
			definitie: $definitie,
			calendarSlug: $calendarSlug,
			organisation: $organisation,
		);
	}//end rollTermEndFor()



	/**
	 * Resolves a declared ladder's rungs to offsets. Built here when it was
	 * not injected: it computes and reads nothing, so an instance that did not
	 * wire it must not thereby ignore the ladders a case type declares.
	 *
	 * @var ThresholdShares
	 */
	private readonly ThresholdShares $shares;

	/**
	 * Arm the beslistermijn timer for a TermijnInstance.
	 *
	 * Maps the instance onto a `due`/`wettelijk` FlowTimer: SLA in
	 * `calendarDays` spanning startDate to endDateCurrent (so an in-flight
	 * instance arms at its CURRENT deadline, pauses and extensions
	 * included), ladder `nl-termijn-default`, anchored at `case_created`
	 * on the start date, extension ceiling from the TermijnDefinitie. No
	 * onExpiry: reaching the beslistermijn never transitions the case.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param array<string, mixed> $definitie The resolved TermijnDefinitie (may be empty).
	 *
	 * @return string|null The armed timer uuid, or null when the engine is unavailable.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `TermijnService::countingModeOf()` is a
	 *  pure function of the array handed to it: no state, no collaborators, and
	 *  nothing resolved implicitly, so the hidden dependency this rule exists to
	 *  catch is not present. Reading `countingMode` here instead would put the
	 *  rule that decides calendar against working days in two places, and the two
	 *  disagreeing is how a ten day term silently becomes fourteen. Injecting
	 *  TermijnService is not open either: it already depends on this class.
	 */
	public function armBeslistermijn(array $instance, array $definitie): ?string {
		$instanceId = (string)($instance['id'] ?? '');
		$start = $this->dates->tryParse($instance['startDate'] ?? null);
		if ($instanceId === '' || $start === null) {
			return null;
		}

		// The mode the definition declares decides BOTH halves of the SLA. A
		// value counted in calendar days under `unit: businessDays` would give
		// a ten working day term fourteen working days, which is two weeks the
		// case is not entitled to, so the unit never moves without the value.
		$mode = TermijnService::countingModeOf(definitie: $definitie);
		$slaUnit = WorkingDayRoll::UNIT_CALENDAR_DAYS;
		if ($mode === WorkingDayRoll::MODE_WORKING_DAYS) {
			$slaUnit = WorkingDayRoll::UNIT_BUSINESS_DAYS;
		}

		$slaDays = $this->slaDaysFor(instance: $instance, definitie: $definitie, start: $start, mode: $mode);

		$config = [
			'subjectType' => 'object',
			'subjectUuid' => $instanceId,
			'appId' => 'dossiq',
			'title' => 'Beslistermijn ' . (string)($instance['case'] ?? $instanceId),
			'purpose' => 'due',
			'legalEffect' => 'wettelijk',
			'sla' => [
				'value' => $slaDays,
				'unit' => $slaUnit,
			],
			'ladder' => self::LADDER_DEFAULT,
			'extensionMax' => max(1, (int)($definitie['countExtensions'] ?? 1)),
			'anchorEvent' => 'case_created',
			'anchorEventAt' => $start,
			'metadata' => [
				'source' => self::METADATA_SOURCE,
				'kind' => self::KIND_BESLISTERMIJN,
				'termijnInstanceId' => $instanceId,
				'caseId' => (string)($instance['case'] ?? ''),
				'deadlineDefinition' => (string)($instance['deadlineDefinition'] ?? ''),
				'basis' => (string)($definitie['legalBasis'] ?? 'AWB 4:13'),
			],
		];

		// A declared ladder REPLACES the seeded one rather than sitting beside
		// it. Two ladders on one timer is two escalations for one term, and
		// the whole reason a share is resolved here, at the one moment the
		// length of the term is known, is that the engine's ladder stays the
		// ladder. An extension re-arms this timer, so the shares are resolved
		// again from the new length.
		$rules = $this->shares->rulesFor(
			ladder: (array)($definitie['escalationLadder'] ?? []),
			slaDays: $slaDays
		);
		if ($rules !== []) {
			unset($config['ladder']);
			$config['escalationRules'] = $rules;
		}

		return $this->arm(config: $config, context: 'beslistermijn', instanceId: $instanceId);
	}//end armBeslistermijn()

	/**
	 * Arm the advisory hersteltermijn helper timer for a paused instance.
	 *
	 * `legalEffect: none` with a single explicit `slaBreached` rule (message
	 * `pauze-verlopen`), so no ladder rungs fire on a short pause and the
	 * pause-expiry signal survives the daily scan's retirement. The engine
	 * has no single-timer cancel, so a helper outliving an on-time
	 * aanvulling is dropped by the listener's still-paused guard instead.
	 *
	 * A pause reason with a chasing schedule adds one `preBreach` rung per
	 * reminder inside its budget, so the engine fires the reminders on the same
	 * clock it fires the expiry on and dossiq keeps no schedule of its own. The
	 * rungs are advisory in the same way the expiry rung is: the listener
	 * re-reads the instance and lets {@see \OCA\Dossiq\Service\Pause\ChaseSchedule}
	 * decide, so a rung that fires after a resume, or twice, sends nothing.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param int $durationDays The hersteltermijn length in days.
	 * @param array<int, int> $chaseOffsets Days before the pause ends, one per reminder.
	 *
	 * @return string|null The armed timer uuid, or null when the engine is unavailable.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function armHersteltermijn(array $instance, int $durationDays, array $chaseOffsets = []): ?string {
		$instanceId = (string)($instance['id'] ?? '');
		if ($instanceId === '' || $durationDays <= 0) {
			return null;
		}

		$config = [
			'subjectType' => 'object',
			'subjectUuid' => $instanceId,
			'appId' => 'dossiq',
			'title' => 'Hersteltermijn ' . (string)($instance['case'] ?? $instanceId),
			'purpose' => 'due',
			'legalEffect' => 'none',
			'sla' => [
				'value' => $durationDays,
				'unit' => 'calendarDays',
			],
			'escalationRules' => array_merge(
				[
					[
						'trigger' => 'slaBreached',
						'offset' => 0,
						'offsetUnit' => 'calendarDays',
						'notifyRole' => ['handler'],
						'escalateToRole' => [],
						'priority' => 'high',
						'message' => 'pauze-verlopen',
						'openIncident' => false,
					],
				],
				$this->chaseRules(offsets: $chaseOffsets)
			),
			'anchorEvent' => 'hersteltermijn_start',
			'metadata' => [
				'source' => self::METADATA_SOURCE,
				'kind' => self::KIND_HERSTELTERMIJN,
				'termijnInstanceId' => $instanceId,
				'caseId' => (string)($instance['case'] ?? ''),
				'basis' => 'AWB 4:5',
			],
		];

		return $this->arm(config: $config, context: 'hersteltermijn', instanceId: $instanceId);
	}//end armHersteltermijn()

	/**
	 * The engine rules for the reminders on one pause.
	 *
	 * `preBreach` rather than a negative `slaBreached` offset, because
	 * `preBreach:<days>` is the rung shape the fired listener already parses
	 * and a second shape would need a second parser. The message is
	 * `pauze-chase` so a human reading the engine's own log can tell a reminder
	 * from the expiry beside it.
	 *
	 * @param array<int, int> $offsets Days before the pause ends, one per reminder.
	 *
	 * @return array<int, array<string, mixed>> The rules, empty when the reason does not chase.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	private function chaseRules(array $offsets): array {
		$rules = [];
		foreach ($offsets as $offset) {
			$days = (int)$offset;
			if ($days <= 0) {
				continue;
			}

			$rules[] = [
				'trigger' => 'preBreach',
				'offset' => $days,
				'offsetUnit' => 'calendarDays',
				'notifyRole' => [],
				'escalateToRole' => [],
				'priority' => 'normal',
				'message' => 'pauze-chase',
				'openIncident' => false,
			];
		}//end foreach

		return $rules;
	}//end chaseRules()

	/**
	 * Suspend the beslistermijn timer (opschorting, AWB 4:5).
	 *
	 * The engine banks the consumed budget: while suspended the timer
	 * neither fires nor escalates nor reports overdue, and resuming
	 * re-projects from the unconsumed remainder.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row (carries `engineTimerId`).
	 * @param string $reason The opschorting rationale (recorded as evidence).
	 * @param DateTimeImmutable|null $until The expected pause end, display-only.
	 *
	 * @return bool True when the engine suspended the timer.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function suspendBeslistermijn(array $instance, string $reason, ?DateTimeImmutable $until): bool {
		$timerId = (string)($instance['engineTimerId'] ?? '');
		if ($timerId === '') {
			return false;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return false;
		}

		try {
			$engine->suspend(uuid: $timerId, reason: $reason, until: $until, actor: null, basis: 'Awb 4:5');
			return true;
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'suspend', timerId: $timerId, error: $e);
			return false;
		}
	}//end suspendBeslistermijn()

	/**
	 * Resume the beslistermijn timer after an aanvulling (AWB 4:15).
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row (carries `engineTimerId`).
	 * @param string $reason Why the term resumes.
	 *
	 * @return bool True when the engine resumed the timer.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function resumeBeslistermijn(array $instance, string $reason): bool {
		$timerId = (string)($instance['engineTimerId'] ?? '');
		if ($timerId === '') {
			return false;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return false;
		}

		try {
			$engine->resume(uuid: $timerId, reason: $reason, actor: null);
			return true;
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'resume', timerId: $timerId, error: $e);
			return false;
		}
	}//end resumeBeslistermijn()

	/**
	 * Mirror a verdaging (AWB 4:14) to the engine timer.
	 *
	 * The standard mode uses the engine's bounded `extend()`; the
	 * supervisor mode (AWB 4:14 lid 3) uses the separately authorized
	 * `extendWithOverride()`. Dossiq's own ceiling check has already run:
	 * the domain is authoritative, the engine records the same decision on
	 * the clock.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row (carries `engineTimerId`).
	 * @param int $days How many days the deadline moves.
	 * @param string $rationale The verdaging motivering.
	 * @param bool $supervisor True for the AWB 4:14 lid 3 override path.
	 *
	 * @return bool True when the engine extended the timer.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function extendBeslistermijn(array $instance, int $days, string $rationale, bool $supervisor): bool {
		$timerId = (string)($instance['engineTimerId'] ?? '');
		if ($timerId === '' || $days <= 0) {
			return false;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return false;
		}

		try {
			if ($supervisor === true) {
				$engine->extendWithOverride(uuid: $timerId, amount: $days, unit: 'calendarDays', rationale: $rationale, actor: 'supervisor');
				return true;
			}

			$engine->extend(uuid: $timerId, amount: $days, unit: 'calendarDays', rationale: $rationale, actor: null);
			return true;
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'extend', timerId: $timerId, error: $e);
			return false;
		}
	}//end extendBeslistermijn()

	/**
	 * Cancel every open timer of an instance, in the operation that made
	 * the term terminal.
	 *
	 * @param string $instanceId The TermijnInstance id.
	 * @param string $reason Why, recorded on each timer.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function cancelForInstance(string $instanceId, string $reason): int {
		if ($instanceId === '') {
			return 0;
		}

		$engine = $this->engine();
		if ($engine === null) {
			return 0;
		}

		try {
			return (int)$engine->cancelForSubject(subjectType: 'object', subjectUuid: $instanceId, reason: $reason, actor: null);
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'cancel', timerId: $instanceId, error: $e);
			return 0;
		}
	}//end cancelForInstance()







	/**
	 * Arm one timer, returning the persisted uuid.
	 *
	 * @param array<string, mixed> $config The engine arm configuration.
	 * @param string $context Which term kind, for logging.
	 * @param string $instanceId The instance, for logging.
	 *
	 * @return string|null The timer uuid, or null on an unavailable or refusing engine.
	 */
	private function arm(array $config, string $context, string $instanceId): ?string {
		$engine = $this->engine();
		if ($engine === null) {
			return null;
		}

		try {
			$timer = $engine->arm(config: $config, actor: null);
			$uuid = (string)$timer->getUuid();
			$this->logger->info(
				'Dossiq termijn: engine timer armed',
				['kind' => $context, 'instance' => $instanceId, 'timer' => $uuid]
			);
			return $uuid;
		} catch (\Throwable $e) {
			$this->logFailure(operation: 'arm ' . $context, timerId: $instanceId, error: $e);
			return null;
		}
	}//end arm()

	/**
	 * The SLA in calendar days: start to the CURRENT deadline when known,
	 * else the definition's standard duration.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param array<string, mixed> $definitie The TermijnDefinitie row.
	 * @param DateTimeImmutable $start The term's start.
	 * @param string $mode Which days the term counts, calendar or working.
	 *
	 * @return int Calendar days, at least 1 (the engine refuses 0).
	 */
	private function slaDaysFor(
		array $instance,
		array $definitie,
		DateTimeImmutable $start,
		string $mode = WorkingDayRoll::MODE_CALENDAR_DAYS,
	): int {
		$end = $this->dates->tryParse($instance['endDateCurrent'] ?? null);
		if ($end !== null) {
			// Both sides at day granularity in the administered zone. Reading
			// one of them through the process zone is how a term lost a day
			// between two servers.
			$startDay = $this->dates->parse($this->dates->formatCalendarDate($start), 'startDate');
			$days = $this->spanFor(from: $startDay, to: $end, mode: $mode);
			if ($end >= $startDay && $days !== null && $days > 0) {
				return $days;
			}
		}

		return max(1, (int)($definitie['standardDurationDays'] ?? 1));
	}//end slaDaysFor()

	/**
	 * The span between two dates in one counting mode, or null when working
	 * days were asked for and the organisation calendar did not answer.
	 *
	 * Null rather than the calendar-day span, so the caller falls back to the
	 * DECLARED duration instead of arming a working-day timer with a number
	 * counted the other way.
	 *
	 * @param DateTimeImmutable $from The start.
	 * @param DateTimeImmutable $to   The end.
	 * @param string            $mode The declared mode.
	 *
	 * @return int|null The span.
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	private function spanFor(DateTimeImmutable $from, DateTimeImmutable $to, string $mode): ?int {
		if ($mode !== WorkingDayRoll::MODE_WORKING_DAYS) {
			return (int)$from->diff($to)->days;
		}

		return $this->roll?->daysBetween(from: $from, to: $to, mode: $mode);
	}//end spanFor()


	/**
	 * Resolve the engine, or null when OpenRegister (or the timer stack) is absent.
	 *
	 * @return object|null The FlowTimerService.
	 */
	private function engine(): ?object {
		return $this->settingsService->getOpenRegisterClass(self::ENGINE_CLASS);
	}//end engine()

	/**
	 * Log a degraded engine call. The domain flow proceeds on case data;
	 * the log line is the operator's signal that the clocks diverged.
	 *
	 * @param string $operation Which lifecycle call failed.
	 * @param string $timerId The timer or instance involved.
	 * @param \Throwable $error The failure.
	 *
	 * @return void
	 */
	private function logFailure(string $operation, string $timerId, \Throwable $error): void {
		$this->logger->warning(
			'Dossiq termijn: engine timer call failed, domain flow continues on case data',
			['operation' => $operation, 'id' => $timerId, 'error' => $error->getMessage()]
		);
	}//end logFailure()
}//end class
