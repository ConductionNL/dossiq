<?php

/**
 * WeeklyThroughput unit tests.
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

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use OCA\Dossiq\Service\Reporting\WeeklyThroughput;
use PHPUnit\Framework\TestCase;

/**
 * Records closed per ISO week.
 *
 * @covers \OCA\Dossiq\Service\Reporting\WeeklyThroughput
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class WeeklyThroughputTest extends TestCase {

	/**
	 * Each end date counts in the week of its Monday; the weeks end at the latest completion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testEndDatesAreCountedInTheirIsoWeek(): void {
		$rows = (new WeeklyThroughput())->rows(
			completed: [
				['endDate' => '2026-10-05'],
				['endDate' => '2026-10-11T23:59:00'],
				['endDate' => '2026-10-14'],
				['endDate' => '2026-09-01'],
				['endDate' => ''],
				['endDate' => null],
				[],
			],
			weeks: 2
		);

		$this->assertSame(
			expected: [
				['weekLabel' => 'W41 2026', 'week' => '2026-W41', 'count' => 2],
				['weekLabel' => 'W42 2026', 'week' => '2026-W42', 'count' => 1],
			],
			actual: $rows
		);
	}//end testEndDatesAreCountedInTheirIsoWeek()

	/**
	 * Without a single end date there are no weeks at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testNoCompletionsHaveNoWeeks(): void {
		$this->assertSame(expected: [], actual: (new WeeklyThroughput())->rows(completed: [['endDate' => null]]));
	}//end testNoCompletionsHaveNoWeeks()

	/**
	 * The ISO year of a week that starts in December is the year of its Thursday.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAWeekAcrossNewYearCarriesItsIsoYear(): void {
		$rows = (new WeeklyThroughput())->rows(completed: [['endDate' => '2027-01-01']], weeks: 1);

		$this->assertSame(
			expected: [['weekLabel' => 'W53 2026', 'week' => '2026-W53', 'count' => 1]],
			actual: $rows
		);
	}//end testAWeekAcrossNewYearCarriesItsIsoYear()
}//end class
