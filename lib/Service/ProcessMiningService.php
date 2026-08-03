<?php

/**
 * Procest Process Mining Service
 *
 * Computes bottleneck-analysis metrics from the `statusRecord` chain that
 * {@see StatusTransitionService} already writes on every case transition
 * (ADR-Leaf-First: procest ships the data provider, nc-vue leaves render
 * it — no bespoke chart components here). Four metric families, all
 * derived from the same single read path via OpenRegister's ObjectService:
 *
 *  - per-status dwell-time stats (median/p90/mean hours), per case type
 *  - bottleneck ranking (dwell time x case volume)
 *  - transition frequency matrix, incl. rework-loop detection (a
 *    transition that revisits a status the case already left earlier)
 *  - weekly throughput trend (cases closed per week)
 *
 * See openspec/changes/process-mining-bottlenecks/design.md for why the
 * `statusRecord` register (rather than a new event log) is the source of
 * truth.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Computes process-mining bottleneck metrics from recorded status history.
 */
class ProcessMiningService
{

    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Shared settings/OR resolver.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
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
     */
    public function getReport(array $params): array
    {
        $to = $this->parseDate(value: ($params['to'] ?? null), fallback: new DateTimeImmutable('today'));
        if (isset($params['from']) === true && is_string($params['from']) === true && $params['from'] !== '') {
            $from = $this->parseDate(value: $params['from'], fallback: $to->sub(new DateInterval('P12M')));
        } else {
            $from = $to->sub(new DateInterval('P12M'));
        }

        if (isset($params['caseType']) === true && is_string($params['caseType']) === true && $params['caseType'] !== '') {
            $caseTypeFilter = $params['caseType'];
        } else {
            $caseTypeFilter = null;
        }

        $cases       = $this->loadCases(caseTypeFilter: $caseTypeFilter);
        $caseTypes   = $this->loadCaseTypes();
        $statusTypes = $this->loadStatusTypes();
        $records     = $this->loadStatusRecords();

        $casesById = [];
        foreach ($cases as $caseData) {
            $id = (string) ($caseData['id'] ?? '');
            if ($id !== '') {
                $casesById[$id] = $caseData;
            }
        }

        $recordsByCase = $this->groupRecordsByCase(records: $records, caseIds: array_keys($casesById));

        $statusTypeIndex = $this->indexById(rows: $statusTypes);
        $caseTypeIndex   = $this->indexByIdAndSlug(rows: $caseTypes);

        $now = new DateTimeImmutable('now');

        $caseTypeGroups = $this->groupCasesByType(cases: $casesById, caseTypeIndex: $caseTypeIndex);

        $caseTypeReports = [];
        foreach ($caseTypeGroups as $caseTypeId => $group) {
            $caseIdsInGroup      = array_keys($group['cases']);
            $recordsForThisGroup = array_intersect_key($recordsByCase, array_flip($caseIdsInGroup));

            $intervals = $this->computeDwellIntervals(
                recordsByCase: $recordsForThisGroup,
                casesById: $group['cases'],
                now: $now,
                periodFrom: $from,
                periodTo: $to,
            );

            $dwellStats  = $this->aggregateDwellStats(intervals: $intervals, statusTypeIndex: $statusTypeIndex);
            $bottlenecks = $this->rankBottlenecks(dwellStats: $dwellStats);
            $transitions = $this->computeTransitionMatrix(recordsByCase: $recordsForThisGroup, statusTypeIndex: $statusTypeIndex);

            $caseTypeReports[] = [
                'id'               => $caseTypeId,
                'title'            => $group['title'],
                'caseVolume'       => count($group['cases']),
                'dwellTime'        => $dwellStats,
                'bottlenecks'      => $bottlenecks,
                'transitionMatrix' => $transitions['matrix'],
                'reworkPercent'    => $transitions['reworkPercent'],
                'transitionCount'  => $transitions['totalCount'],
            ];
        }//end foreach

        usort(
            $caseTypeReports,
            static fn (array $left, array $right): int => ($right['caseVolume'] <=> $left['caseVolume'])
        );

        return [
            'period'          => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'caseTypeFilter'  => $caseTypeFilter,
            'caseTypes'       => $caseTypeReports,
            'throughputTrend' => $this->computeThroughputTrend(cases: $casesById, from: $from, to: $to),
        ];
    }//end getReport()

    /**
     * Build dwell-time intervals: one entry per (case, status-visit), the
     * time the case spent in that status before the next recorded
     * transition (or, for the still-current status, before `$now`/the
     * case's `endDate`).
     *
     * Handles the invariants callers rely on:
     *  - a case with zero statusRecords contributes nothing (no crash);
     *  - the still-open current status uses `$now` as its exit boundary;
     *  - a closed case's final status uses the case's `endDate`;
     *  - two records with an identical timestamp yield a zero-hour interval;
     *  - only intervals that ENTERED the status within `[periodFrom, periodTo]`
     *    are returned — the exit boundary may fall outside the window.
     *
     * @param array<string, array<int, array<string, mixed>>> $recordsByCase Chronologically sorted statusRecords, keyed by case id.
     * @param array<string, array<string, mixed>>             $casesById     Case rows, keyed by id.
     * @param DateTimeImmutable                               $now           "Now", for open cases' current status.
     * @param DateTimeImmutable                               $periodFrom    Inclusive period start.
     * @param DateTimeImmutable                               $periodTo      Inclusive period end.
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
        $windowStart = $periodFrom->setTime(0, 0, 0);
        $windowEnd   = $periodTo->setTime(23, 59, 59);

        $intervals = [];
        foreach ($recordsByCase as $caseId => $records) {
            $count = count($records);
            if ($count === 0) {
                continue;
            }

            $case     = ($casesById[$caseId] ?? []);
            $endDate  = ($case['endDate'] ?? null);
            $closedAt = null;
            if (is_string($endDate) === true && $endDate !== '') {
                $closedAt = $this->parseDate(value: $endDate, fallback: $now);
            }

            for ($i = 0; $i < $count; $i++) {
                $statusId = (string) ($records[$i]['statusType'] ?? '');
                if ($statusId === '') {
                    continue;
                }

                $enteredAt = $this->extractTimestamp(record: $records[$i]);
                if ($enteredAt === null) {
                    continue;
                }

                if ($enteredAt < $windowStart || $enteredAt > $windowEnd) {
                    continue;
                }

                if (($i + 1) < $count) {
                    $exitedAt = $this->extractTimestamp(record: $records[$i + 1]);
                } else {
                    $exitedAt = ($closedAt ?? $now);
                }

                if ($exitedAt === null) {
                    $exitedAt = $enteredAt;
                }

                $hours = (($exitedAt->getTimestamp() - $enteredAt->getTimestamp()) / 3600.0);
                if ($hours < 0.0) {
                    $hours = 0.0;
                }

                $intervals[] = [
                    'caseId'   => (string) $caseId,
                    'statusId' => $statusId,
                    'hours'    => $hours,
                ];
            }//end for
        }//end foreach

        return $intervals;
    }//end computeDwellIntervals()

    /**
     * Aggregate dwell-time intervals per status into median/p90/mean stats.
     *
     * @param array<int, array{caseId: string, statusId: string, hours: float}> $intervals       Dwell intervals.
     * @param array<string, array<string, mixed>>                               $statusTypeIndex StatusType rows, keyed by id.
     *
     * @return array<int, array{statusId: string, statusName: string, visitCount: int, medianHours: float, p90Hours: float, meanHours: float}>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function aggregateDwellStats(array $intervals, array $statusTypeIndex): array
    {
        $byStatus = [];
        foreach ($intervals as $interval) {
            $statusId = $interval['statusId'];
            if (isset($byStatus[$statusId]) === false) {
                $byStatus[$statusId] = [];
            }

            $byStatus[$statusId][] = $interval['hours'];
        }

        $out = [];
        foreach ($byStatus as $statusId => $hoursList) {
            sort($hoursList);
            $out[] = [
                'statusId'    => $statusId,
                'statusName'  => $this->statusLabel(statusId: $statusId, statusTypeIndex: $statusTypeIndex),
                'visitCount'  => count($hoursList),
                'medianHours' => round(self::percentile(sorted: $hoursList, percentile: 50.0), 1),
                'p90Hours'    => round(self::percentile(sorted: $hoursList, percentile: 90.0), 1),
                'meanHours'   => round((array_sum($hoursList) / count($hoursList)), 1),
            ];
        }

        return $out;
    }//end aggregateDwellStats()

    // phpcs:disable Generic.Files.LineLength.MaxExceeded -- PEAR.Commenting.FunctionComment misreads a wrapped @param array shape as MissingParamName, so this shape stays on one line.

    /**
     * Rank statuses by bottleneck severity: median dwell time x visit volume.
     * Highest score first.
     *
     * @param array<int, array{statusId: string, statusName: string, visitCount: int, medianHours: float, p90Hours: float, meanHours: float}> $dwellStats Per-status dwell stats.
     *
     * @return array<int, array{statusId: string, statusName: string, visitCount: int, medianHours: float, score: float}>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function rankBottlenecks(array $dwellStats): array
    {
        $ranked = [];
        foreach ($dwellStats as $stat) {
            $ranked[] = [
                'statusId'    => $stat['statusId'],
                'statusName'  => $stat['statusName'],
                'visitCount'  => $stat['visitCount'],
                'medianHours' => $stat['medianHours'],
                'score'       => round(($stat['medianHours'] * $stat['visitCount']), 1),
            ];
        }

        usort(
            $ranked,
            static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
        );

        return $ranked;
    }//end rankBottlenecks()

    // phpcs:enable Generic.Files.LineLength.MaxExceeded

    /**
     * Build the from→to transition frequency matrix and detect rework
     * loops — a transition whose target status the case had already left
     * earlier in its own history.
     *
     * @param array<string, array<int, array<string, mixed>>> $recordsByCase   Chronologically sorted statusRecords, keyed by case id.
     * @param array<string, array<string, mixed>>             $statusTypeIndex StatusType rows, keyed by id.
     *
     * @return array{
     *     matrix: array<int, array{
     *         from: string,
     *         fromName: string,
     *         to: string,
     *         toName: string,
     *         count: int,
     *         reworkCount: int
     *     }>,
     *     reworkPercent: float,
     *     totalCount: int
     * }
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function computeTransitionMatrix(array $recordsByCase, array $statusTypeIndex): array
    {
        $matrix     = [];
        $totalCount = 0;
        $reworkSum  = 0;

        foreach ($recordsByCase as $records) {
            $transitions = $this->computeCaseTransitions(sortedRecords: $records);
            foreach ($transitions as $transition) {
                $key = ($transition['from'].'::'.$transition['to']);
                if (isset($matrix[$key]) === false) {
                    $matrix[$key] = [
                        'from'        => $transition['from'],
                        'to'          => $transition['to'],
                        'count'       => 0,
                        'reworkCount' => 0,
                    ];
                }

                $matrix[$key]['count']++;
                $totalCount++;
                if ($transition['isRework'] === true) {
                    $matrix[$key]['reworkCount']++;
                    $reworkSum++;
                }
            }
        }//end foreach

        $out = [];
        foreach ($matrix as $row) {
            $out[] = [
                'from'        => $row['from'],
                'fromName'    => $this->statusLabel(statusId: $row['from'], statusTypeIndex: $statusTypeIndex),
                'to'          => $row['to'],
                'toName'      => $this->statusLabel(statusId: $row['to'], statusTypeIndex: $statusTypeIndex),
                'count'       => $row['count'],
                'reworkCount' => $row['reworkCount'],
            ];
        }

        usort(
            $out,
            static fn (array $left, array $right): int => ($right['count'] <=> $left['count'])
        );

        if ($totalCount === 0) {
            $reworkPercent = 0.0;
        } else {
            $reworkPercent = round((($reworkSum / $totalCount) * 100), 1);
        }

        return [
            'matrix'        => $out,
            'reworkPercent' => $reworkPercent,
            'totalCount'    => $totalCount,
        ];
    }//end computeTransitionMatrix()

    /**
     * Walk one case's chronologically sorted statusRecords into
     * from→to transition pairs, flagging any transition that revisits a
     * status the case had already left earlier (a rework loop).
     *
     * @param array<int, array<string, mixed>> $sortedRecords Chronologically sorted statusRecords for one case.
     *
     * @return array<int, array{from: string, to: string, isRework: bool}>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function computeCaseTransitions(array $sortedRecords): array
    {
        $count = count($sortedRecords);
        if ($count < 2) {
            return [];
        }

        $visited = [];
        $first   = (string) ($sortedRecords[0]['statusType'] ?? '');
        if ($first !== '') {
            $visited[$first] = true;
        }

        $transitions = [];
        for ($i = 1; $i < $count; $i++) {
            $from = (string) ($sortedRecords[$i - 1]['statusType'] ?? '');
            $to   = (string) ($sortedRecords[$i]['statusType'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }

            $isRework      = isset($visited[$to]);
            $transitions[] = [
                'from'     => $from,
                'to'       => $to,
                'isRework' => $isRework,
            ];
            $visited[$to]  = true;
        }

        return $transitions;
    }//end computeCaseTransitions()

    /**
     * Weekly throughput trend: cases closed (by `endDate`) per ISO week
     * within `[from, to]`.
     *
     * @param array<string, array<string, mixed>> $cases Case rows, keyed by id.
     * @param DateTimeImmutable                   $from  Inclusive period start.
     * @param DateTimeImmutable                   $to    Inclusive period end.
     *
     * @return array<int, array{week: string, count: int}>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function computeThroughputTrend(array $cases, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $buckets = [];

        // Seed every ISO week in range so gaps render as zero, not "missing".
        $cursor = $from->modify('monday this week');
        $end    = $to;
        while ($cursor <= $end) {
            $buckets[$cursor->format('o-\WW')] = 0;
            $cursor = $cursor->modify('+1 week');
        }

        foreach ($cases as $caseData) {
            $endDate = ($caseData['endDate'] ?? null);
            if (is_string($endDate) === false || $endDate === '') {
                continue;
            }

            $closedAt = $this->parseDate(value: $endDate, fallback: null);
            if ($closedAt === null || $closedAt < $from || $closedAt > $to) {
                continue;
            }

            $week = $closedAt->format('o-\WW');
            if (isset($buckets[$week]) === false) {
                $buckets[$week] = 0;
            }

            $buckets[$week]++;
        }//end foreach

        $out = [];
        foreach ($buckets as $week => $count) {
            $out[] = ['week' => $week, 'count' => $count];
        }

        ksort($out);
        usort($out, static fn (array $left, array $right): int => strcmp($left['week'], $right['week']));

        return $out;
    }//end computeThroughputTrend()

    /**
     * Group cases by their caseType, resolving the display title.
     *
     * @param array<string, array<string, mixed>> $cases         Case rows, keyed by id.
     * @param array<string, array<string, mixed>> $caseTypeIndex CaseType rows, keyed by id and slug.
     *
     * @return array<string, array{title: string, cases: array<string, array<string, mixed>>}>
     */
    private function groupCasesByType(array $cases, array $caseTypeIndex): array
    {
        $groups = [];
        foreach ($cases as $caseId => $caseData) {
            $caseTypeKey = (string) ($caseData['caseType'] ?? '');
            if ($caseTypeKey === '') {
                continue;
            }

            if (isset($groups[$caseTypeKey]) === false) {
                $title = $caseTypeKey;
                if (isset($caseTypeIndex[$caseTypeKey]) === true) {
                    $entry = $caseTypeIndex[$caseTypeKey];
                    $title = (string) ($entry['title'] ?? $caseTypeKey);
                }

                $groups[$caseTypeKey] = ['title' => $title, 'cases' => []];
            }

            $groups[$caseTypeKey]['cases'][$caseId] = $caseData;
        }//end foreach

        return $groups;
    }//end groupCasesByType()

    /**
     * Group and chronologically sort statusRecords by their `case` field,
     * restricted to the given set of case ids.
     *
     * @param array<int, array<string, mixed>> $records Raw statusRecord rows.
     * @param array<int, string>               $caseIds Case ids in scope.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupRecordsByCase(array $records, array $caseIds): array
    {
        $allowed = array_flip($caseIds);
        $grouped = [];
        foreach ($records as $record) {
            $caseId = (string) ($record['case'] ?? '');
            if ($caseId === '' || isset($allowed[$caseId]) === false) {
                continue;
            }

            $grouped[$caseId][] = $record;
        }

        foreach ($grouped as $caseId => $rows) {
            usort(
                $rows,
                function (array $left, array $right): int {
                    $leftAt  = $this->extractTimestamp(record: $left);
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
     * Extract a record's creation timestamp — either the flattened
     * `createdAt` key or OpenRegister's `@self.created` metadata block.
     *
     * @param array<string, mixed> $record A statusRecord row.
     *
     * @return DateTimeImmutable|null
     */
    private function extractTimestamp(array $record): ?DateTimeImmutable
    {
        $raw = ($record['createdAt'] ?? ($record['@self']['created'] ?? ($record['@self']['createdAt'] ?? null)));
        if (is_string($raw) === false || $raw === '') {
            return null;
        }

        return $this->parseDate(value: $raw, fallback: null);
    }//end extractTimestamp()

    /**
     * Resolve a statusType id to its human-readable label.
     *
     * @param string                              $statusId        StatusType UUID.
     * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
     *
     * @return string
     */
    private function statusLabel(string $statusId, array $statusTypeIndex): string
    {
        if (isset($statusTypeIndex[$statusId]) === false) {
            return $statusId;
        }

        $entry = $statusTypeIndex[$statusId];
        $label = ($entry['name'] ?? ($entry['title'] ?? ''));
        if (is_string($label) === true && $label !== '') {
            return $label;
        }

        return $statusId;
    }//end statusLabel()

    /**
     * Index a list of rows by their `id` field.
     *
     * @param array<int, array<string, mixed>> $rows Rows to index.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexById(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $index[$id] = $row;
            }
        }

        return $index;
    }//end indexById()

    /**
     * Index a list of rows by both `id` and `slug`, mirroring
     * {@see DoorlooptijdService::enrichCases()}'s caseType lookup so a case's
     * `caseType` field resolves whether it stores the UUID or the slug.
     *
     * @param array<int, array<string, mixed>> $rows Rows to index.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexByIdAndSlug(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $id   = (string) ($row['id'] ?? '');
            $slug = (string) ($row['slug'] ?? '');
            if ($id !== '') {
                $index[$id] = $row;
            }

            if ($slug !== '') {
                $index[$slug] = $row;
            }
        }

        return $index;
    }//end indexByIdAndSlug()

    /**
     * Percentile of a pre-sorted numeric list (nearest-rank method).
     *
     * @param array<int, float> $sorted     Ascending-sorted values.
     * @param float             $percentile Percentile in [0, 100].
     *
     * @return float
     */
    private static function percentile(array $sorted, float $percentile): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }

        if ($count === 1) {
            return $sorted[0];
        }

        $rank = (int) ceil(($percentile / 100.0) * $count);
        $rank = max(1, min($count, $rank));

        return $sorted[($rank - 1)];
    }//end percentile()

    /**
     * Parse a date/datetime string; return `$fallback` on empty/invalid input.
     *
     * @param mixed                  $value    Raw date value.
     * @param DateTimeImmutable|null $fallback Value to return when parsing fails.
     *
     * @return DateTimeImmutable|null
     */
    private function parseDate(mixed $value, ?DateTimeImmutable $fallback): ?DateTimeImmutable
    {
        if (is_string($value) === false || $value === '') {
            return $fallback;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }//end parseDate()

    /**
     * Load every case record via OpenRegister.
     *
     * @param string|null $caseTypeFilter Optional caseType filter (UUID or slug).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadCases(?string $caseTypeFilter): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $filters = ['_limit' => 2000];
        if ($caseTypeFilter !== null && $caseTypeFilter !== '') {
            $filters['caseType'] = $caseTypeFilter;
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: $filters,
        );
    }//end loadCases()

    /**
     * Load all caseType definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadCaseTypes(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_type_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['_limit' => 500],
        );
    }//end loadCaseTypes()

    /**
     * Load all statusType definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadStatusTypes(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('status_type_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['_limit' => 500],
        );
    }//end loadStatusTypes()

    /**
     * Load statusRecord rows — the same register {@see StatusTransitionService}
     * writes on every transition. No `case` filter: process mining reads
     * across the whole case population, then groups in-memory (mirrors
     * {@see StatusTransitionService::replay()}'s single-case read, scaled up).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadStatusRecords(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('status_record_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['_limit' => 10000],
        );
    }//end loadStatusRecords()
}//end class
