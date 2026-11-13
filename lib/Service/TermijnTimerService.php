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
use OCA\Dossiq\Kernel\Contracts\TermijnTimerServiceInterface;
use OCA\Dossiq\Service\Term\ThresholdShares;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\Termijn\TermEndRoll;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\OpenRegister\Kernel\Contracts\TimerChange;
use OCA\OpenRegister\Kernel\Contracts\TimerSubjectRef;
use Psr\Log\LoggerInterface;

/**
 * Arms, suspends, resumes, extends and cancels engine timers for AWB terms.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
class TermijnTimerService implements TermijnTimerServiceInterface {

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
	 * Metadata kinds.
	 */
	public const KIND_BESLISTERMIJN = 'beslistermijn';

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
	 * Roll a computed end date onto the first ordinary day.
	 *
	 * Always rolls. A term that may or may not roll goes through
	 * {@see rollTermEndFor()}, which reads the declared flag and calls this
	 * only when the term carries it.
	 *
	 * @param DateTimeImmutable $date         The computed end date.
	 * @param string|null       $calendarSlug The calendar named on the term, when any.
	 * @param string|null       $organisation The subject's organisation, when any.
	 *
	 * @return DateTimeImmutable The day the term actually ends on.
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function rollTermEnd(
		DateTimeImmutable $date,
		?string $calendarSlug = null,
		?string $organisation = null,
	): DateTimeImmutable {
		return $this->ends->rollTermEnd(
			date: $date,
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
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
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
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-a-term-is-late-only-from-the-day-after-its-last-day-everywhere-req-ote-05
	 */
	public function armBeslistermijn(array $instance, array $definitie): ?string {
		$instanceId = (string)($instance['id'] ?? '');
		$start = $this->dates->tryParse($instance['startDate'] ?? null);
		if ($instanceId === '' || $start === null) {
			return null;
		}

		$run = new TimerArmRun(instance: $instance, definitie: $definitie, instanceId: $instanceId, start: $start);

		return $this->arm(config: $this->beslistermijnConfig(run: $run), context: 'beslistermijn', instanceId: $instanceId);
	}//end armBeslistermijn()

	/**
	 * The engine configuration of a beslistermijn timer.
	 *
	 * @param TimerArmRun $run The term being armed.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function beslistermijnConfig(TimerArmRun $run): array {
		$config = (new TermTimerFields())->subject(instance: $run->instance, label: 'Beslistermijn') + [
			'legalEffect' => 'wettelijk',
			'sla' => $this->slaFor(run: $run),
			'ladder' => self::LADDER_DEFAULT,
			'extensionMax' => max(1, (int)($run->definitie['countExtensions'] ?? 1)),
			'anchorEvent' => 'case_created',
			'anchorEventAt' => $this->anchorFor(start: $run->start),
			'metadata' => $this->beslistermijnMetadata(run: $run),
		];

		return $this->withDeclaredLadder(config: $config, run: $run);
	}//end beslistermijnConfig()

	/**
	 * The metadata of a beslistermijn timer.
	 *
	 * @param TimerArmRun $run The term being armed.
	 *
	 * @return array<string, string> The metadata.
	 */
	private function beslistermijnMetadata(TimerArmRun $run): array {
		return (new TermTimerFields())->metadata(instance: $run->instance, kind: self::KIND_BESLISTERMIJN) + [
			'deadlineDefinition' => (string)($run->instance['deadlineDefinition'] ?? ''),
			'basis' => (string)($run->definitie['legalBasis'] ?? 'AWB 4:13'),
		];
	}//end beslistermijnMetadata()

	/**
	 * The SLA of a beslistermijn timer, its value and its unit.
	 *
	 * The mode the definition declares decides BOTH halves of the SLA. A
	 * value counted in calendar days under `unit: businessDays` would give
	 * a ten working day term fourteen working days, which is two weeks the
	 * case is not entitled to, so the unit never moves without the value.
	 *
	 * @param TimerArmRun $run The term being armed.
	 *
	 * @return array{value: int, unit: string} The SLA.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `TermDefinitions::countingModeOf()` is a
	 *  pure function of the array handed to it: no state, no collaborators, and
	 *  nothing resolved implicitly, so the hidden dependency this rule exists to
	 *  catch is not present. Reading `countingMode` here instead would put the
	 *  rule that decides calendar against working days in two places, and the two
	 *  disagreeing is how a ten day term silently becomes fourteen. It was
	 *  `TermijnService::countingModeOf()` until the definitions got their own
	 *  class; injecting either is still not open, because TermijnService already
	 *  depends on this one.
	 */
	private function slaFor(TimerArmRun $run): array {
		$mode = TermDefinitions::countingModeOf(definitie: $run->definitie);
		$slaUnit = WorkingDayRoll::UNIT_CALENDAR_DAYS;
		if ($mode === WorkingDayRoll::MODE_WORKING_DAYS) {
			$slaUnit = WorkingDayRoll::UNIT_BUSINESS_DAYS;
		}

		return [
			'value' => $this->slaDaysFor(run: $run, mode: $mode),
			'unit' => $slaUnit,
		];
	}//end slaFor()

	/**
	 * The configuration with the ladder the definition declares, when it declares one.
	 *
	 * A declared ladder REPLACES the seeded one rather than sitting beside
	 * it. Two ladders on one timer is two escalations for one term, and
	 * the whole reason a share is resolved here, at the one moment the
	 * length of the term is known, is that the engine's ladder stays the
	 * ladder. An extension re-arms this timer, so the shares are resolved
	 * again from the new length.
	 *
	 * @param array<string, mixed> $config The configuration with the seeded ladder.
	 * @param TimerArmRun          $run    The term being armed.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function withDeclaredLadder(array $config, TimerArmRun $run): array {
		$rules = $this->ladderRulesFor(run: $run, slaDays: $config['sla']['value']);
		if ($rules !== []) {
			unset($config['ladder']);
			$config['escalationRules'] = $rules;
		}

		return $config;
	}//end withDeclaredLadder()

	/**
	 * The escalation rules of the declared ladder, else the default rungs that fit the term.
	 *
	 * @param TimerArmRun $run     The term being armed.
	 * @param int         $slaDays The term's length.
	 *
	 * @return array<int, array<string, mixed>> The rules, empty when the named ladder fits.
	 */
	private function ladderRulesFor(TimerArmRun $run, int $slaDays): array {
		$rules = $this->shares->rulesFor(
			ladder: (array)($run->definitie['escalationLadder'] ?? []),
			slaDays: $slaDays
		);
		if ($rules === []) {
			$rules = $this->shares->defaultRulesFor(
				startDay: $this->dates->parse($this->dates->formatCalendarDate($run->start), 'startDate'),
				end: $this->dates->tryParse($run->instance['endDateCurrent'] ?? null)
			);
		}

		return $rules;
	}//end ladderRulesFor()

	/**
	 * Where a beslistermijn timer counts from: the start of the day after the
	 * term's start day.
	 *
	 * 🔴 NOT THE START MOMENT. Anchored at the moment a request arrived (say
	 * 14:00) with an SLA spanning to the end date, the timer breached at 14:00
	 * on the term's LAST day, and the fired listener marked the term exceeded
	 * while it still had ten hours to run (REQ-TERM-DAY-001 says a term is
	 * late only from the day after its last day). The Algemene termijnenwet
	 * counts a term from the day after the event that starts it, so anchoring
	 * there with the term's own length breaches at the start of the day after
	 * the end day, and the ladder's shares, computed from the same length, do
	 * not move.
	 *
	 * @param DateTimeImmutable $start The term's start.
	 *
	 * @return DateTimeImmutable The anchor, at midnight in the administered zone.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-a-term-is-late-only-from-the-day-after-its-last-day-everywhere-req-ote-05
	 */
	private function anchorFor(DateTimeImmutable $start): DateTimeImmutable {
		$startDay = $this->dates->parse($this->dates->formatCalendarDate($start), 'startDate');

		return $startDay->setDate(
			(int)$startDay->format('Y'),
			(int)$startDay->format('n'),
			((int)$startDay->format('j') + 1)
		);
	}//end anchorFor()

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
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function armHersteltermijn(array $instance, int $durationDays, array $chaseOffsets = []): ?string {
		$instanceId = (string)($instance['id'] ?? '');
		if ($instanceId === '' || $durationDays <= 0) {
			return null;
		}

		$config = (new TermTimerFields())->subject(instance: $instance, label: 'Hersteltermijn') + [
			'legalEffect' => 'none',
			'sla' => [
				'value' => $durationDays,
				'unit' => 'calendarDays',
			],
			'escalationRules' => (new TermTimerFields())->pauseRules(offsets: $chaseOffsets),
			'anchorEvent' => 'hersteltermijn_start',
			'metadata' => (new TermTimerFields())->metadata(instance: $instance, kind: self::KIND_HERSTELTERMIJN) + ['basis' => 'AWB 4:5'],
		];

		return $this->arm(config: $config, context: 'hersteltermijn', instanceId: $instanceId);
	}//end armHersteltermijn()

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
		$engine = $this->engineFor(timerId: $timerId);
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
		$engine = $this->engineFor(timerId: $timerId);
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
		$engine = null;
		if ($days > 0) {
			$engine = $this->engineFor(timerId: $timerId);
		}

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
		$engine = $this->engineFor(timerId: $instanceId);
		if ($engine === null) {
			return 0;
		}

		try {
			return (int)$engine->cancelForSubject(
				subject: new TimerSubjectRef(type: 'object', uuid: $instanceId),
				change: new TimerChange(reason: $reason, actor: null)
			);
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
	 * @param TimerArmRun $run  The term being armed.
	 * @param string      $mode Which days the term counts, calendar or working.
	 *
	 * @return int Calendar days, at least 1 (the engine refuses 0).
	 */
	private function slaDaysFor(TimerArmRun $run, string $mode): int {
		$end = $this->dates->tryParse($run->instance['endDateCurrent'] ?? null);
		if ($end !== null) {
			// Both sides at day granularity in the administered zone. Reading
			// one of them through the process zone is how a term lost a day
			// between two servers.
			$startDay = $this->dates->parse($this->dates->formatCalendarDate($run->start), 'startDate');
			$days = $this->spanFor(from: $startDay, to: $end, mode: $mode);
			if ($end >= $startDay && $days !== null && $days > 0) {
				return $days;
			}
		}

		return max(1, (int)($run->definitie['standardDurationDays'] ?? 1));
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
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
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
	 * The engine, for a call on one timer: none when the timer is not known.
	 *
	 * @param string $timerId The timer or instance the call is about.
	 *
	 * @return object|null The engine, or null when there is no timer or no engine.
	 */
	private function engineFor(string $timerId): ?object {
		if ($timerId === '') {
			return null;
		}

		return $this->engine();
	}//end engineFor()

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
