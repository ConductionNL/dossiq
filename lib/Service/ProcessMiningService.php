<?php

/**
 * Dossiq Process Mining Service
 *
 * Computes bottleneck-analysis metrics from the `statusRecord` chain that
 * {@see StatusTransitionService} already writes on every case transition
 * (ADR-Leaf-First: dossiq ships the data provider, nc-vue leaves render
 * it — no bespoke chart components here). Four metric families, all
 * derived from the same single read path via OpenRegister's ObjectService:
 *
 *  - per-status dwell-time stats (median/p90/mean hours), per case type
 *  - bottleneck ranking (dwell time x case volume)
 *  - transition frequency matrix, incl. rework-loop detection (a
 *    transition that revisits a status the case already left earlier)
 *  - weekly throughput trend (cases closed per week)
 *
 * This class is the orchestrator only. The read path lives in
 * {@see ProcessMiningDataLoader}; each metric family lives in its own
 * calculator ({@see DwellTimeAnalyzer}, {@see TransitionMatrixBuilder},
 * {@see ThroughputTrendCalculator}). What remains here is the report
 * assembly: resolving the reporting period, grouping the loaded cases per
 * case type, and handing each group to the calculators.
 *
 * See openspec/changes/process-mining-bottlenecks/design.md for why the
 * `statusRecord` register (rather than a new event log) is the source of
 * truth.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer;
use OCA\Dossiq\Service\ProcessMining\PhaseSummary;
use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\ProcessMining\ThroughputTrendCalculator;
use OCA\Dossiq\Service\ProcessMining\TransitionMatrixBuilder;
use OCA\Dossiq\Service\Support\LanguageMapText;

/**
 * Computes process-mining bottleneck metrics from recorded status history.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class ProcessMiningService {
	/**
	 * Constructor.
	 *
	 * @param ProcessMiningDataLoader $dataLoader The OpenRegister read path + lookup indexes.
	 * @param DwellTimeAnalyzer $dwellTimeAnalyzer Dwell-interval reconstruction + bottleneck ranking.
	 * @param TransitionMatrixBuilder $transitionBuilder Transition matrix + rework detection.
	 * @param ThroughputTrendCalculator $throughputCalculator Weekly closed-case throughput trend.
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param LanguageMapText $text A translatable title or name as one string.
	 * @param PhaseSummary $phases The phase matrix and the stuck list the page leads with.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ProcessMiningDataLoader $dataLoader,
		private readonly DwellTimeAnalyzer $dwellTimeAnalyzer,
		private readonly TransitionMatrixBuilder $transitionBuilder,
		private readonly ThroughputTrendCalculator $throughputCalculator,
		private readonly CaseDateNormaliser $dates,
		private readonly LanguageMapText $text = new LanguageMapText(),
		private readonly PhaseSummary $phases = new PhaseSummary(),
	) {
	}//end __construct()

	/**
	 * Compute the full process-mining report for the given parameters.
	 *
	 * @param array<string, mixed> $params Query parameters from the controller
	 *                                     (`from`, `to`, `caseType` — all optional strings).
	 *
	 * @return array<string, mixed> The structured response body.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function getReport(array $params): array {
		$report = $this->buildReport(params: $params);
		return [...$report, ...$this->phases->forPage(caseTypes: $report['caseTypes'])];
	}//end getReport()

	/**
	 * The report itself: per case type dwell, bottlenecks and transitions, and the throughput trend.
	 *
	 * @param array<string, mixed> $params Query parameters (from, to, caseType).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	private function buildReport(array $params): array {
		$to = ($this->dates->tryParse($params['to'] ?? null) ?? $this->dates->today());
		$from = $to->sub(new DateInterval('P12M'));
		$fromParam = $this->nonEmptyStringParam(params: $params, key: 'from');
		if ($fromParam !== null) {
			$from = ($this->dates->tryParse($fromParam) ?? $to->sub(new DateInterval('P12M')));
		}

		$caseTypeFilter = $this->nonEmptyStringParam(params: $params, key: 'caseType');

		$cases = $this->dataLoader->loadCases(caseTypeFilter: $caseTypeFilter);
		$caseTypes = $this->dataLoader->loadCaseTypes();
		$statusTypes = $this->dataLoader->loadStatusTypes();
		$records = $this->dataLoader->loadStatusRecords();

		$casesById = [];
		foreach ($cases as $caseData) {
			$id = (string)($caseData['id'] ?? '');
			if ($id !== '') {
				$casesById[$id] = $caseData;
			}
		}

		$recordsByCase = $this->groupRecordsByCase(records: $records, cases: $casesById);

		$statusTypeIndex = $this->dataLoader->indexById(rows: $statusTypes);
		$caseTypeIndex = $this->dataLoader->indexByIdAndSlug(rows: $caseTypes);

		$now = new DateTimeImmutable('now');

		$caseTypeGroups = $this->groupCasesByType(cases: $casesById, caseTypeIndex: $caseTypeIndex);

		$caseTypeReports = [];
		foreach ($caseTypeGroups as $caseTypeId => $group) {
			$caseTypeReports[] = $this->caseTypeReport(
				caseTypeId: (string)$caseTypeId,
				group: $group,
				recordsByCase: $recordsByCase,
				statusTypeIndex: $statusTypeIndex,
				from: $from,
				to: $to,
				now: $now,
			);
		}

		usort(
			$caseTypeReports,
			static fn (array $left, array $right): int => ($right['caseVolume'] <=> $left['caseVolume'])
		);

		return [
			'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
			// WHICH CLOCK THE NUMBERS ARE ON, published with them. The page
			// puts it in the column header. A report that serves working
			// hours counted on working days times eight, and looks exactly
			// like one counted on the calendar, is the failure this whole
			// change is about, so the answer travels with the payload rather
			// than being assumed by the reader.
			'clock' => $this->dwellTimeAnalyzer->clock(),
			'caseTypeFilter' => $caseTypeFilter,
			// The load stops at its limit, so a report that reached it is short.
			'truncated' => (count($cases) >= ProcessMiningDataLoader::CASE_LIMIT),
			'caseTypes' => $caseTypeReports,
			'throughputTrend' => $this->throughputCalculator->computeThroughputTrend(cases: $casesById, from: $from, to: $to),
		];
	}//end buildReport()

	/**
	 * One case type's part of the report: dwell, bottlenecks and transitions.
	 *
	 * @param string                                          $caseTypeId      The case type id.
	 * @param array{title: mixed, cases: array<string, mixed>}       $group           The case type's title and cases.
	 * @param array<string, array<int, array<string, mixed>>> $recordsByCase   Status records per case.
	 * @param array<string, array<string, mixed>>             $statusTypeIndex StatusType rows, keyed by id.
	 * @param DateTimeImmutable                               $from            Start of the period.
	 * @param DateTimeImmutable                               $to              End of the period.
	 * @param DateTimeImmutable                               $now             The moment of reading.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	private function caseTypeReport(
		string $caseTypeId,
		array $group,
		array $recordsByCase,
		array $statusTypeIndex,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		DateTimeImmutable $now,
	): array {
		$caseIdsInGroup = array_keys($group['cases']);
		$recordsForThisGroup = array_intersect_key($recordsByCase, array_flip($caseIdsInGroup));

		$intervals = $this->dwellTimeAnalyzer->computeDwellIntervals(
			recordsByCase: $recordsForThisGroup,
			casesById: $group['cases'],
			now: $now,
			periodFrom: $from,
			periodTo: $to,
		);

		$dwellStats = $this->dwellTimeAnalyzer->aggregateDwellStats(intervals: $intervals, statusTypeIndex: $statusTypeIndex);
		// Days per phase reads visits that ended only, so it holds still under the same data.
		$completedStats = $this->dwellTimeAnalyzer->aggregateDwellStats(
			intervals: array_values(array_filter($intervals, static fn (array $interval): bool => ($interval['open'] ?? false) === false)),
			statusTypeIndex: $statusTypeIndex
		);
		$bottlenecks = $this->dwellTimeAnalyzer->rankBottlenecks(dwellStats: $dwellStats);
		$transitions = $this->transitionBuilder->computeTransitionMatrix(
			recordsByCase: $recordsForThisGroup,
			statusTypeIndex: $statusTypeIndex
		);

		return [
			'id' => $caseTypeId,
			'title' => $group['title'],
			'caseVolume' => count($group['cases']),
			'dwellTime' => $dwellStats,
			'dwellTimeCompleted' => $completedStats,
			// The working days the CASES hold, beside the aggregates
			// reconstructed from the record chain. Published rather than
			// recomputed, so the page and a handler's work list answer the
			// same question with the same number.
			'dwellDaysHeld' => $this->dwellTimeAnalyzer->heldTotalsByStatus(
				casesById: $group['cases'],
				now: $now,
			),
			// The same intervals keyed by who held them. No second walk
			// of the records: a by-assignee table computed on its own
			// would drift from the by-phase one beside it.
			'dwellByAssignee' => $this->dwellTimeAnalyzer->aggregateDwellStatsByActor(intervals: $intervals),
			'bottlenecks' => $bottlenecks,
			'transitionMatrix' => $transitions['matrix'],
			'reworkPercent' => $transitions['reworkPercent'],
			'transitionCount' => $transitions['totalCount'],
		];
	}//end caseTypeReport()

	/**
	 * Build dwell-time intervals: one entry per (case, status-visit).
	 *
	 * Delegates to {@see DwellTimeAnalyzer::computeDwellIntervals()}.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsByCase Chronologically sorted statusRecords, keyed by case id.
	 * @param array<string, array<string, mixed>> $casesById Case rows, keyed by id.
	 * @param DateTimeImmutable $now "Now", for open cases' current status.
	 * @param DateTimeImmutable $periodFrom Inclusive period start.
	 * @param DateTimeImmutable $periodTo Inclusive period end.
	 *
	 * @return array<int, array{caseId: string, statusId: string, hours: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeDwellIntervals(
		array $recordsByCase,
		array $casesById,
		DateTimeImmutable $now,
		DateTimeImmutable $periodFrom,
		DateTimeImmutable $periodTo,
	): array {
		return $this->dwellTimeAnalyzer->computeDwellIntervals(
			recordsByCase: $recordsByCase,
			casesById: $casesById,
			now: $now,
			periodFrom: $periodFrom,
			periodTo: $periodTo,
		);
	}//end computeDwellIntervals()

	/**
	 * Aggregate dwell-time intervals per status into median/p90/mean stats.
	 *
	 * Delegates to {@see DwellTimeAnalyzer::aggregateDwellStats()}.
	 *
	 * @param array<int, array{caseId: string, statusId: string, hours: float}> $intervals Dwell intervals.
	 * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
	 *
	 * @return array<int, array{statusId: string, statusName: string, visitCount: int, medianHours: float, p90Hours: float, meanHours: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function aggregateDwellStats(array $intervals, array $statusTypeIndex): array {
		return $this->dwellTimeAnalyzer->aggregateDwellStats(intervals: $intervals, statusTypeIndex: $statusTypeIndex);
	}//end aggregateDwellStats()

	/**
	 * Rank statuses by bottleneck severity: median dwell time x visit volume.
	 *
	 * Delegates to {@see DwellTimeAnalyzer::rankBottlenecks()}.
	 *
	 * @param array<int, array<string, mixed>> $dwellStats Per-status dwell stats.
	 *
	 * @return array<int, array{statusId: string, statusName: string, visitCount: int, medianHours: float, score: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function rankBottlenecks(array $dwellStats): array {
		return $this->dwellTimeAnalyzer->rankBottlenecks(dwellStats: $dwellStats);
	}//end rankBottlenecks()

	/**
	 * Build the from→to transition frequency matrix and detect rework loops.
	 *
	 * Delegates to {@see TransitionMatrixBuilder::computeTransitionMatrix()}.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsByCase Chronologically sorted statusRecords, keyed by case id.
	 * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
	 *
	 * @return array{matrix: array<int, array{from: string, fromName: string, to: string,
	 *                toName: string, count: int, reworkCount: int}>, reworkPercent: float, totalCount: int}
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeTransitionMatrix(array $recordsByCase, array $statusTypeIndex): array {
		return $this->transitionBuilder->computeTransitionMatrix(
			recordsByCase: $recordsByCase,
			statusTypeIndex: $statusTypeIndex
		);
	}//end computeTransitionMatrix()

	/**
	 * Walk one case's chronologically sorted statusRecords into from→to pairs.
	 *
	 * Delegates to {@see TransitionMatrixBuilder::computeCaseTransitions()}.
	 *
	 * @param array<int, array<string, mixed>> $sortedRecords Chronologically sorted statusRecords for one case.
	 *
	 * @return array<int, array{from: string, to: string, isRework: bool}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeCaseTransitions(array $sortedRecords): array {
		return $this->transitionBuilder->computeCaseTransitions(sortedRecords: $sortedRecords);
	}//end computeCaseTransitions()

	/**
	 * Weekly throughput trend: cases closed (by `endDate`) per ISO week.
	 *
	 * Delegates to {@see ThroughputTrendCalculator::computeThroughputTrend()}.
	 *
	 * @param array<string, array<string, mixed>> $cases Case rows, keyed by id.
	 * @param DateTimeImmutable $from Inclusive period start.
	 * @param DateTimeImmutable $to Inclusive period end.
	 *
	 * @return array<int, array{week: string, count: int}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function computeThroughputTrend(array $cases, DateTimeImmutable $from, DateTimeImmutable $to): array {
		return $this->throughputCalculator->computeThroughputTrend(cases: $cases, from: $from, to: $to);
	}//end computeThroughputTrend()

	/**
	 * Read a query parameter that MUST be a non-empty string, or null when it is absent, not a
	 * string, or empty.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 * @param string $key The parameter name.
	 *
	 * @return string|null The parameter value, or null.
	 */
	private function nonEmptyStringParam(array $params, string $key): ?string {
		$value = ($params[$key] ?? null);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end nonEmptyStringParam()

	/**
	 * Group cases by their caseType, resolving the display title.
	 *
	 * @param array<string, array<string, mixed>> $cases Case rows, keyed by id.
	 * @param array<string, array<string, mixed>> $caseTypeIndex CaseType rows, keyed by id and slug.
	 *
	 * @return array<string, array{title: string, cases: array<string, array<string, mixed>>}>
	 */
	private function groupCasesByType(array $cases, array $caseTypeIndex): array {
		$groups = [];
		foreach ($cases as $caseId => $caseData) {
			$caseTypeKey = (string)($caseData['caseType'] ?? '');
			if ($caseTypeKey === '') {
				continue;
			}

			if (isset($groups[$caseTypeKey]) === false) {
				$title = $caseTypeKey;
				if (isset($caseTypeIndex[$caseTypeKey]) === true) {
					$entry = $caseTypeIndex[$caseTypeKey];
					$title = $this->text->textOr(value: ($entry['title'] ?? null), fallback: $caseTypeKey);
				}

				$groups[$caseTypeKey] = ['title' => $title, 'cases' => []];
			}

			$groups[$caseTypeKey]['cases'][$caseId] = $caseData;
		}//end foreach

		return $groups;
	}//end groupCasesByType()

	/**
	 * Group and chronologically sort statusRecords by their `case` field,
	 * restricted to the cases in scope; a case without records is read from
	 * its own status history.
	 *
	 * @param array<int, array<string, mixed>>    $records Raw statusRecord rows.
	 * @param array<string, array<string, mixed>> $cases   The cases in scope, by id.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function groupRecordsByCase(array $records, array $cases): array {
		$grouped = [];
		foreach ($records as $record) {
			$caseId = (string)($record['case'] ?? '');
			if ($caseId === '' || isset($cases[$caseId]) === false) {
				continue;
			}

			$grouped[$caseId][] = $record;
		}

		// A case without status records (example data, an imported case) is read from its own status history.
		foreach (array_diff_key($cases, $grouped) as $caseId => $case) {
			$fromHistory = $this->recordsFromHistory(caseId: (string)$caseId, case: $case);
			if ($fromHistory !== []) {
				$grouped[$caseId] = $fromHistory;
			}
		}

		foreach ($grouped as $caseId => $rows) {
			usort(
				$rows,
				function (array $left, array $right): int {
					$leftAt = $this->extractTimestamp(record: $left);
					$rightAt = $this->extractTimestamp(record: $right);
					if ($leftAt === null || $rightAt === null) {
						return 0;
					}

					return ($leftAt <=> $rightAt);
				}
			);
			$grouped[$caseId] = $rows;
		}

		return $grouped;
	}//end groupRecordsByCase()

	/**
	 * Status records read off a case's own status history: one per entry, at the moment it entered that status.
	 *
	 * @param string               $caseId The case id.
	 * @param array<string, mixed> $case   The case; `statusHistory` as a list or as JSON text.
	 *
	 * Each is held by the person the entry names, else by the case's handler:
	 * a history entry carries no actor of its own, and time nobody holds would
	 * read as "not recorded" in the per-handler table.
	 *
	 * @return array<int, array{case: string, statusType: string, createdAt: string, actor: string}>
	 *
	 * @spec openspec/specs/dwell-from-status-history-actor/spec.md#requirement-time-read-from-the-status-history-has-a-holder
	 */
	private function recordsFromHistory(string $caseId, array $case): array {
		$history = ($case['statusHistory'] ?? []);
		if (is_string($history) === true) {
			$history = json_decode($history, true);
		}

		$holder = trim((string)($case['assignee'] ?? ''));
		$records = [];
		foreach ((array)$history as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$status = (string)($entry['status'] ?? '');
			$moment = (string)($entry['enteredAt'] ?? '');
			if ($status === '' || $moment === '') {
				continue;
			}

			$actor = trim((string)($entry['actor'] ?? ($entry['by'] ?? '')));
			if ($actor === '') {
				$actor = $holder;
			}

			$records[] = ['case' => $caseId, 'statusType' => $status, 'createdAt' => $moment, 'actor' => $actor];
		}

		return $records;
	}//end recordsFromHistory()

	/**
	 * Extract a record's creation timestamp — either the flattened
	 * `createdAt` key or OpenRegister's `@self.created` metadata block.
	 *
	 * @param array<string, mixed> $record A statusRecord row.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function extractTimestamp(array $record): ?DateTimeImmutable {
		$raw = ($record['createdAt'] ?? ($record['@self']['created'] ?? ($record['@self']['createdAt'] ?? null)));
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		return $this->dates->tryParse($raw);
	}//end extractTimestamp()

}//end class
