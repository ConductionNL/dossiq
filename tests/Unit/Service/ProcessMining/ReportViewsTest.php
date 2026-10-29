<?php

/**
 * Locks the display blocks of the process-mining report.
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

use OCA\Dossiq\Service\ProcessMining\ReportViews;
use OCA\Dossiq\Service\ProcessMining\WorkingClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * KPI summary, dwell, throughput, bottleneck ranking, by-holder rows and the
 * clock disclosure in the column titles.
 *
 * @spec openspec/specs/process-mining-bottlenecks/spec.md
 */
class ReportViewsTest extends TestCase {

	private ReportViews $views;

	/**
	 * The shaper with a translator that echoes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $params = []): string {
				if ($params === []) {
					return $text;
				}

				return vsprintf(format: $text, values: $params);
			}
		);

		$this->views = new ReportViews(l10n: $l10n);
	}//end setUp()

	/**
	 * Two types with bottlenecks, the busier first as the report lists them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function caseTypes(): array {
		return [
			[
				'id'              => 'permit',
				'title'           => 'Permit',
				'caseVolume'      => 10,
				'reworkPercent'   => 10,
				'transitionCount' => 20,
				'dwellTime'       => [
					['statusName' => 'Intake', 'medianHours' => 4],
					['statusName' => 'In Review', 'medianHours' => 72, 'medianWorkingHours' => 20],
				],
				'dwellByAssignee' => [
					['actor' => 'anna', 'medianWorkingHours' => 6, 'medianHours' => 40, 'visitCount' => 3],
					['actor' => '', 'medianWorkingHours' => 2, 'medianHours' => 10, 'visitCount' => 1],
				],
				'bottlenecks'     => [
					['statusName' => 'Review', 'medianHours' => 72, 'visitCount' => 10, 'score' => 720],
					['statusName' => 'Intake', 'medianHours' => 4, 'visitCount' => 10, 'score' => 40],
				],
			],
			[
				'id'              => 'objection',
				'title'           => 'Objection',
				'caseVolume'      => 5,
				'reworkPercent'   => 40,
				'transitionCount' => 5,
				'dwellTime'       => [['statusName' => 'Advice', 'medianHours' => 200]],
				'bottlenecks'     => [
					['statusName' => 'Advice', 'medianHours' => 200, 'visitCount' => 3, 'score' => 600],
				],
			],
		];
	}//end caseTypes()

	/**
	 * Volume is summed and rework is weighted by transitions.
	 *
	 * @return void
	 */
	public function testSummary(): void {
		$summary = $this->views->summary(caseTypes: $this->caseTypes());

		self::assertSame(15, $summary['totalCases']);
		self::assertSame(2, $summary['caseTypeCount']);
		self::assertSame(16.0, $summary['overallReworkPercent']);
		self::assertSame('Review', $summary['topBottleneck']['statusName']);
		self::assertSame('Review (72h)', $summary['topBottleneckLabel']);
		self::assertNull($summary['reworkNotice']);
	}//end testSummary()

	/**
	 * An empty report has zero figures, no top bottleneck and a dash label.
	 *
	 * @return void
	 */
	public function testEmptySummary(): void {
		$summary = $this->views->summary(caseTypes: []);

		self::assertSame(0, $summary['totalCases']);
		self::assertSame(0.0, $summary['overallReworkPercent']);
		self::assertNull($summary['topBottleneck']);
		self::assertSame('—', $summary['topBottleneckLabel']);
	}//end testEmptySummary()

	/**
	 * The summary names the case type and status cases return to most
	 * (DqProcesanalyse "Bezwaar keert het vaakst terug naar Beoordeling").
	 *
	 * @return void
	 */
	public function testSummaryNamesWhereCasesReturnMost(): void {
		$types = $this->caseTypes();
		$types[0]['transitionMatrix'] = [
			['from' => 'r', 'to' => 'i', 'toName' => 'Intake', 'count' => 6, 'reworkCount' => 1],
		];
		$types[1]['transitionMatrix'] = [
			['from' => 'd', 'to' => 'a', 'toName' => 'Advice', 'count' => 2, 'reworkCount' => 0],
			['from' => 'a', 'to' => 'r', 'toName' => 'Review', 'count' => 3, 'reworkCount' => 2],
		];

		$summary = $this->views->summary(caseTypes: $types);

		self::assertSame(expected: 'Objection most often returns to Review', actual: $summary['reworkLead']);
	}//end testSummaryNamesWhereCasesReturnMost()

	/**
	 * Without a returning transition there is no lead.
	 *
	 * @return void
	 */
	public function testNoReworkLeadWithoutReturns(): void {
		self::assertSame(expected: '', actual: $this->views->summary(caseTypes: $this->caseTypes())['reworkLead']);
	}//end testNoReworkLeadWithoutReturns()

	/**
	 * At 20% rework or more the summary carries the notice sentence.
	 *
	 * @return void
	 */
	public function testReworkNotice(): void {
		$summary = $this->views->summary(caseTypes: [['caseVolume' => 1, 'reworkPercent' => 25, 'transitionCount' => 4]]);

		self::assertStringStartsWith('25% of recorded transitions revisit a status', (string)$summary['reworkNotice']);
	}//end testReworkNotice()

	/**
	 * Every type's bottlenecks in one ranking, highest score first, capped.
	 *
	 * @return void
	 */
	public function testBottleneckRanking(): void {
		$rows = $this->views->bottlenecks(caseTypes: $this->caseTypes(), limit: 10);

		self::assertSame(['Review', 'Advice', 'Intake'], array_column($rows, 'statusName'));
		self::assertSame('Permit', $rows[0]['caseTypeTitle']);
		self::assertCount(1, $this->views->bottlenecks(caseTypes: $this->caseTypes(), limit: 1));
		self::assertSame([], $this->views->bottlenecks(caseTypes: [], limit: 10));
	}//end testBottleneckRanking()

	/**
	 * The headline number is working hours when the row has them, the wall
	 * clock otherwise, and zero for nothing.
	 *
	 * @return void
	 */
	public function testHeadlineHours(): void {
		self::assertSame(1, $this->views->headlineHours(row: ['medianWorkingHours' => 1, 'medianHours' => 65]));
		self::assertSame(65, $this->views->headlineHours(row: ['medianHours' => 65]));
		self::assertSame(0, $this->views->headlineHours(row: []));
	}//end testHeadlineHours()

	/**
	 * The dwell bars are on the headline clock, not just the axis title.
	 *
	 * @return void
	 */
	public function testDwellUsesTheHeadlineClock(): void {
		self::assertSame(
			[['statusName' => 'Intake', 'hours' => 4], ['statusName' => 'In Review', 'hours' => 20]],
			$this->views->dwell(rows: $this->caseTypes()[0]['dwellTime'])
		);
		self::assertSame([], $this->views->dwell(rows: []));
	}//end testDwellUsesTheHeadlineClock()

	/**
	 * By-holder rows carry both numbers, and unattributed time is named.
	 *
	 * @return void
	 */
	public function testByAssignee(): void {
		$rows = $this->views->byAssignee(rows: $this->caseTypes()[0]['dwellByAssignee']);

		self::assertSame(['actor' => 'anna', 'actorLabel' => 'anna', 'workingHours' => 6, 'wallHours' => 40, 'visitCount' => 3], $rows[0]);
		self::assertCount(2, $rows);
		self::assertSame('Not recorded', $rows[1]['actorLabel']);
		self::assertSame([], $this->views->byAssignee(rows: []));
	}//end testByAssignee()

	/**
	 * The headline title says working hours only on the calendar, says how
	 * it was approximated otherwise, and is never the wall-clock title.
	 *
	 * @return void
	 */
	public function testColumnTitlesDiscloseTheClock(): void {
		self::assertSame('Working hours', $this->views->labels(clock: WorkingClock::CLOCK_CALENDAR)['workingHours']);
		self::assertSame('Working hours (working days x 8)', $this->views->labels(clock: WorkingClock::CLOCK_DAYS_TIMES_EIGHT)['workingHours']);
		self::assertSame('Hours, wall clock', $this->views->labels(clock: WorkingClock::CLOCK_WALL)['workingHours']);
		self::assertSame('Hours, wall clock', $this->views->labels(clock: 'sundial')['workingHours']);

		foreach ([WorkingClock::CLOCK_CALENDAR, WorkingClock::CLOCK_DAYS_TIMES_EIGHT, WorkingClock::CLOCK_WALL] as $clock) {
			$labels = $this->views->labels(clock: $clock);
			self::assertNotSame($labels['wallHours'], $labels['workingHours']);
		}
	}//end testColumnTitlesDiscloseTheClock()

	/**
	 * Per-type blocks describe the filtered type, else the busiest; a view
	 * answers only its block beside the labels.
	 *
	 * @return void
	 */
	public function testShapeFocusesAndNarrows(): void {
		$report = [
			'clock'           => WorkingClock::CLOCK_CALENDAR,
			'caseTypeFilter'  => 'objection',
			'caseTypes'       => $this->caseTypes(),
			'throughputTrend' => [['week' => '2026-W22', 'count' => 3], ['week' => '2026-W23', 'count' => 5]],
		];

		$all = $this->views->shape(report: $report, view: null);
		self::assertSame(['labels', ...ReportViews::VIEWS], array_keys($all));
		self::assertSame([['statusName' => 'Advice', 'hours' => 200]], $all['dwell']);
		self::assertSame($report['throughputTrend'], $all['throughput']);

		$one = $this->views->shape(report: [...$report, 'caseTypeFilter' => null], view: 'dwell');
		self::assertSame(['labels', 'dwell'], array_keys($one));
		self::assertSame('Intake', $one['dwell'][0]['statusName']);

		$missing = $this->views->shape(report: [...$report, 'caseTypeFilter' => 'none'], view: 'byAssignee');
		self::assertSame([], $missing['byAssignee']);
	}//end testShapeFocusesAndNarrows()
}//end class
