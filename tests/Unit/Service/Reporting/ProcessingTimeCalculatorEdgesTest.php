<?php

/**
 * Locks the edges of the processing-time histogram.
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

use OCA\Dossiq\Service\Reporting\ProcessingTimeCalculator;
use OCA\Dossiq\Service\Term\DayCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Records without dates, types without a target and a target below zero.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
#[CoversClass(ProcessingTimeCalculator::class)]
#[UsesClass(DayCount::class)]
class ProcessingTimeCalculatorEdgesTest extends TestCase {

	private ProcessingTimeCalculator $calculator;

	/**
	 * A fresh calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calculator = new ProcessingTimeCalculator();
	}//end setUp()

	/**
	 * A completed record without an end date is left out of every bin.
	 *
	 * @return void
	 */
	public function testRecordWithoutEndDateIsNotBinned(): void {
		$types  = ['permit' => ['id' => 'permit', 'slug' => 'permit', 'name' => 'Permit', 'targetDays' => 10]];
		$result = $this->calculator->distribution(
			completed: [
				['caseType' => 'permit', 'startDate' => '2026-06-01', 'endDate' => null],
				['caseType' => 'permit', 'startDate' => '2026-06-01', 'endDate' => '2026-06-03'],
			],
			types: $types,
		);

		self::assertSame(1, array_sum(array_column($result['bins'], 'count')));
		self::assertSame(1, $result['bins'][0]['count']);
		self::assertSame(10, $result['slaTargetDays']);
		self::assertSame('8-14', $result['targetBin']);
	}//end testRecordWithoutEndDateIsNotBinned()

	/**
	 * A record whose type has no target, or is unknown, adds no target, so a
	 * page of such records shows no target line.
	 *
	 * @return void
	 */
	public function testTypesWithoutTargetReportNoTarget(): void {
		$types  = ['open' => ['id' => 'open', 'slug' => 'open', 'name' => 'Open', 'targetDays' => null]];
		$result = $this->calculator->distribution(
			completed: [
				['caseType' => 'open', 'startDate' => '2026-06-01', 'endDate' => '2026-06-20'],
				['caseType' => 'unknown', 'startDate' => '2026-01-01', 'endDate' => '2026-06-01'],
			],
			types: $types,
		);

		self::assertSame([0, 0, 1, 0, 0, 0, 1], array_column($result['bins'], 'count'));
		self::assertNull($result['slaTargetDays']);
		self::assertNull($result['targetBin']);
	}//end testTypesWithoutTargetReportNoTarget()

	/**
	 * Two different targets on one page report no single target.
	 *
	 * @return void
	 */
	public function testMixedTargetsReportNoTarget(): void {
		$types  = [
			'a' => ['id' => 'a', 'slug' => 'a', 'name' => 'A', 'targetDays' => 10],
			'b' => ['id' => 'b', 'slug' => 'b', 'name' => 'B', 'targetDays' => 30],
		];
		$result = $this->calculator->distribution(
			completed: [
				['caseType' => 'a', 'startDate' => '2026-06-01', 'endDate' => '2026-06-02'],
				['caseType' => 'b', 'startDate' => '2026-06-01', 'endDate' => '2026-06-02'],
			],
			types: $types,
		);

		self::assertNull($result['slaTargetDays']);
		self::assertNull($result['targetBin']);
	}//end testMixedTargetsReportNoTarget()

	/**
	 * A day count below every bin falls in the open-ended last bin.
	 *
	 * @return void
	 */
	public function testTargetBelowZeroFallsInTheLastBin(): void {
		$types  = ['odd' => ['id' => 'odd', 'slug' => 'odd', 'name' => 'Odd', 'targetDays' => -3]];
		$result = $this->calculator->distribution(
			completed: [['caseType' => 'odd', 'startDate' => '2026-06-01', 'endDate' => '2026-06-01']],
			types: $types,
		);

		self::assertSame(-3, $result['slaTargetDays']);
		self::assertSame('57+', $result['targetBin']);
	}//end testTargetBelowZeroFallsInTheLastBin()
}//end class
