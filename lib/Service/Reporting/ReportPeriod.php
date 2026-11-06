<?php

/**
 * A dashboard period preset, resolved to dates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

use DateTimeImmutable;

/**
 * Resolves the period presets a report page offers (`1m`, `3m`, `6m`, `12m`,
 * `year`, `all`) into a start date and a trend window.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
class ReportPeriod {

	/**
	 * The presets a report accepts. Anything else reads as the default.
	 *
	 * @var string[]
	 */
	public const PRESETS = ['1m', '3m', '6m', '12m', 'year', 'all'];

	/**
	 * The preset a report reads when none (or an unknown one) is given.
	 *
	 * @var string
	 */
	public const DEFAULT_PRESET = '12m';

	/**
	 * Whether a value names a known preset.
	 *
	 * @param mixed $preset The value as it arrived.
	 *
	 * @return bool True for a known preset.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
	 */
	public function isPreset(mixed $preset): bool {
		return is_string($preset) === true && in_array($preset, self::PRESETS, true) === true;
	}//end isPreset()

	/**
	 * The first day a preset covers, or null for no lower bound.
	 *
	 * Month presets start on the first of the month that many months back, so
	 * `1m` is the current month and `12m` the twelve months before it.
	 *
	 * @param string            $preset The preset key.
	 * @param DateTimeImmutable $today  The reference day.
	 *
	 * @return DateTimeImmutable|null The first covered day, or null for `all`.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
	 */
	public function from(string $preset, DateTimeImmutable $today): ?DateTimeImmutable {
		$firstOfMonth = $today->setTime(hour: 0, minute: 0)->modify(modifier: 'first day of this month');

		return match ($preset) {
			'1m' => $firstOfMonth,
			'3m' => $firstOfMonth->modify(modifier: '-3 months'),
			'6m' => $firstOfMonth->modify(modifier: '-6 months'),
			'year' => $today->setTime(hour: 0, minute: 0)->setDate(year: (int)$today->format('Y'), month: 1, day: 1),
			'all' => null,
			default => $firstOfMonth->modify(modifier: '-12 months'),
		};
	}//end from()

	/**
	 * How many months of trend a preset plots.
	 *
	 * @param string            $preset The preset key.
	 * @param DateTimeImmutable $today  The reference day.
	 *
	 * @return int The month count.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
	 */
	public function trendMonths(string $preset, DateTimeImmutable $today): int {
		return match ($preset) {
			'1m' => 1,
			'3m' => 3,
			'6m' => 6,
			'year' => (int)$today->format('n'),
			'all' => 24,
			default => 12,
		};
	}//end trendMonths()
}//end class
