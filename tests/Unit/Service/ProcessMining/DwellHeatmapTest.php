<?php

/**
 * DwellHeatmap unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\ProcessMining\DwellHeatmap;
use PHPUnit\Framework\TestCase;

/**
 * Process analytics shows dwell time as a heatmap: statuses against weeks,
 * each cell the median (REQ-CRS-36, row 10.4).
 *
 * @covers \OCA\Dossiq\Service\ProcessMining\DwellHeatmap
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/process-mining-bottlenecks/spec.md
 */
class DwellHeatmapTest extends TestCase {

	/**
	 * One visit.
	 *
	 * @param string $status    The status id.
	 * @param string $enteredAt When it was entered.
	 * @param float  $hours     The working hours.
	 *
	 * @return array<string, mixed>
	 */
	private function visit(string $status, string $enteredAt, float $hours): array {
		return ['caseId' => 'c', 'statusId' => $status, 'enteredAt' => $enteredAt, 'hours' => $hours * 2, 'workingHours' => $hours, 'open' => false];
	}//end visit()

	/**
	 * Cases that waited far longer in Review in week 12 make that cell the largest, naming its median.
	 *
	 * @return void
	 */
	public function testASlowWeekStandsOut(): void {
		$intervals = [
			$this->visit('review', '2026-03-10T09:00:00+01:00', 8.0),
			$this->visit('review', '2026-03-11T09:00:00+01:00', 12.0),
			$this->visit('review', '2026-03-17T09:00:00+01:00', 90.0),
			$this->visit('review', '2026-03-18T09:00:00+01:00', 110.0),
			$this->visit('review', '2026-03-19T09:00:00+01:00', 100.0),
			$this->visit('intake', '2026-03-17T08:00:00+01:00', 2.0),
			$this->visit('intake', '2026-03-24T08:00:00+01:00', 3.0),
			$this->visit('review', '2026-03-25T09:00:00+01:00', 9.0),
		];
		$stats = [
			['statusId' => 'review', 'statusName' => 'Review'],
			['statusId' => 'intake', 'statusName' => 'Intake'],
		];

		$grid = (new DwellHeatmap())->grid(intervals: $intervals, dwellStats: $stats);

		self::assertSame(['2026-W11', '2026-W12', '2026-W13'], $grid['columns']);
		self::assertSame(
			[
				['statusId' => 'review', 'label' => 'Review', 'values' => [10.0, 100.0, 9.0]],
				['statusId' => 'intake', 'label' => 'Intake', 'values' => [null, 2.0, 3.0]],
			],
			$grid['rows']
		);
	}//end testASlowWeekStandsOut()

	/**
	 * The wall clock is read when an interval carries no working hours; a status only the intervals know keeps its id.
	 *
	 * @return void
	 */
	public function testFallsBackToWallHoursAndUnnamedStatuses(): void {
		$grid = (new DwellHeatmap())->grid(
			intervals: [['statusId' => 'x', 'enteredAt' => '2026-01-05T10:00:00+00:00', 'hours' => 5]],
			dwellStats: []
		);

		self::assertSame(['2026-W02'], $grid['columns']);
		self::assertSame([['statusId' => 'x', 'label' => 'x', 'values' => [5.0]]], $grid['rows']);
	}//end testFallsBackToWallHoursAndUnnamedStatuses()

	/**
	 * A visit without a parseable moment, a status or hours is left out; nothing left is an empty grid.
	 *
	 * @return void
	 */
	public function testSkipsVisitsItCannotPlace(): void {
		$grid = (new DwellHeatmap())->grid(
			intervals: [
				['statusId' => 'a', 'enteredAt' => 'not a date', 'hours' => 1.0],
				['statusId' => 'a', 'hours' => 1.0],
				['statusId' => '', 'enteredAt' => '2026-01-05T10:00:00+00:00', 'hours' => 1.0],
				['statusId' => 'a', 'enteredAt' => '2026-01-05T10:00:00+00:00', 'hours' => 'many'],
			],
			dwellStats: [['statusId' => 'a', 'statusName' => 'A']]
		);

		self::assertSame(['columns' => [], 'rows' => []], $grid);
	}//end testSkipsVisitsItCannotPlace()

	/**
	 * A stat row for a status with no visit in the window gets no row.
	 *
	 * @return void
	 */
	public function testOnlyStatusesWithVisitsGetARow(): void {
		$grid = (new DwellHeatmap())->grid(
			intervals: [$this->visit('b', '2026-01-05T10:00:00+00:00', 4.0)],
			dwellStats: [['statusId' => 'a', 'statusName' => 'A'], ['statusId' => 'b', 'statusName' => 'B']]
		);

		self::assertSame([['statusId' => 'b', 'label' => 'B', 'values' => [4.0]]], $grid['rows']);
	}//end testOnlyStatusesWithVisitsGetARow()
}//end class
