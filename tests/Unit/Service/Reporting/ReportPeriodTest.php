<?php

/**
 * Locks how a period preset resolves to dates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use DateTimeImmutable;
use OCA\Dossiq\Service\Reporting\ReportPeriod;
use PHPUnit\Framework\TestCase;

/**
 * Month presets start on the first of a month; `all` has no start.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
class ReportPeriodTest extends TestCase {

	/**
	 * Each preset's first day and trend length, on 31 May 2026.
	 *
	 * @return void
	 */
	public function testPresets(): void {
		$periods = new ReportPeriod();
		$today   = new DateTimeImmutable('2026-05-31 15:00');

		$expected = [
			'1m'      => ['2026-05-01', 1],
			'3m'      => ['2026-02-01', 3],
			'6m'      => ['2025-11-01', 6],
			'12m'     => ['2025-05-01', 12],
			'year'    => ['2026-01-01', 5],
			'unknown' => ['2025-05-01', 12],
		];
		foreach ($expected as $preset => [$from, $months]) {
			self::assertSame($from, $periods->from(preset: $preset, today: $today)?->format('Y-m-d'), $preset);
			self::assertSame($months, $periods->trendMonths(preset: $preset, today: $today), $preset);
		}

		self::assertNull($periods->from(preset: 'all', today: $today));
		self::assertSame(24, $periods->trendMonths(preset: 'all', today: $today));
	}//end testPresets()

	/**
	 * Only the listed presets are accepted.
	 *
	 * @return void
	 */
	public function testIsPreset(): void {
		$periods = new ReportPeriod();

		self::assertTrue($periods->isPreset(preset: '12m'));
		self::assertTrue($periods->isPreset(preset: 'all'));
		self::assertFalse($periods->isPreset(preset: '2m'));
		self::assertFalse($periods->isPreset(preset: null));
		self::assertFalse($periods->isPreset(preset: 12));
	}//end testIsPreset()
}//end class
