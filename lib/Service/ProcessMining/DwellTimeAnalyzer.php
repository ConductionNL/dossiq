<?php

/**
 * Procest DwellTimeAnalyzer.
 *
 * The dwell-time metric family of the process-mining report: how long cases
 * sit in each status, and which statuses that makes the worst bottlenecks.
 * Split out of ProcessMiningService so that service keeps only the
 * orchestration — the per-visit interval reconstruction, the
 * median/p90/mean aggregation and the bottleneck ranking (dwell time x case
 * volume) live here and nowhere else.
 *
 * Pure computation: every input is passed in, nothing is read from
 * OpenRegister, so the whole family is exercisable without a register.
 *
 * @category Service
 * @package  OCA\Procest\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Service\ProcessMining;

use DateTimeImmutable;

/**
 * Reconstructs per-status dwell intervals and ranks the resulting bottlenecks.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class DwellTimeAnalyzer
{
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
            if (count($records) === 0) {
                continue;
            }

            $case     = ($casesById[$caseId] ?? []);
            $endDate  = ($case['endDate'] ?? null);
            $closedAt = null;
            if (is_string($endDate) === true && $endDate !== '') {
                $closedAt = $this->parseDate(value: $endDate, fallback: $now);
            }

            $intervals = array_merge(
                $intervals,
                $this->dwellIntervalsForCase(
                    records: $records,
                    caseId: (string) $caseId,
                    closedAt: $closedAt,
                    now: $now,
                    windowStart: $windowStart,
                    windowEnd: $windowEnd,
                )
            );
        }//end foreach

        return $intervals;
    }//end computeDwellIntervals()

    /**
     * Build the dwell-time intervals for a single case's chronologically sorted statusRecords.
     *
     * Only visits ENTERED within `[windowStart, windowEnd]` are returned; the exit boundary of the
     * final visit is the case's close moment when it has one, and `$now` otherwise.
     *
     * @param array<int, array<string, mixed>> $records     Chronologically sorted statusRecords for one case.
     * @param string                           $caseId      The case id.
     * @param DateTimeImmutable|null           $closedAt    The case's close moment, or null when still open.
     * @param DateTimeImmutable                $now         "Now", for the still-open current status.
     * @param DateTimeImmutable                $windowStart Inclusive window start.
     * @param DateTimeImmutable                $windowEnd   Inclusive window end.
     *
     * @return array<int, array{caseId: string, statusId: string, hours: float}>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    private function dwellIntervalsForCase(
        array $records,
        string $caseId,
        ?DateTimeImmutable $closedAt,
        DateTimeImmutable $now,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): array {
        $count     = count($records);
        $intervals = [];

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

            $exitedAt = ($closedAt ?? $now);
            if (($i + 1) < $count) {
                $exitedAt = $this->extractTimestamp(record: $records[$i + 1]);
            }

            if ($exitedAt === null) {
                $exitedAt = $enteredAt;
            }

            $hours = (($exitedAt->getTimestamp() - $enteredAt->getTimestamp()) / 3600.0);
            if ($hours < 0.0) {
                $hours = 0.0;
            }

            $intervals[] = [
                'caseId'   => $caseId,
                'statusId' => $statusId,
                'hours'    => $hours,
            ];
        }//end for

        return $intervals;
    }//end dwellIntervalsForCase()

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

    /**
     * Rank statuses by bottleneck severity: median dwell time x visit volume.
     * Highest score first.
     *
     * Each `$dwellStats` row is the shape {@see self::aggregateDwellStats()}
     * returns: statusId, statusName, visitCount, medianHours, p90Hours,
     * meanHours. Spelled as a loose shape here only to keep the tag on one
     * line — PHPCS's PEAR sniff cannot parse a wrapped `@param`.
     *
     * @param array<int, array<string, mixed>> $dwellStats Per-status dwell stats.
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

    /**
     * Resolve a statusType id to its human-readable label.
     *
     * @param string                              $statusId        StatusType UUID.
     * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
     *
     * @return string
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
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
     * Extract a record's creation timestamp — either the flattened
     * `createdAt` key or OpenRegister's `@self.created` metadata block.
     *
     * @param array<string, mixed> $record A statusRecord row.
     *
     * @return DateTimeImmutable|null
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
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
     * Percentile of a pre-sorted numeric list (nearest-rank method).
     *
     * @param array<int, float> $sorted     Ascending-sorted values.
     * @param float             $percentile Percentile in [0, 100].
     *
     * @return float
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
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
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
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
}//end class
