<?php

/**
 * Procest Doorlooptijd (throughput-time) Service
 *
 * Aggregates KPIs, monthly compliance, case-type breakdown and the open-case
 * list for the throughput-time dashboard. All metrics are computed from the
 * Procest `case` register via OpenRegister's ObjectService — no separate
 * analytics store. See `openspec/changes/doorlooptijd-dashboard/design.md`.
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
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Computes throughput-time metrics for the case dashboard.
 */
class DoorlooptijdService
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
     * Compute the full metrics payload for the dashboard.
     *
     * @param array<string, mixed> $params Query parameters from the controller.
     *
     * @return array<string, mixed> The structured response body.
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
     */
    public function getMetrics(array $params): array
    {
        if (isset($params['caseType']) === true && is_string($params['caseType']) === true) {
            $caseTypeFilter = $params['caseType'];
        } else {
            $caseTypeFilter = null;
        }

        if (isset($params['period']) === true && is_string($params['period']) === true) {
            $period = $params['period'];
        } else {
            $period = '12m';
        }

        if (isset($params['atRiskDays']) === true) {
            $atRiskDays = (int) $params['atRiskDays'];
        } else {
            $atRiskDays = 5;
        }

        if ($atRiskDays < 0) {
            $atRiskDays = 0;
        }

        $cases     = $this->loadCases(caseTypeFilter: $caseTypeFilter);
        $caseTypes = $this->loadCaseTypes();

        $enriched = $this->enrichCases(cases: $cases, caseTypes: $caseTypes);

        return [
            'kpi'               => $this->computeKpi(cases: $enriched, atRiskDays: $atRiskDays),
            'compliance'        => $this->computeMonthlyCompliance(cases: $enriched, period: $period),
            'caseTypeBreakdown' => $this->computeCaseTypeBreakdown(cases: $enriched, caseTypes: $caseTypes),
            'cases'             => $this->buildCaseList(cases: $enriched, atRiskDays: $atRiskDays),
        ];
    }//end getMetrics()

    /**
     * Compute the four headline KPIs.
     *
     * @param array<int, array<string, mixed>> $cases      Enriched cases.
     * @param int                              $atRiskDays Threshold for at-risk band.
     *
     * @return array{open: int, atRisk: int, overdue: int, onTimePercent: int}
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
     */
    public function computeKpi(array $cases, int $atRiskDays): array
    {
        $open    = 0;
        $atRisk  = 0;
        $overdue = 0;
        foreach ($cases as $caseData) {
            if ($caseData['_isOpen'] !== true) {
                continue;
            }

            $open++;
            $daysRemaining = $caseData['_daysRemaining'];
            if ($daysRemaining === null) {
                continue;
            }

            if ($daysRemaining < 0) {
                $overdue++;
            } else if ($daysRemaining <= $atRiskDays) {
                $atRisk++;
            }
        }//end foreach

        // Closed cases in the last 12 months.
        $cutoff       = (new DateTimeImmutable('-12 months'))->format('Y-m-d');
        $closedOnTime = 0;
        $closedLate   = 0;
        foreach ($cases as $caseData) {
            if ($caseData['_isOpen'] === true) {
                continue;
            }

            if ($caseData['_endDate'] === null || $caseData['_endDate'] < $cutoff) {
                continue;
            }

            if ($caseData['_deadline'] === null) {
                continue;
            }

            if ($caseData['_endDate'] <= $caseData['_deadline']) {
                $closedOnTime++;
            } else {
                $closedLate++;
            }
        }//end foreach

        $totalClosed = ($closedOnTime + $closedLate);
        if ($totalClosed === 0) {
            $onTimePercent = 100;
        } else {
            $onTimePercent = (int) round(($closedOnTime / $totalClosed) * 100);
        }

        return [
            'open'          => $open,
            'atRisk'        => $atRisk,
            'overdue'       => $overdue,
            'onTimePercent' => $onTimePercent,
        ];
    }//end computeKpi()

    /**
     * Monthly on-time / late counts over the requested period.
     *
     * @param array<int, array<string, mixed>> $cases  Enriched cases.
     * @param string                           $period Period spec (e.g. `12m`, `6m`, `3m`).
     *
     * @return array<int, array{month: string, onTime: int, late: int, percent: int}>
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
     */
    public function computeMonthlyCompliance(array $cases, string $period): array
    {
        $months  = $this->parseMonths(period: $period);
        $buckets = [];

        $endDate = new DateTimeImmutable('first day of this month');
        for ($i = ($months - 1); $i >= 0; $i--) {
            $month           = $endDate->modify('-'.$i.' month')->format('Y-m');
            $buckets[$month] = ['onTime' => 0, 'late' => 0];
        }

        foreach ($cases as $caseData) {
            if ($caseData['_endDate'] === null || $caseData['_deadline'] === null) {
                continue;
            }

            $month = substr($caseData['_endDate'], 0, 7);
            if (isset($buckets[$month]) === false) {
                continue;
            }

            if ($caseData['_endDate'] <= $caseData['_deadline']) {
                $buckets[$month]['onTime']++;
            } else {
                $buckets[$month]['late']++;
            }
        }//end foreach

        $out = [];
        foreach ($buckets as $month => $counts) {
            $total = ($counts['onTime'] + $counts['late']);
            if ($total === 0) {
                $percent = 100;
            } else {
                $percent = (int) round(($counts['onTime'] / $total) * 100);
            }

            $out[] = [
                'month'   => $month,
                'onTime'  => $counts['onTime'],
                'late'    => $counts['late'],
                'percent' => $percent,
            ];
        }

        return $out;
    }//end computeMonthlyCompliance()

    /**
     * Average closed-case throughput by case-type.
     *
     * @param array<int, array<string, mixed>> $cases     Enriched cases.
     * @param array<int, array<string, mixed>> $caseTypes Indexed case-type metadata.
     *
     * @return array<int, array{id: string, title: string, avgDays: int, count: int}>
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
     */
    public function computeCaseTypeBreakdown(array $cases, array $caseTypes): array
    {
        $accum = [];
        foreach ($cases as $caseData) {
            if ($caseData['_isOpen'] === true || $caseData['_throughputDays'] === null) {
                continue;
            }

            $caseTypeId = (string) ($caseData['caseType'] ?? '');
            if ($caseTypeId === '') {
                continue;
            }

            if (isset($accum[$caseTypeId]) === false) {
                $accum[$caseTypeId] = ['sum' => 0, 'count' => 0];
            }

            $accum[$caseTypeId]['sum'] += $caseData['_throughputDays'];
            $accum[$caseTypeId]['count']++;
        }

        $caseTypeIndex = [];
        foreach ($caseTypes as $caseType) {
            $id = (string) ($caseType['id'] ?? '');
            if ($id !== '') {
                $caseTypeIndex[$id] = $caseType;
            }
        }

        $out = [];
        foreach ($accum as $caseTypeId => $stats) {
            if ($stats['count'] === 0) {
                continue;
            }

            if (isset($caseTypeIndex[$caseTypeId]['title']) === true) {
                $title = (string) $caseTypeIndex[$caseTypeId]['title'];
            } else {
                $title = $caseTypeId;
            }

            $out[] = [
                'id'      => $caseTypeId,
                'title'   => $title,
                'avgDays' => (int) round($stats['sum'] / $stats['count']),
                'count'   => $stats['count'],
            ];
        }

        usort(
            $out,
            static fn (array $left, array $right): int => ($right['avgDays'] <=> $left['avgDays'])
        );

        return $out;
    }//end computeCaseTypeBreakdown()

    /**
     * Build the sortable list of open cases with RAG status.
     *
     * @param array<int, array<string, mixed>> $cases      Enriched cases.
     * @param int                              $atRiskDays Threshold for at-risk band.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01
     */
    public function buildCaseList(array $cases, int $atRiskDays): array
    {
        $rows = [];
        foreach ($cases as $caseData) {
            if ($caseData['_isOpen'] !== true) {
                continue;
            }

            $daysRemaining = $caseData['_daysRemaining'];
            $ragStatus     = 'on-time';
            if ($daysRemaining !== null) {
                if ($daysRemaining < 0) {
                    $ragStatus = 'overdue';
                } else if ($daysRemaining <= $atRiskDays) {
                    $ragStatus = 'at-risk';
                }
            }

            $rows[] = [
                'id'            => (string) ($caseData['id'] ?? ''),
                'identifier'    => (string) ($caseData['identifier'] ?? ''),
                'title'         => (string) ($caseData['title'] ?? ''),
                'caseTypeTitle' => (string) ($caseData['_caseTypeTitle'] ?? ''),
                'startDate'     => $caseData['_startDate'],
                'deadline'      => $caseData['_deadline'],
                'daysRemaining' => $daysRemaining,
                'ragStatus'     => $ragStatus,
            ];
        }//end foreach

        usort(
            $rows,
            static function (array $left, array $right): int {
                $leftValue  = $left['daysRemaining'] ?? PHP_INT_MAX;
                $rightValue = $right['daysRemaining'] ?? PHP_INT_MAX;
                return ($leftValue <=> $rightValue);
            }
        );

        return $rows;
    }//end buildCaseList()

    /**
     * Enrich each raw case with derived fields used by the metric helpers.
     *
     * @param array<int, array<string, mixed>> $cases     Raw cases.
     * @param array<int, array<string, mixed>> $caseTypes Raw case-types.
     *
     * @return array<int, array<string, mixed>>
     */
    public function enrichCases(array $cases, array $caseTypes): array
    {
        $today         = new DateTimeImmutable('today');
        $caseTypeByKey = [];
        foreach ($caseTypes as $caseType) {
            $id   = (string) ($caseType['id'] ?? '');
            $slug = (string) ($caseType['slug'] ?? '');
            if ($id !== '') {
                $caseTypeByKey[$id] = $caseType;
            }

            if ($slug !== '') {
                $caseTypeByKey[$slug] = $caseType;
            }
        }

        $enriched = [];
        foreach ($cases as $caseData) {
            $endDate   = $this->normaliseDate(value: $caseData['endDate'] ?? null);
            $startDate = $this->normaliseDate(value: $caseData['startDate'] ?? null);
            $isOpen    = ($endDate === null);

            $caseType      = null;
            $caseTypeTitle = '';
            $caseTypeKey   = (string) ($caseData['caseType'] ?? '');
            if ($caseTypeKey !== '' && isset($caseTypeByKey[$caseTypeKey]) === true) {
                $caseType      = $caseTypeByKey[$caseTypeKey];
                $caseTypeTitle = (string) ($caseType['title'] ?? '');
            }

            $deadline = $this->normaliseDate(value: $caseData['deadline'] ?? null);
            if ($deadline === null && $startDate !== null && $caseType !== null) {
                $deadline = $this->deriveDeadline(
                    startDate: $startDate,
                    processingDeadline: (string) ($caseType['processingDeadline'] ?? '')
                );
            }

            $daysRemaining  = null;
            $throughputDays = null;
            if ($isOpen === true && $deadline !== null) {
                $deadlineDate  = new DateTimeImmutable($deadline);
                $daysRemaining = (int) $today->diff($deadlineDate)->format('%R%a');
            }

            if ($isOpen === false && $startDate !== null && $endDate !== null) {
                $throughputDays = (int) (new DateTimeImmutable($startDate))
                    ->diff(new DateTimeImmutable($endDate))->format('%R%a');
                if ($throughputDays < 0) {
                    $throughputDays = 0;
                }
            }

            $caseData['_isOpen']         = $isOpen;
            $caseData['_startDate']      = $startDate;
            $caseData['_endDate']        = $endDate;
            $caseData['_deadline']       = $deadline;
            $caseData['_daysRemaining']  = $daysRemaining;
            $caseData['_throughputDays'] = $throughputDays;
            $caseData['_caseTypeTitle']  = $caseTypeTitle;
            $enriched[] = $caseData;
        }//end foreach

        return $enriched;
    }//end enrichCases()

    /**
     * Translate a period string into a month count (`12m` → 12, `6m` → 6).
     *
     * @param string $period Period spec.
     *
     * @return int
     */
    private function parseMonths(string $period): int
    {
        if (preg_match('/^(\d+)m$/', $period, $matches) === 1) {
            $months = (int) $matches[1];
            if ($months >= 1 && $months <= 36) {
                return $months;
            }
        }

        return 12;
    }//end parseMonths()

    /**
     * Compute a deadline from a start-date + ISO 8601 duration (e.g. `P8W`).
     *
     * Falls back to null when the duration can't be parsed.
     *
     * @param string $startDate          Y-m-d date.
     * @param string $processingDeadline ISO 8601 duration spec.
     *
     * @return string|null
     */
    private function deriveDeadline(string $startDate, string $processingDeadline): ?string
    {
        if ($processingDeadline === '') {
            return null;
        }

        try {
            $start = new DateTimeImmutable($startDate);
            return $start->add(new \DateInterval($processingDeadline))->format('Y-m-d');
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Could not derive deadline from processingDeadline',
                ['duration' => $processingDeadline, 'error' => $e->getMessage()]
            );
            return null;
        }
    }//end deriveDeadline()

    /**
     * Trim a date or datetime field to `Y-m-d`; return null for empty/invalid input.
     *
     * @param mixed $value Raw date value.
     *
     * @return string|null
     */
    private function normaliseDate(mixed $value): ?string
    {
        if (is_string($value) === false || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }//end normaliseDate()

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

        $filters = ['_limit' => 1000];
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
     * Load all caseType definitions so the service can resolve titles and
     * derived deadlines.
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
}//end class
