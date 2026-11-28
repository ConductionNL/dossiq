<?php

/**
 * RecordedReportRead Unit Tests
 *
 * A named case is the scope, else the period with moments read as the
 * tenant's days; a read is recorded with the cases it counted before it is
 * answered; a report that cannot be made or a read that cannot be recorded
 * comes back as the refusal, never as a report.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\RecordedReportRead;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\WooReportReadLogInterface;
use OCA\Dossiq\Kernel\Contracts\WooThroughputReportInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RecordedReportRead.
 *
 * @covers \OCA\Dossiq\Controller\Support\RecordedReportRead
 */
final class RecordedReportReadTest extends TestCase {

	/**
	 * The report every read answers.
	 *
	 * @var array{rows: list<array<string, int|string>>, cases: list<string>, truncated: bool}
	 */
	private const REPORT = ['rows' => [['reviewer' => 'anna', 'day' => '2026-10-02', 'count' => 3]], 'cases' => ['case-1'], 'truncated' => false];

	/**
	 * The scope is the case when one is named, else the period with moments read as days.
	 *
	 * @return void
	 */
	public function testTheScopeIsTheCaseElseThePeriodAsDays(): void {
		$dates = $this->createMock(originalClassName: CaseDateNormaliserInterface::class);
		$dates->method('toCalendarDateOrNull')->willReturnMap([['2026-10-01T22:00:00.000Z', '2026-10-02']]);
		$reads = new RecordedReportRead(
			report: $this->createMock(originalClassName: WooThroughputReportInterface::class),
			readLog: $this->createMock(originalClassName: WooReportReadLogInterface::class),
			dates: $dates,
		);

		$this->assertSame(expected: ['case' => 'case-1'], actual: $reads->scope(caseId: 'case-1', from: '2026-10-01', to: '2026-10-31'));
		$this->assertSame(
			expected: ['from' => '2026-10-02', 'to' => '2026-10-31'],
			actual: $reads->scope(caseId: '', from: '2026-10-01T22:00:00.000Z', to: '2026-10-31')
		);

		$withoutDates = new RecordedReportRead(
			report: $this->createMock(originalClassName: WooThroughputReportInterface::class),
			readLog: $this->createMock(originalClassName: WooReportReadLogInterface::class),
		);
		$this->assertSame(
			expected: ['from' => '2026-10-01T22:00:00.000Z', 'to' => ''],
			actual: $withoutDates->scope(caseId: '', from: '2026-10-01T22:00:00.000Z', to: '')
		);
	}//end testTheScopeIsTheCaseElseThePeriodAsDays()

	/**
	 * A case read and a period read are each recorded with the cases they counted, then answered.
	 *
	 * @return void
	 */
	public function testAReadIsRecordedThenAnswered(): void {
		$report = $this->createMock(originalClassName: WooThroughputReportInterface::class);
		$report->expects($this->once())->method('forCase')->with('case-1')->willReturn(self::REPORT);
		$report->expects($this->once())->method('forPeriod')->with('2026-10-01', '2026-10-31')->willReturn(self::REPORT);
		$readLog = $this->createMock(originalClassName: WooReportReadLogInterface::class);
		$readLog->expects($this->exactly(count: 2))->method('recordThroughputRead')
			->willReturnCallback(
				function (string $readerId, array $scope, array $caseIds): int {
					$this->assertSame(expected: 'anna', actual: $readerId);
					$this->assertSame(expected: ['case-1'], actual: $caseIds);
					return 1;
				}
			);
		$reads = new RecordedReportRead(report: $report, readLog: $readLog);

		$this->assertSame(expected: ['report' => self::REPORT, 'refusal' => null], actual: $reads->read(readerId: 'anna', scope: ['case' => 'case-1']));
		$this->assertSame(
			expected: ['report' => self::REPORT, 'refusal' => null],
			actual: $reads->read(readerId: 'anna', scope: ['from' => '2026-10-01', 'to' => '2026-10-31'])
		);
	}//end testAReadIsRecordedThenAnswered()

	/**
	 * A report that cannot be made, and a read that cannot be recorded, come back as the refusal.
	 *
	 * @return void
	 */
	public function testARefusalComesBackInsteadOfTheReport(): void {
		$report = $this->createMock(originalClassName: WooThroughputReportInterface::class);
		$report->method('forPeriod')->willThrowException(new RefusedException(rule: 'woo-throughput-period', sentence: 'x', status: 400));
		$report->method('forCase')->willReturn(self::REPORT);
		$readLog = $this->createMock(originalClassName: WooReportReadLogInterface::class);
		$readLog->method('recordThroughputRead')
			->willThrowException(new RefusedException(rule: 'woo-throughput-not-recorded', sentence: 'y', status: 503));
		$reads = new RecordedReportRead(report: $report, readLog: $readLog);

		$this->assertSame(
			expected: ['report' => null, 'refusal' => ['rule' => 'woo-throughput-period', 'status' => 400]],
			actual: $reads->read(readerId: 'anna', scope: ['from' => '', 'to' => ''])
		);
		$this->assertSame(
			expected: ['report' => null, 'refusal' => ['rule' => 'woo-throughput-not-recorded', 'status' => 503]],
			actual: $reads->read(readerId: 'anna', scope: ['case' => 'case-1'])
		);
	}//end testARefusalComesBackInsteadOfTheReport()
}//end class
