<?php

/**
 * Case type versions of one family share one SLA row.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use DateTimeImmutable;
use OCA\Dossiq\Service\Reporting\ProcessingTimeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Family grouping and exclusions in the SLA table, and the trend window's edges.
 *
 * @covers \OCA\Dossiq\Service\Reporting\ProcessingTimeCalculator
 * @uses \OCA\Dossiq\Service\Reporting\SlaTally
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class ProcessingTimeCalculatorFamilyTest extends TestCase {

	/**
	 * A completed record.
	 *
	 * @param string $type  The case type id.
	 * @param string $start The start date.
	 * @param string $end   The end date.
	 *
	 * @return array<string, mixed>
	 */
	private function record(string $type, string $start, string $end): array {
		return ['caseType' => $type, 'startDate' => $start, 'endDate' => $end];
	}//end record()

	/**
	 * Versions of one family are tallied in one row, named after the first version met.
	 *
	 * @return void
	 */
	public function testVersionsOfOneFamilyAreTalliedTogether(): void {
		$types = [
			'permit-v1' => ['name' => 'Permit (old)', 'targetDays' => 10, 'family' => 'permit'],
			'permit-v2' => ['name' => 'Permit', 'targetDays' => 10, 'family' => 'permit'],
		];
		$completed = [
			$this->record('permit-v1', '2026-01-01', '2026-01-04'),
			$this->record('permit-v2', '2026-01-01', '2026-01-12'),
			$this->record('permit-v2', '2026-01-01', '2026-01-11'),
		];

		$result = (new ProcessingTimeCalculator())->slaCompliance(completed: $completed, types: $types);

		$this->assertSame(['overallRate' => 67, 'withinSla' => 2, 'total' => 3, 'excluded' => 0], $result['summary']);
		$this->assertSame(
			[['id' => 'permit-v1', 'name' => 'Permit (old)', 'total' => 3, 'withinSla' => 2, 'rate' => 67, 'avgActual' => 8, 'targetDays' => 10]],
			$result['byType']
		);
	}//end testVersionsOfOneFamilyAreTalliedTogether()

	/**
	 * A type without a target excludes its records; a negative duration counts as zero days.
	 *
	 * @return void
	 */
	public function testUntargetedRecordsAreExcludedAndNegativeDurationsAreZero(): void {
		$types = ['open' => ['name' => 'Open', 'targetDays' => null], 'permit' => ['name' => 'Permit', 'targetDays' => 0]];
		$completed = [
			$this->record('open', '2026-01-01', '2026-01-05'),
			$this->record('permit', '2026-01-10', '2026-01-05'),
		];

		$result = (new ProcessingTimeCalculator())->slaCompliance(completed: $completed, types: $types);

		$this->assertSame(['overallRate' => 100, 'withinSla' => 1, 'total' => 1, 'excluded' => 1], $result['summary']);
		$this->assertSame(0, $result['byType'][0]['avgActual']);
	}//end testUntargetedRecordsAreExcludedAndNegativeDurationsAreZero()

	/**
	 * A trend of zero months still shows the current month; untargeted and unfinished records are skipped.
	 *
	 * @return void
	 */
	public function testATrendShowsAtLeastTheCurrentMonth(): void {
		$types     = ['permit' => ['targetDays' => 10], 'open' => ['targetDays' => null]];
		$completed = [
			$this->record('permit', '2026-06-01', '2026-06-05'),
			$this->record('open', '2026-06-01', '2026-06-05'),
			['caseType' => 'permit', 'startDate' => null, 'endDate' => '2026-06-07'],
		];

		$trend = (new ProcessingTimeCalculator())->monthlyTrend(completed: $completed, types: $types, months: 0, today: new DateTimeImmutable('2026-06-30'));

		$this->assertSame([['month' => '2026-06', 'rate' => 100, 'withinSla' => 1, 'total' => 1]], $trend);
	}//end testATrendShowsAtLeastTheCurrentMonth()

	/**
	 * The throughput window is anchored on the week of the latest end date, not on today.
	 *
	 * @return void
	 */
	public function testThroughputIsAnchoredOnTheLatestEndDate(): void {
		$completed = [
			['endDate' => '2026-03-04T10:00:00+01:00'],
			['endDate' => '2026-03-08'],
			['endDate' => '2026-03-09'],
			['endDate' => ''],
			['endDate' => null],
		];

		$weeks = (new ProcessingTimeCalculator())->weeklyThroughput(completed: $completed, weeks: 2);

		$this->assertSame(
			[
				['weekLabel' => 'W10 2026', 'week' => '2026-W10', 'count' => 2],
				['weekLabel' => 'W11 2026', 'week' => '2026-W11', 'count' => 1],
			],
			$weeks
		);
	}//end testThroughputIsAnchoredOnTheLatestEndDate()
}//end class
