<?php

/**
 * Procest ThroughputTrendCalculator.
 *
 * The throughput metric family of the process-mining report: how many cases
 * were closed (by `endDate`) per ISO week within the reporting period. Split
 * out of ProcessMiningService so that service keeps only the orchestration.
 *
 * Every ISO week in range is seeded to zero first, so a week with no closures
 * renders as an explicit zero rather than a gap in the series — the leaf that
 * draws the trend line must not have to infer missing buckets.
 *
 * Pure computation: every input is passed in, nothing is read from
 * OpenRegister.
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
 * Computes the weekly closed-case throughput trend.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class ThroughputTrendCalculator
{
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
        // Seed every ISO week in range so gaps render as zero, not "missing".
        $buckets = $this->seedWeekBuckets(from: $from, to: $to);

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
     * Seed a zero-valued bucket for every ISO week that starts within `[from, to]`.
     *
     * @param DateTimeImmutable $from Inclusive period start.
     * @param DateTimeImmutable $to   Inclusive period end.
     *
     * @return array<string, int> Zero-valued buckets, keyed by ISO week ("o-\WW").
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    private function seedWeekBuckets(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $buckets = [];
        $cursor  = $from->modify('monday this week');
        while ($cursor <= $to) {
            $buckets[$cursor->format('o-\WW')] = 0;
            $cursor = $cursor->modify('+1 week');
        }

        return $buckets;
    }//end seedWeekBuckets()

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
