<?php

/**
 * Per case type and month: closed cases, average days and the share within
 * the deadline, for the processing-time download (doorlooptijd-csv).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Doorlooptijd
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
 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Doorlooptijd;

use DateTimeImmutable;
use OCA\Dossiq\Service\Csv\CsvWriter;
use OCA\Dossiq\Service\Doorlooptijd\CaseTypeThroughputCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see CaseTypeThroughputCalculator::computeMonthlyByCaseType()} and {@see CsvWriter}.
 */
class MonthlyByCaseTypeTest extends TestCase {

	/**
	 * A closed (or, with no end, open) enriched case.
	 *
	 * @param string $caseType The case type id.
	 * @param string|null $end The end date.
	 * @param int|null $days The days it took.
	 * @param string|null $deadline The deadline.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function closed(string $caseType, ?string $end, ?int $days, ?string $deadline): array {
		return [
			'caseType' => $caseType,
			'_isOpen' => $end === null,
			'_endDate' => $end,
			'_throughputDays' => $days,
			'_deadline' => $deadline,
		];
	}//end closed()

	/**
	 * Rows per case type and month inside the period, with average and share.
	 *
	 * @return void
	 */
	public function testGroupsClosedCasesPerCaseTypeAndMonthInsideThePeriod(): void {
		$cases = [
			$this->closed('woo', '2026-09-03', 20, '2026-09-10'),
			$this->closed('woo', '2026-09-20', 30, '2026-09-15'),
			$this->closed('woo', '2026-10-02', 10, null),
			$this->closed('bezwaar', '2026-10-05', 41, '2026-10-30'),
			$this->closed('woo', '2026-06-30', 99, '2026-07-01'),
			$this->closed('woo', null, null, '2026-11-01'),
		];
		$caseTypes = [['id' => 'woo', 'title' => 'Woo-verzoek'], ['id' => 'bezwaar', 'title' => 'Bezwaar']];

		$rows = (new CaseTypeThroughputCalculator())->computeMonthlyByCaseType(
			cases: $cases,
			caseTypes: $caseTypes,
			months: 3,
			today: new DateTimeImmutable('2026-10-20')
		);

		$this->assertSame([
			['caseType' => 'Bezwaar', 'month' => '2026-10', 'closed' => 1, 'avgDays' => 41, 'withinNormPercent' => 100],
			['caseType' => 'Woo-verzoek', 'month' => '2026-09', 'closed' => 2, 'avgDays' => 25, 'withinNormPercent' => 50],
			['caseType' => 'Woo-verzoek', 'month' => '2026-10', 'closed' => 1, 'avgDays' => 10, 'withinNormPercent' => null],
		], $rows);
	}//end testGroupsClosedCasesPerCaseTypeAndMonthInsideThePeriod()

	/**
	 * A cell that starts like a formula is neutralised, quotes are doubled.
	 *
	 * @return void
	 */
	public function testTheCsvWriterQuotesAndNeutralisesFormulas(): void {
		$this->assertSame('"\'=1+1","a ""b""","\'-5","\'@x",""', (new CsvWriter())->line(cells: ['=1+1', 'a "b"', '-5', '@x', null]));
		$this->assertSame("\"A\",\"B\"\r\n\"1\",\"2\"\r\n", (new CsvWriter())->document(header: ['A', 'B'], rows: [[1, 2]]));
	}//end testTheCsvWriterQuotesAndNeutralisesFormulas()
}//end class
