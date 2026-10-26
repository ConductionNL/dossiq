<?php

/**
 * Locks what the processing-time report reads and how it answers each block.
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

use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\Reporting\ProcessingTimeCalculator;
use OCA\Dossiq\Service\Reporting\ProcessingTimeReportService;
use OCA\Dossiq\Service\Reporting\ProcessingTimeRows;
use OCA\Dossiq\Service\Reporting\ProcessingTimeSeriesPublisher;
use OCA\Dossiq\Service\Reporting\ReportPeriod;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The report is shaped on the server from one load of records, types and
 * statuses.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ProcessingTimeReportServiceTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The loader double.
	 *
	 * @var ProcessMiningDataLoader&MockObject
	 */
	private ProcessMiningDataLoader $loader;

	/**
	 * The publisher double.
	 *
	 * @var ProcessingTimeSeriesPublisher&MockObject
	 */
	private ProcessingTimeSeriesPublisher $publisher;

	/**
	 * Doubles for the loader and the publisher; a translator that echoes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->loader    = $this->createMock(ProcessMiningDataLoader::class);
		$this->publisher = $this->createMock(ProcessingTimeSeriesPublisher::class);

		$this->loader->method('loadCaseTypes')->willReturn(
			[
				['id' => 'type-permit', 'slug' => 'permit', 'title' => ['en' => 'Permit'], 'processingDeadline' => 'P10D'],
				['id' => 'type-other', 'slug' => 'other', 'title' => 'Other', 'processingDeadline' => 'P30D'],
			]
		);
		$this->loader->method('loadStatusTypes')->willReturn(
			[
				['id' => 'st-open', 'isFinal' => false],
				['id' => 'st-done', 'isFinal' => true],
			]
		);
	}//end setUp()

	/**
	 * The service on a clock frozen at 15 June 2026, 10:00 UTC.
	 *
	 * @param array<int, array<string, mixed>> $records The records the loader answers.
	 *
	 * @return ProcessingTimeReportService
	 */
	private function service(array $records): ProcessingTimeReportService {
		$this->loader->method('loadCases')->willReturn($records);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('n')->willReturnCallback(
			static function (string $singular, string $plural, int $count): string {
				$text = $plural;
				if ($count === 1) {
					$text = $singular;
				}

				return str_replace(search: '%n', replace: (string)$count, subject: $text);
			}
		);

		return new ProcessingTimeReportService(
			loader: $this->loader,
			calculator: new ProcessingTimeCalculator(),
			rows: new ProcessingTimeRows(),
			publisher: $this->publisher,
			dates: $this->caseDatesFrozenAt(instant: '2026-06-15T10:00:00+00:00'),
			periods: new ReportPeriod(),
			l10n: $l10n,
		);
	}//end service()

	/**
	 * Records across both types, statuses and periods.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function records(): array {
		return [
			['id' => 'done-in', 'caseType' => 'type-permit', 'status' => 'st-done', 'startDate' => '2026-06-01', 'endDate' => '2026-06-05T16:00:00+02:00'],
			['id' => 'done-slug', 'caseType' => 'permit', 'status' => 'st-done', 'startDate' => '2026-05-01', 'endDate' => '2026-05-25'],
			['id' => 'done-old', 'caseType' => 'type-permit', 'status' => 'st-done', 'startDate' => '2024-01-01', 'endDate' => '2024-01-05'],
			['id' => 'done-no-end', 'caseType' => 'type-permit', 'status' => 'st-done', 'startDate' => '2026-06-01'],
			['id' => 'ended-not-final', 'caseType' => 'type-other', 'status' => 'st-open', 'startDate' => '2026-01-01', 'endDate' => '2026-02-01', 'title' => 'Still open'],
			['id' => 'late', 'caseType' => 'type-permit', 'status' => 'st-open', 'startDate' => '2026-06-01', 'identifier' => 'P-1', 'title' => 'Late'],
		];
	}//end records()

	/**
	 * Completion needs a final status AND an end date; the period bounds the
	 * compliance figures; a type named by slug counts under its id.
	 *
	 * @return void
	 */
	public function testComplianceReadsFinalRecordsInsideThePeriod(): void {
		$report = $this->service(records: $this->records())->getReport(period: '12m', caseType: null, view: null, threshold: 0.25);

		self::assertSame(['overallRate' => 50, 'withinSla' => 1, 'total' => 2, 'excluded' => 0], $report['slaSummary']);
		self::assertSame('Permit', $report['sla'][0]['name']);
		self::assertSame(['preset' => '12m', 'from' => '2025-06-01', 'to' => '2026-06-15'], $report['period']);
		self::assertFalse($report['truncated']);
	}//end testComplianceReadsFinalRecordsInsideThePeriod()

	/**
	 * Every block is present without a view, labelled for text beside colour.
	 *
	 * @return void
	 */
	public function testEveryBlockIsAnswered(): void {
		$report = $this->service(records: $this->records())->getReport(period: '12m', caseType: null, view: null, threshold: 0.25);

		foreach (ProcessingTimeReportService::VIEWS as $block) {
			self::assertArrayHasKey($block, $report);
		}

		self::assertSame(['ended-not-final', 'late'], array_column($report['atRisk'], 'id'));
		self::assertSame('Overdue', $report['atRisk'][1]['severityLabel']);
		self::assertSame(expected: '4 days late', actual: $report['atRisk'][1]['remainingLabel']);
		self::assertSame('P-1', $report['atRisk'][1]['identifier']);
		self::assertSame(['Permit', 'Other'], array_column($report['breakdown'], 'name'));
		self::assertSame('critical', $report['breakdown'][0]['status']);
		self::assertSame('Critical', $report['breakdown'][0]['statusLabel']);
		self::assertSame('No target', $report['breakdown'][1]['statusLabel']);
	}//end testEveryBlockIsAnswered()

	/**
	 * Days are counted in the tenant's calendar: a start on 1 June is 14 days
	 * before 15 June even though the reference day began at 22:00 UTC.
	 *
	 * @return void
	 */
	public function testElapsedDaysAreCalendarDays(): void {
		$report = $this->service(records: $this->records())->getReport(period: '12m', caseType: null, view: 'atRisk', threshold: 0.25);

		$late = array_column($report['atRisk'], null, 'id')['late'];

		self::assertSame(14, $late['elapsedDays']);
		self::assertSame(-4, $late['remainingDays']);
	}//end testElapsedDaysAreCalendarDays()

	/**
	 * A view answers only its block, plus the period and truncation notice.
	 *
	 * @return void
	 */
	public function testViewNarrowsTheAnswer(): void {
		$report = $this->service(records: $this->records())->getReport(period: '12m', caseType: null, view: 'distribution', threshold: 0.25);

		self::assertSame(['period', 'caseType', 'truncated', 'distribution', 'distributionTarget'], array_keys($report));
		self::assertSame(2, array_sum(array_column($report['distribution'], 'count')));
		self::assertSame(['days' => 10, 'bin' => '8-14', 'label' => 'SLA Target: 10d'], $report['distributionTarget']);
	}//end testViewNarrowsTheAnswer()

	/**
	 * An empty histogram is an empty list, so the chart shows its empty state.
	 *
	 * @return void
	 */
	public function testEmptyHistogramIsEmpty(): void {
		$report = $this->service(records: [])->getReport(period: '12m', caseType: null, view: 'distribution', threshold: 0.25);

		self::assertSame([], $report['distribution']);
		self::assertNull($report['distributionTarget']);
	}//end testEmptyHistogramIsEmpty()

	/**
	 * A type filter given as a slug narrows to that type's records, and the
	 * narrowed figures are not published as the organisation's series.
	 *
	 * @return void
	 */
	public function testCaseTypeFilterBySlug(): void {
		$this->publisher->expects(self::never())->method('publish');

		$report = $this->service(records: $this->records())->getReport(period: 'all', caseType: 'other', view: null, threshold: 1.0);

		self::assertSame('type-other', $report['caseType']);
		self::assertSame(['ended-not-final'], array_column($report['atRisk'], 'id'));
		self::assertSame([], $report['sla']);
	}//end testCaseTypeFilterBySlug()

	/**
	 * A filter by identifier covers every version of the type: the Woo terms
	 * table asks for "woo-verzoek" and the open cases sit on version 2, whose
	 * OpenRegister slug is "woo-verzoek-v2".
	 *
	 * @return void
	 */
	public function testCaseTypeFilterByIdentifierCoversEveryVersion(): void {
		$this->loader = $this->createMock(originalClassName: ProcessMiningDataLoader::class);
		$this->loader->method('loadCaseTypes')->willReturn(
			[
				['id' => 'woo-1', 'identifier' => 'woo-verzoek', '@self' => ['slug' => 'woo-verzoek'], 'title' => 'Woo-verzoek', 'processingDeadline' => 'P28D'],
				[
					'id'                 => 'woo-2',
					'identifier'         => 'woo-verzoek',
					'@self'              => ['slug' => 'woo-verzoek-v2'],
					'title'              => 'Woo-verzoek',
					'processingDeadline' => 'P28D',
				],
				['id' => 'type-other', 'identifier' => 'other', 'title' => 'Other', 'processingDeadline' => 'P30D'],
			]
		);
		$this->loader->method('loadStatusTypes')->willReturn([['id' => 'st-open', 'isFinal' => false]]);

		$report = $this->service(
			records: [
				['id' => 'woo-open', 'caseType' => 'woo-2', 'status' => 'st-open', 'startDate' => '2026-06-01', 'title' => 'Woo v2'],
				['id' => 'woo-old', 'caseType' => 'woo-1', 'status' => 'st-open', 'startDate' => '2026-06-01', 'title' => 'Woo v1'],
				['id' => 'other-open', 'caseType' => 'type-other', 'status' => 'st-open', 'startDate' => '2026-06-01'],
			]
		)->getReport(period: 'all', caseType: 'woo-verzoek', view: 'atRisk', threshold: 1.0);

		$ids = array_column(array: $report['atRisk'], column_key: 'id');
		sort(array: $ids);
		self::assertSame(expected: ['woo-old', 'woo-open'], actual: $ids);
	}//end testCaseTypeFilterByIdentifierCoversEveryVersion()

	/**
	 * One day is singular: "1 day late", not "1 days late" (DqDoorlooptijd
	 * Wettelijke Woo-termijnen read "1 dagen te laat").
	 *
	 * @return void
	 */
	public function testOneDayIsSingular(): void {
		$report = $this->service(
			records: [['id' => 'one-late', 'caseType' => 'type-permit', 'status' => 'st-open', 'startDate' => '2026-06-04']]
		)->getReport(period: '12m', caseType: null, view: 'atRisk', threshold: 1.0);

		self::assertSame(expected: '1 day late', actual: $report['atRisk'][0]['remainingLabel']);
	}//end testOneDayIsSingular()

	/**
	 * The breakdown has one row per case type, not per version: Woo-verzoek
	 * v1 and v2 share an identifier and read as one row with their records
	 * together.
	 *
	 * @return void
	 */
	public function testBreakdownHasOneRowPerCaseTypeNotPerVersion(): void {
		$this->loader = $this->createMock(originalClassName: ProcessMiningDataLoader::class);
		$this->loader->method('loadCaseTypes')->willReturn(
			[
				['id' => 'woo-1', 'identifier' => 'woo-verzoek', 'title' => 'Woo-verzoek', 'processingDeadline' => 'P28D'],
				['id' => 'woo-2', 'identifier' => 'woo-verzoek', 'title' => 'Woo-verzoek', 'processingDeadline' => 'P28D'],
				['id' => 'type-other', 'identifier' => 'other', 'title' => 'Other', 'processingDeadline' => 'P30D'],
			]
		);
		$this->loader->method('loadStatusTypes')->willReturn([['id' => 'st-done', 'isFinal' => true]]);

		$report = $this->service(
			records: [
				['id' => 'w1', 'caseType' => 'woo-1', 'status' => 'st-done', 'startDate' => '2026-06-01', 'endDate' => '2026-06-05'],
				['id' => 'w2', 'caseType' => 'woo-2', 'status' => 'st-done', 'startDate' => '2026-06-01', 'endDate' => '2026-06-11'],
				['id' => 'w3', 'caseType' => 'woo-2', 'status' => 'st-done', 'startDate' => '2026-06-01', 'endDate' => '2026-06-09'],
			]
		)->getReport(period: '12m', caseType: null, view: 'breakdown', threshold: 1.0);

		$rows = $report['breakdown'];
		self::assertSame(expected: ['Woo-verzoek', 'Other'], actual: array_column(array: $rows, column_key: 'name'));
		self::assertSame(expected: 3, actual: $rows[0]['total']);
		self::assertSame(expected: 'woo-2', actual: $rows[0]['id']);
	}//end testBreakdownHasOneRowPerCaseTypeNotPerVersion()

	/**
	 * The compliance per case type ("Naleving per zaaktype") is one row per
	 * case type too: Woo-verzoek v1 and v2 together.
	 *
	 * @return void
	 */
	public function testComplianceHasOneRowPerCaseTypeNotPerVersion(): void {
		$this->loader = $this->createMock(originalClassName: ProcessMiningDataLoader::class);
		$this->loader->method('loadCaseTypes')->willReturn(
			[
				['id' => 'woo-1', 'identifier' => 'woo-verzoek', 'title' => 'Woo-verzoek', 'processingDeadline' => 'P28D'],
				['id' => 'woo-2', 'identifier' => 'woo-verzoek', 'title' => 'Woo-verzoek', 'processingDeadline' => 'P28D'],
			]
		);
		$this->loader->method('loadStatusTypes')->willReturn([['id' => 'st-done', 'isFinal' => true]]);

		$report = $this->service(
			records: [
				['id' => 'w1', 'caseType' => 'woo-1', 'status' => 'st-done', 'startDate' => '2026-06-01', 'endDate' => '2026-06-05'],
				['id' => 'w2', 'caseType' => 'woo-2', 'status' => 'st-done', 'startDate' => '2026-06-01', 'endDate' => '2026-06-11'],
			]
		)->getReport(period: '12m', caseType: null, view: 'sla', threshold: 1.0);

		self::assertCount(expectedCount: 1, haystack: $report['sla']);
		self::assertSame(expected: 2, actual: $report['sla'][0]['total']);
	}//end testComplianceHasOneRowPerCaseTypeNotPerVersion()

	/**
	 * The unfiltered report is handed to the publisher.
	 *
	 * @return void
	 */
	public function testUnfilteredReportIsPublished(): void {
		$this->publisher->expects(self::once())->method('publish')->with(self::callback(static fn (array $report): bool => isset($report['trend'])));

		$this->service(records: $this->records())->getReport(period: '3m', caseType: null, view: 'trend', threshold: 0.25);
	}//end testUnfilteredReportIsPublished()

	/**
	 * A load that reaches the limit says the figures are short.
	 *
	 * @return void
	 */
	public function testTruncatedAtTheLoadLimit(): void {
		$records = array_fill(0, ProcessMiningDataLoader::CASE_LIMIT, ['id' => 'x', 'caseType' => 'type-permit', 'status' => 'st-open']);

		$report = $this->service(records: $records)->getReport(period: '12m', caseType: null, view: 'breakdown', threshold: 0.25);

		self::assertTrue($report['truncated']);
	}//end testTruncatedAtTheLoadLimit()
}//end class
