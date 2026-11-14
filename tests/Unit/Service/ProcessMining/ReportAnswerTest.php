<?php

/**
 * The report answer: the JSON of all views or one, and the CSV download of a phase matrix.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\Csv\CsvWriter;
use OCA\Dossiq\Service\ProcessMining\ReportAnswer;
use OCA\Dossiq\Service\ProcessMining\ReportViews;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\ProcessMining\ReportAnswer
 * @uses \OCA\Dossiq\Service\ProcessMining\ReportViews
 * @uses \OCA\Dossiq\Service\Csv\CsvWriter
 */
class ReportAnswerTest extends TestCase {

	/**
	 * The answer over the real views and writer, with a translator that marks what it translated.
	 *
	 * @return ReportAnswer
	 */
	private function answer(): ReportAnswer {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '['.$text.']');

		return new ReportAnswer(views: new ReportViews(l10n: $l10n), l10n: $l10n, csvWriter: new CsvWriter());
	}//end answer()

	/**
	 * One view keeps only the period, the clock and the truncation flag of the report, then the view's blocks.
	 *
	 * @return void
	 */
	public function testOneViewKeepsOnlyTheReportHeader(): void {
		$report = [
			'period' => '3m',
			'clock' => 'wall-clock',
			'truncated' => false,
			'caseTypes' => [['id' => 'permit']],
			'throughputTrend' => ['2026-01' => 4, '2026-02' => 6],
		];

		$this->assertSame(
			expected: [
				'period' => '3m',
				'clock' => 'wall-clock',
				'truncated' => false,
				'labels' => ['workingHours' => '[Hours, wall clock]', 'wallHours' => '[Wall-clock hours]'],
				'throughput' => [4, 6],
			],
			actual: $this->answer()->json(report: $report, view: 'throughput')
		);
	}//end testOneViewKeepsOnlyTheReportHeader()

	/**
	 * A view on a report without a header still answers the header keys, as null.
	 *
	 * @return void
	 */
	public function testOneViewOnABareReportAnswersNullHeaders(): void {
		$json = $this->answer()->json(report: [], view: 'throughput');

		$this->assertSame(expected: ['period', 'clock', 'truncated', 'labels', 'throughput'], actual: array_keys($json));
		$this->assertNull($json['period']);
		$this->assertNull($json['clock']);
		$this->assertNull($json['truncated']);
		$this->assertSame(expected: [], actual: $json['throughput']);
	}//end testOneViewOnABareReportAnswersNullHeaders()

	/**
	 * Without a view, or with an empty or non-string one, the whole report comes back with every block after it.
	 *
	 * @return void
	 */
	public function testWithoutAViewTheWholeReportAndEveryBlock(): void {
		$report = ['period' => 'all', 'clock' => 'working-calendar', 'caseTypes' => [], 'extra' => 'kept'];

		foreach ([null, '', 5] as $view) {
			$json = $this->answer()->json(report: $report, view: $view);

			$this->assertSame(
				expected: ['period', 'clock', 'caseTypes', 'extra', 'labels', 'summary', 'dwell', 'throughput', 'bottlenecks', 'byAssignee'],
				actual: array_keys($json),
				message: var_export($view, true)
			);
			$this->assertSame(expected: 'kept', actual: $json['extra']);
			$this->assertSame(expected: '[Working hours]', actual: $json['labels']['workingHours']);
		}
	}//end testWithoutAViewTheWholeReportAndEveryBlock()

	/**
	 * The CSV is the statistic's matrix with a byte order mark, a translated header and translated empty cells.
	 *
	 * @return void
	 */
	public function testTheCsvIsTheStatisticsMatrix(): void {
		$report = [
			'phaseDays' => [
				'median' => [
					'columns' => ['Intake', 2026],
					'rows' => [
						['label' => 'Permit', 'values' => [1.5, 'none']],
						['values' => ['=SUM(A1)']],
					],
				],
				'mean' => ['columns' => ['ignored'], 'rows' => []],
			],
		];

		$this->assertSame(
			expected: "\u{FEFF}\"[Case type]\",\"Intake\",\"2026\"\r\n\"Permit\",\"1.5\",\"[none]\"\r\n\"\",\"'=SUM(A1)\"\r\n",
			actual: $this->answer()->csv(report: $report, statistic: 'median')
		);
	}//end testTheCsvIsTheStatisticsMatrix()

	/**
	 * A statistic the report does not hold is the header alone.
	 *
	 * @return void
	 */
	public function testAnAbsentStatisticIsTheHeaderAlone(): void {
		$this->assertSame(
			expected: "\u{FEFF}\"[Case type]\"\r\n",
			actual: $this->answer()->csv(report: ['phaseDays' => []], statistic: 'mean')
		);
	}//end testAnAbsentStatisticIsTheHeaderAlone()
}//end class
