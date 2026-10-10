<?php

/**
 * The numbers one queue computation scores with.
 *
 * The thresholds decide the deadline tier, the weights decide the order inside
 * a tier. They come from the admin settings, and a case type may replace the
 * two thresholds. This value object is what `WorkQueueService::scoreItem()`
 * takes, so the scorer stays a pure function: it is handed the numbers, it
 * never reads them.
 *
 * Every bound lives here, and every way in goes through the constructor. The
 * bounds are what keep the tier in charge of the order: the priority and idle
 * parts together can never reach the 250 points between two tier bases, so a
 * case is never lifted above a case one tier up by how it is weighted.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

/**
 * Thresholds and weights for one scoring run.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
final class UrgencyProfile {

	public const DEFAULT_CRITICAL_DAYS = 3;
	public const DEFAULT_WARNING_DAYS = 7;
	public const DEFAULT_PRIORITY_WEIGHT = 10.0;
	public const DEFAULT_IDLE_WEIGHT = 0.5;

	public const MAX_CRITICAL_DAYS = 60;
	public const MAX_WARNING_DAYS = 120;
	public const MAX_PRIORITY_WEIGHT = 50.0;
	public const MAX_IDLE_WEIGHT = 1.5;

	/**
	 * The critical threshold, in working days left.
	 *
	 * @var int
	 */
	public readonly int $criticalDays;

	/**
	 * The warning threshold, in working days left. Never below the critical one.
	 *
	 * @var int
	 */
	public readonly int $warningDays;

	/**
	 * Points per priority step.
	 *
	 * @var float
	 */
	public readonly float $priorityWeight;

	/**
	 * Points per day lying still.
	 *
	 * @var float
	 */
	public readonly float $idleWeight;

	/**
	 * Build a profile from raw values, each read leniently. With no arguments
	 * it is the default profile: today's numbers.
	 *
	 * A value that does not parse as a number reads as its default; a number
	 * out of bounds is clamped. A warning threshold below the critical one
	 * reads as equal to it, which leaves the type without an almost-due band.
	 * The queue must never fail on a stored setting: a strange order is
	 * something an administrator can see and fix, a failing page is not.
	 *
	 * @param mixed $criticalDays   Raw critical threshold.
	 * @param mixed $warningDays    Raw warning threshold.
	 * @param mixed $priorityWeight Raw priority weight.
	 * @param mixed $idleWeight     Raw idle weight.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function __construct(
		mixed $criticalDays = self::DEFAULT_CRITICAL_DAYS,
		mixed $warningDays = self::DEFAULT_WARNING_DAYS,
		mixed $priorityWeight = self::DEFAULT_PRIORITY_WEIGHT,
		mixed $idleWeight = self::DEFAULT_IDLE_WEIGHT,
	) {
		$critical = (int)self::bounded(raw: $criticalDays, default: self::DEFAULT_CRITICAL_DAYS, max: self::MAX_CRITICAL_DAYS, whole: true);
		$warning = (int)self::bounded(raw: $warningDays, default: self::DEFAULT_WARNING_DAYS, max: self::MAX_WARNING_DAYS, whole: true);

		$this->criticalDays = $critical;
		$this->warningDays = max($warning, $critical);
		$this->priorityWeight = self::bounded(raw: $priorityWeight, default: self::DEFAULT_PRIORITY_WEIGHT, max: self::MAX_PRIORITY_WEIGHT, whole: false);
		$this->idleWeight = self::bounded(raw: $idleWeight, default: self::DEFAULT_IDLE_WEIGHT, max: self::MAX_IDLE_WEIGHT, whole: false);
	}//end __construct()

	/**
	 * This profile with a case type's own thresholds, where it sets them.
	 *
	 * Each threshold falls back on its own: a type that sets only the warning
	 * threshold keeps the instance's critical one.
	 *
	 * @param mixed $criticalDays The case type's `queueCriticalDays`, or null/empty.
	 * @param mixed $warningDays  The case type's `queueWarningDays`, or null/empty.
	 *
	 * @return self The profile for cases of that type.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function withCaseTypeThresholds(mixed $criticalDays, mixed $warningDays): self {
		$critical = $this->criticalDays;
		if (self::isSet(raw: $criticalDays) === true) {
			$critical = $criticalDays;
		}

		$warning = $this->warningDays;
		if (self::isSet(raw: $warningDays) === true) {
			$warning = $warningDays;
		}

		return new self(
			criticalDays: $critical,
			warningDays: $warning,
			priorityWeight: $this->priorityWeight,
			idleWeight: $this->idleWeight,
		);
	}//end withCaseTypeThresholds()

	/**
	 * The profile as the admin settings page reads it.
	 *
	 * @return array{criticalDays: int, warningDays: int, priorityWeight: float, idleWeight: float}
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function toArray(): array {
		return [
			'criticalDays' => $this->criticalDays,
			'warningDays' => $this->warningDays,
			'priorityWeight' => $this->priorityWeight,
			'idleWeight' => $this->idleWeight,
		];
	}//end toArray()

	/**
	 * Whether a case-type override carries a usable number.
	 *
	 * @param mixed $raw The stored value.
	 *
	 * @return bool True when it is a number.
	 */
	private static function isSet(mixed $raw): bool {
		if ($raw === null || is_bool($raw) === true) {
			return false;
		}

		if (is_string($raw) === true) {
			$raw = trim($raw);
		}

		return is_numeric($raw);
	}//end isSet()

	/**
	 * Read one raw value into its bounds.
	 *
	 * @param mixed $raw     The stored value.
	 * @param float $default The default when it does not parse.
	 * @param float $max     The upper bound; the lower bound is 0.
	 * @param bool  $whole   Whether the value is a whole number of days.
	 *
	 * @return float The bounded value.
	 */
	private static function bounded(mixed $raw, float $default, float $max, bool $whole): float {
		if (self::isSet(raw: $raw) === false) {
			return $default;
		}

		if (is_string($raw) === true) {
			$raw = trim($raw);
		}

		$value = (float)$raw;
		if (is_finite($value) === false) {
			return $default;
		}

		if ($whole === true) {
			$value = floor($value);
		}

		return min(max($value, 0.0), $max);
	}//end bounded()
}//end class
