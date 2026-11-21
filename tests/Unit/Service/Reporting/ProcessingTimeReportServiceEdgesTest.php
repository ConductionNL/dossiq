<?php

/**
 * Locks the edges of the processing-time report: labels, type names and filters.
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

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\Reporting\AtRiskScan;
use OCA\Dossiq\Service\Reporting\ProcessingTimeCalculator;
use OCA\Dossiq\Service\Reporting\ProcessingTimeReportService;
use OCA\Dossiq\Service\Reporting\ProcessingTimeRows;
use OCA\Dossiq\Service\Reporting\ProcessingTimeRun;
use OCA\Dossiq\Service\Reporting\ProcessingTimeSeriesPublisher;
use OCA\Dossiq\Service\Reporting\ProcessingTimeSteps;
use OCA\Dossiq\Service\Reporting\ReportPeriod;
use OCA\Dossiq\Service\Reporting\RiskMeasure;
use OCA\Dossiq\Service\Reporting\WeeklyThroughput;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Term\DayCount;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A case due today, types named only by `name` or not at all, and filters by
 * id or by a key no type answers to.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
#[CoversClass(ProcessingTimeReportService::class)]
#[UsesClass(AtRiskScan::class)]
#[UsesClass(WeeklyThroughput::class)]
#[UsesClass(CaseDateNormaliser::class)]
#[UsesClass(DayCount::class)]
#[UsesClass(LanguageMapText::class)]
#[UsesClass(ProcessingTimeCalculator::class)]
#[UsesClass(ProcessingTimeRows::class)]
#[UsesClass(ProcessingTimeRun::class)]
#[UsesClass(ProcessingTimeSteps::class)]
#[UsesClass(ReportPeriod::class)]
#[UsesClass(RiskMeasure::class)]
class ProcessingTimeReportServiceEdgesTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The service over the given types and records, on a clock frozen at
	 * 15 June 2026, 10:00 UTC.
	 *
	 * @param array<int, array<string, mixed>> $types   The record types the loader answers.
	 * @param array<int, array<string, mixed>> $records The records the loader answers.
	 *
	 * @return ProcessingTimeReportService
	 */
	private function service(array $types, array $records): ProcessingTimeReportService {
		$loader = $this->createMock(ProcessMiningDataLoader::class);
		$loader->method('loadCaseTypes')->willReturn($types);
		$loader->method('loadStatusTypes')->willReturn([['id' => 'st-open', 'isFinal' => false], ['id' => 'st-done', 'isFinal' => true]]);
		$loader->method('loadCases')->willReturn($records);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural))
		);

		return new ProcessingTimeReportService(
			loader: $loader,
			steps: new ProcessingTimeSteps(
				calculator: new ProcessingTimeCalculator(),
				rows: new ProcessingTimeRows(),
				publisher: $this->createMock(ProcessingTimeSeriesPublisher::class),
			),
			dates: $this->caseDatesFrozenAt(instant: '2026-06-15T10:00:00+00:00'),
			periods: new ReportPeriod(),
			l10n: $l10n,
		);
	}//end service()

	/**
	 * Two plain types.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function types(): array {
		return [
			['id' => 'type-permit', 'slug' => 'permit', 'title' => 'Permit', 'processingDeadline' => 'P10D'],
			['id' => 'type-other', 'slug' => 'other', 'title' => 'Other', 'processingDeadline' => 'P30D'],
		];
	}//end types()

	/**
	 * An open record of each type, both started on 5 June.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function openRecords(): array {
		return [
			['id' => 'permit-open', 'caseType' => 'type-permit', 'status' => 'st-open', 'startDate' => '2026-06-05'],
			['id' => 'other-open', 'caseType' => 'type-other', 'status' => 'st-open', 'startDate' => '2026-06-05'],
		];
	}//end openRecords()

	/**
	 * A case whose last day is today reads "Due today", not "0 days remaining".
	 *
	 * @return void
	 */
	public function testCaseOnItsLastDayIsDueToday(): void {
		$report = $this->service(types: $this->types(), records: $this->openRecords())
			->getReport(period: '12m', caseType: null, view: 'atRisk', threshold: 1.0);

		$rows = array_column($report['atRisk'], null, 'id');
		self::assertSame(0, $rows['permit-open']['remainingDays']);
		self::assertSame('Due today', $rows['permit-open']['remainingLabel']);
		self::assertSame('20 days remaining', $rows['other-open']['remainingLabel']);
	}//end testCaseOnItsLastDayIsDueToday()

	/**
	 * A type without an id is ignored; a type without a title is named by its
	 * `name`, and one with neither reads "Unknown".
	 *
	 * @return void
	 */
	public function testTypeNamesFallBackToNameThenUnknown(): void {
		$types = [
			['slug' => 'nameless', 'title' => 'No id', 'processingDeadline' => 'P5D'],
			['id' => 'type-named', 'slug' => 'named', 'name' => 'By name', 'processingDeadline' => 'P10D'],
			['id' => 'type-bare', 'slug' => 'bare', 'processingDeadline' => 'P10D'],
		];

		$report = $this->service(types: $types, records: [])
			->getReport(period: '12m', caseType: null, view: 'breakdown', threshold: 0.25);

		self::assertSame(['By name', 'Unknown'], array_column($report['breakdown'], 'name'));
	}//end testTypeNamesFallBackToNameThenUnknown()

	/**
	 * A filter given as a type id narrows to that type's records.
	 *
	 * @return void
	 */
	public function testCaseTypeFilterById(): void {
		$report = $this->service(types: $this->types(), records: $this->openRecords())
			->getReport(period: 'all', caseType: 'type-other', view: 'atRisk', threshold: 1.0);

		self::assertSame('type-other', $report['caseType']);
		self::assertSame(['other-open'], array_column($report['atRisk'], 'id'));
	}//end testCaseTypeFilterById()

	/**
	 * A filter no type answers to is kept as given and matches no record.
	 *
	 * @return void
	 */
	public function testUnknownCaseTypeFilterMatchesNothing(): void {
		$report = $this->service(types: $this->types(), records: $this->openRecords())
			->getReport(period: 'all', caseType: 'nope', view: 'atRisk', threshold: 1.0);

		self::assertSame('nope', $report['caseType']);
		self::assertSame([], $report['atRisk']);
	}//end testUnknownCaseTypeFilterMatchesNothing()
}//end class
