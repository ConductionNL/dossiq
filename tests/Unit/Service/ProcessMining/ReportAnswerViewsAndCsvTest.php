<?php

/**
 * Locks how the process-mining report is answered as JSON and as CSV.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\Csv\CsvWriter;
use OCA\Dossiq\Service\ProcessMining\ReportAnswer;
use OCA\Dossiq\Service\ProcessMining\ReportViews;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A view answers only its own block plus the report's frame; no view answers everything; the CSV is the phase-days matrix.
 */
#[CoversClass(ReportAnswer::class)]
#[UsesClass(ReportViews::class)]
#[UsesClass(CsvWriter::class)]
final class ReportAnswerViewsAndCsvTest extends TestCase {

	private ReportViews $views;
	private ReportAnswer $answer;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text): string => ['none' => 'geen', 'Case type' => 'Zaaktype'][$text] ?? $text
		);
		$this->views  = new ReportViews(l10n: $l10n);
		$this->answer = new ReportAnswer(views: $this->views, l10n: $l10n);
	}//end setUp()

	/**
	 * A small report.
	 *
	 * @return array<string, mixed>
	 */
	private function report(): array {
		return [
			'period' => ['from' => '2026-01-01', 'to' => '2026-06-30'],
			'clock' => 'wall',
			'truncated' => false,
			'caseTypeFilter' => null,
			'caseTypes' => [
				[
					'id' => 'permit',
					'title' => 'Permit',
					'caseVolume' => 3,
					'reworkPercent' => 0,
					'transitionCount' => 4,
					'dwellTime' => [['statusName' => 'Intake', 'medianHours' => 48]],
					'bottlenecks' => [],
				],
			],
			'phaseDays' => [
				'mean' => [
					'columns' => ['Intake', 'Review'],
					'rows' => [
						['label' => 'Permit', 'values' => [2, 'none']],
						['label' => '=SUM(A1)', 'values' => [1, 3]],
					],
				],
				'median' => [
					'columns' => ['Intake'],
					'rows' => [['label' => 'Permit', 'values' => [1]]],
				],
			],
		];
	}//end report()

	public function testAViewAnswersTheFrameAndItsBlockOnly(): void {
		$report = $this->report();

		$json = $this->answer->json(report: $report, view: 'summary');

		$this->assertSame(
			[
				'period' => $report['period'],
				'clock' => 'wall',
				'truncated' => false,
				...$this->views->shape(report: $report, view: 'summary'),
			],
			$json
		);
		$this->assertArrayNotHasKey('caseTypes', $json);
		$this->assertArrayNotHasKey('phaseDays', $json);
	}//end testAViewAnswersTheFrameAndItsBlockOnly()

	public function testAViewOnAReportWithoutAFrameAnswersNulls(): void {
		$json = $this->answer->json(report: [], view: 'dwell');

		$this->assertNull($json['period']);
		$this->assertNull($json['clock']);
		$this->assertNull($json['truncated']);
	}//end testAViewOnAReportWithoutAFrameAnswersNulls()

	public function testNoViewAnswersTheWholeReportWithEveryBlock(): void {
		$report = $this->report();
		$expected = [...$report, ...$this->views->shape(report: $report, view: null)];

		$this->assertSame($expected, $this->answer->json(report: $report, view: null));
		$this->assertSame($expected, $this->answer->json(report: $report, view: ''));
		$this->assertSame($expected, $this->answer->json(report: $report, view: 5));
	}//end testNoViewAnswersTheWholeReportWithEveryBlock()

	public function testTheCsvIsTheMeanMatrixWithTranslatedNoneAndABom(): void {
		$csv = $this->answer->csv(report: $this->report(), statistic: 'mean');

		$this->assertSame(
			"\u{FEFF}"
			. "\"Zaaktype\",\"Intake\",\"Review\"\r\n"
			. "\"Permit\",\"2\",\"geen\"\r\n"
			. "\"'=SUM(A1)\",\"1\",\"3\"\r\n",
			$csv
		);
	}//end testTheCsvIsTheMeanMatrixWithTranslatedNoneAndABom()

	public function testTheCsvReadsTheAskedStatistic(): void {
		$this->assertSame(
			"\u{FEFF}\"Zaaktype\",\"Intake\"\r\n\"Permit\",\"1\"\r\n",
			$this->answer->csv(report: $this->report(), statistic: 'median')
		);
	}//end testTheCsvReadsTheAskedStatistic()

	public function testACsvWithoutTheMatrixIsTheHeaderOnly(): void {
		$this->assertSame("\u{FEFF}\"Zaaktype\"\r\n", $this->answer->csv(report: [], statistic: 'mean'));
	}//end testACsvWithoutTheMatrixIsTheHeaderOnly()
}//end class
