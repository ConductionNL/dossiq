<?php

/**
 * The dashboard KPI counters over term instances: counted, met, missed, over
 * their term, and each one's duration in days.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermCaseTypeResolver;
use OCA\Dossiq\Service\Termijn\TermDashboardFigures;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Termijn\TermDashboardFigures
 * @uses   \OCA\Dossiq\Service\Termijn\TermOutcome
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 * @uses   \OCA\Dossiq\Service\Termijn\TermCaseTypeResolver
 */
class TermDashboardFiguresTallyTest extends TestCase {

	/**
	 * The figures, with no store behind them: the tally reads nothing.
	 *
	 * @return TermDashboardFigures
	 */
	private function figures(): TermDashboardFigures {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		return new TermDashboardFigures(settingsService: $settings, caseTypes: new TermCaseTypeResolver(settingsService: $settings));
	}//end figures()

	/**
	 * Every row is counted; completed in time is met, completed late and exceeded are missed, exceeded is an overrun.
	 *
	 * @return void
	 */
	public function testTheCountersFollowEachRowsOutcome(): void {
		$rows = [
			['status' => 'completed', 'voltooiDatum' => '2026-04-10', 'startDate' => '2026-04-01T09:00:00', 'endDateCurrent' => '2026-04-15'],
			['status' => 'completed', 'voltooiDatum' => '2026-04-20', 'startDate' => '2026-04-01', 'endDateCurrent' => '2026-04-15'],
			['status' => 'exceeded', 'startDate' => '2026-04-01', 'endDateCurrent' => '2026-04-11'],
			['status' => 'lopend', 'startDate' => '2026-04-01'],
			['status' => 'paused', 'endDateCurrent' => '2026-05-01'],
		];

		$this->assertSame(
			['total' => 5, 'within' => 1, 'missed' => 2, 'overrun' => 1, 'durations' => [14, 14, 10]],
			$this->figures()->tally(rows: $rows, filters: [])
		);
	}//end testTheCountersFollowEachRowsOutcome()

	/**
	 * A case-type filter counts only that type's rows.
	 *
	 * @return void
	 */
	public function testACaseTypeFilterCountsOnlyThatType(): void {
		$rows = [
			['caseType' => 'woo', 'status' => 'completed', 'startDate' => '2026-04-01', 'endDateCurrent' => '2026-04-03'],
			['caseType' => 'melding', 'status' => 'exceeded'],
			['status' => 'exceeded'],
		];

		$this->assertSame(
			['total' => 1, 'within' => 1, 'missed' => 0, 'overrun' => 0, 'durations' => [2]],
			$this->figures()->tally(rows: $rows, filters: ['caseType' => 'woo'])
		);
		$this->assertSame(
			['total' => 0, 'within' => 0, 'missed' => 0, 'overrun' => 0, 'durations' => []],
			$this->figures()->tally(rows: [], filters: [])
		);
	}//end testACaseTypeFilterCountsOnlyThatType()
}//end class
