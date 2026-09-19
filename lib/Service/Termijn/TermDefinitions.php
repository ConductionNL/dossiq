<?php

/**
 * Dossiq term definitions.
 *
 * What a case type's term definitions say: which of them are in force today,
 * which one a case falls back to, whether a term is counted in calendar days
 * or working days, and the end date a declared duration implies.
 *
 * Split out of {@see \OCA\Dossiq\Service\TermijnService}, which was over its
 * complexity ceiling. Reading the definitions is not running a term: a case
 * type can carry several definitions, one per participating organisation,
 * service and priority, and choosing between them is
 * {@see \OCA\Dossiq\Service\Term\TermResolution}'s job. This class is the
 * store they are read from and the arithmetic their duration implies.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * The term definitions a case type carries, and what their duration implies.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
class TermDefinitions {

	use SearchesObjects;

	/**
	 * Per-request definition cache keyed by case type.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Register and schema ids, and the object service.
	 * @param LoggerInterface     $logger          Logger.
	 * @param WorkingDayRoll|null      $roll            Counts a term in working days when its definition asks.
	 * @param TermijnTimerService|null $timer           The one Algemene termijnenwet roll. Absent, an end
	 *        date is answered unrolled, which is what every build without an engine already did.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?WorkingDayRoll $roll = null,
		private readonly ?TermijnTimerService $timer = null,
	) {
	}//end __construct()

	/**
	 * Resolve the active TermijnDefinitie for a zaaktype.
	 *
	 * Version-aware: returns the definition with the latest validFrom that
	 * is <= today, where validUntil is null or > today.
	 *
	 * @param string $caseType Zaaktype slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function activeFor(string $caseType): ?array {
		if (isset($this->cache[$caseType]) === true) {
			return $this->cache[$caseType];
		}

		$active = $this->allActiveFor(caseType: $caseType);
		if (count($active) === 0) {
			return null;
		}

		$this->cache[$caseType] = $active[0];
		return $active[0];
	}//end activeFor()

	/**
	 * EVERY active TermijnDefinitie for a zaaktype, newest validFrom first.
	 *
	 * {@see self::activeFor()} answers the ONE a case type falls back to.
	 * A case type can carry several: one per participating organisation, per
	 * service and per priority, which is what lets one shared case type serve
	 * five municipalities with five agreed norms and no duplication. Choosing
	 * between them is {@see \OCA\Dossiq\Service\Term\TermResolution}'s
	 * job, because the order is a policy and this is the store.
	 *
	 * @param string $caseType The zaaktype slug.
	 *
	 * @return array<int, array<string, mixed>> The active definitions.
	 *
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
	 */
	public function allActiveFor(string $caseType): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseType]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TermDefinitions.allActiveFor lookup failed',
				['caseType' => $caseType, 'error' => $e->getMessage()]
			);
			return [];
		}

		$today = (new DateTimeImmutable())->format('Y-m-d');
		$active = $this->filterActiveDefinities(rows: $rows, today: $today);

		usort(
			$active,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['validFrom'] ?? ''), (string)($a['validFrom'] ?? ''))
		);

		return $active;
	}//end allActiveFor()

	/**
	 * Keep the TermijnDefinitie rows whose validity window covers today.
	 *
	 * @param array<int, array<string, mixed>> $rows Candidate definitions.
	 * @param string $today Today's date as `Y-m-d`.
	 *
	 * @return array<int, array<string, mixed>> The definitions valid today.
	 */
	private function filterActiveDefinities(array $rows, string $today): array {
		$active = [];
		foreach ($rows as $row) {
			$validFrom = (string)($row['validFrom'] ?? '1970-01-01');
			$validUntil = (string)($row['validUntil'] ?? '');
			if ($validFrom <= $today && ($validUntil === '' || $validUntil >= $today)) {
				$active[] = $row;
			}
		}//end foreach

		return $active;
	}//end filterActiveDefinities()

	/**
	 * The counting mode a definition declares.
	 *
	 * Static, because the timer service asks the same question of the same row
	 * and two readings of one declaration is how the badge and the engine come
	 * to count down to different dates. An absent or unknown value reads as
	 * calendar days: every definition written before this property existed
	 * counts them, and an Awb beslistermijn counts them by law.
	 *
	 * @param array<string, mixed> $definitie The definition.
	 *
	 * @return string One of the two modes.
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public static function countingModeOf(array $definitie): string {
		$declared = trim((string)($definitie['countingMode'] ?? ''));

		if ($declared === WorkingDayRoll::MODE_WORKING_DAYS) {
			return WorkingDayRoll::MODE_WORKING_DAYS;
		}

		return WorkingDayRoll::MODE_CALENDAR_DAYS;
	}//end countingModeOf()

	/**
	 * The end date of a term, counted in the mode its definition declares and
	 * rolled off a day the Algemene termijnenwet does not allow a term to end on.
	 *
	 * 🔴 A DEGRADED WORKING-DAY TERM IS LOGGED, NOT SILENTLY SHORTENED. When
	 * the definition asks for working days and the organisation calendar does
	 * not answer, the fallback counts calendar days, which gives the case a
	 * SHORTER term than it is owed: ten working days is fourteen calendar
	 * days, so the applicant loses four. That is the documented degradation
	 * (D-2, the D-7 posture) and it is stated at warning naming the case type,
	 * because a term nobody can see is short is the failure this whole change
	 * exists to end.
	 *
	 * @param DateTimeImmutable    $start     The day the term starts.
	 * @param int                  $days      The declared duration.
	 * @param array<string, mixed> $definitie The definition.
	 *
	 * @return DateTimeImmutable The end date, after the Awt roll.
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public function endDateFor(DateTimeImmutable $start, int $days, array $definitie): DateTimeImmutable {
		return $this->rolled(date: $this->counted(start: $start, days: $days, definitie: $definitie), definitie: $definitie);
	}//end endDateFor()

	/**
	 * The end date the declared duration implies, before the Awt roll.
	 *
	 * @param DateTimeImmutable    $start     The day the term starts.
	 * @param int                  $days      The declared duration.
	 * @param array<string, mixed> $definitie The definition.
	 *
	 * @return DateTimeImmutable The counted end date.
	 */
	private function counted(DateTimeImmutable $start, int $days, array $definitie): DateTimeImmutable {
		$mode = self::countingModeOf(definitie: $definitie);
		if ($mode !== WorkingDayRoll::MODE_WORKING_DAYS || $this->roll === null) {
			return self::plusDays(start: $start, days: $days);
		}

		$computed = $this->roll->endAfter(start: $start, days: $days, mode: $mode);
		if ($computed !== null) {
			return $computed;
		}

		$this->logger->warning(
			'Dossiq termijn: a term declares working days and the organisation calendar did not answer, '
			. 'so its end date was counted in calendar days and the term is SHORTER than it is owed',
			['caseType' => (string)($definitie['caseType'] ?? ''), 'days' => $days]
		);

		return self::plusDays(start: $start, days: $days);
	}//end counted()

	/**
	 * A number of calendar days after a date.
	 *
	 * 🔑 `add()` AND NOT `modify()`, AND THAT IS NOT A STYLE CHOICE.
	 * `modify()` takes an arbitrary string, so its declared return type is
	 * `DateTimeImmutable|false` and every call to it needs either a psalm
	 * suppression for FalsableReturnStatement or a branch on a value that
	 * cannot occur. `add()` takes a `DateInterval` and returns a date, so the
	 * falsehood the suppression was covering up is gone rather than hidden.
	 * Two suppressions came off this class when it arrived.
	 *
	 * A negative duration is subtracted rather than encoded as `P-5D`, which
	 * `DateInterval` refuses to parse.
	 *
	 * @param DateTimeImmutable $start The day to count from.
	 * @param int               $days  The number of calendar days, which may be negative.
	 *
	 * @return DateTimeImmutable The counted date.
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	private static function plusDays(DateTimeImmutable $start, int $days): DateTimeImmutable {
		$interval = new DateInterval('P' . abs($days) . 'D');
		if ($days < 0) {
			return $start->sub($interval);
		}

		return $start->add($interval);
	}//end plusDays()

	/**
	 * THE ALGEMENE TERMIJNENWET ROLL. `+N days` on its own lands a third of
	 * dossiq's terms on a Saturday, a Sunday or a recognised holiday, which Awt
	 * art. 1 says must move to the next ordinary day.
	 *
	 * 🔑 ONE ROLL FOR THE WHOLE APP, AND IT IS THE TIMER SERVICE'S.
	 * `rollTermEndFor()` is the call every other term site makes, it reads the
	 * declared flag itself, and it refuses when a term names a calendar the
	 * engine cannot resolve. A second roll here read the same flag with the
	 * OPPOSITE default for a few hours, which is how two implementations of one
	 * statutory rule start answering different dates for the same case.
	 *
	 * The armed timer inherits the rolled date without a third rule: it derives
	 * its SLA from `endDateCurrent` through
	 * {@see TermijnTimerService::slaDaysFor()}, so one computation decides both
	 * the stored date and the deadline the engine counts to.
	 *
	 * @param DateTimeImmutable    $date      The counted end date.
	 * @param array<string, mixed> $definitie The definition, which names the calendar.
	 *
	 * @return DateTimeImmutable The rolled end date, or the counted one when no engine answers.
	 */
	private function rolled(DateTimeImmutable $date, array $definitie): DateTimeImmutable {
		return ($this->timer?->rollTermEndFor(date: $date, definitie: $definitie) ?? $date);
	}//end rolled()
}//end class
