<?php

/**
 * Procest Supplier KPI Aggregation Service
 *
 * Computes per-period KPI rollups for a supplier: average payment days,
 * on-time percentage, dispute rate, compliance score, and a municipal
 * benchmark over all suppliers in the same period.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-13-kpi-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * KPI aggregation service.
 */
class SupplierKpiAggregationService
{
    /**
     * Outlier threshold — payment-day values above this are excluded.
     */
    public const PAYMENT_DAYS_OUTLIER = 200;

    /**
     * Minimum invoice count for a metric to be considered sufficient data.
     */
    public const MIN_INVOICES_FOR_TREND = 3;

    /**
     * Calculate mean payment days (actual - invoice). Filters outliers.
     *
     * @param array<int, array<string,mixed>> $invoices Invoices.
     *
     * @return float|null Mean days or null when no qualifying rows.
     */
    public function calculatePaymentDaysMetric(array $invoices): ?float
    {
        $deltas = [];
        foreach ($invoices as $inv) {
            if ((string) ($inv['status'] ?? '') !== 'paid') {
                continue;
            }

            $invDate = strtotime((string) ($inv['invoiceDate'] ?? ''));
            $payDate = strtotime((string) ($inv['actualPaymentDate'] ?? ''));
            if ($invDate === false || $payDate === false) {
                continue;
            }

            $delta = (int) floor(($payDate - $invDate) / 86400);
            if ($delta < 0 || $delta > self::PAYMENT_DAYS_OUTLIER) {
                continue;
            }

            $deltas[] = $delta;
        }

        if (count($deltas) === 0) {
            return null;
        }

        return round(array_sum($deltas) / count($deltas), 2);
    }//end calculatePaymentDaysMetric()

    /**
     * On-time percentage — paid by dueDate / total.
     *
     * @param array<int, array<string,mixed>> $invoices Invoices.
     *
     * @return float
     */
    public function calculateOnTimePercentage(array $invoices): float
    {
        $paid   = 0;
        $onTime = 0;
        foreach ($invoices as $inv) {
            if ((string) ($inv['status'] ?? '') !== 'paid') {
                continue;
            }

            $paid++;
            $due    = strtotime((string) ($inv['dueDate'] ?? ''));
            $paid_d = strtotime((string) ($inv['actualPaymentDate'] ?? ''));
            if ($due !== false && $paid_d !== false && $paid_d <= $due) {
                $onTime++;
            }
        }

        return $paid === 0 ? 0.0 : round(($onTime / $paid) * 100, 2);
    }//end calculateOnTimePercentage()

    /**
     * Dispute rate — disputed / total.
     *
     * @param array<int, array<string,mixed>> $invoices Invoices.
     *
     * @return float
     */
    public function calculateDisputeRate(array $invoices): float
    {
        if (count($invoices) === 0) {
            return 0.0;
        }

        $disputed = 0;
        foreach ($invoices as $inv) {
            if ((string) ($inv['status'] ?? '') === 'disputed') {
                $disputed++;
            }
        }

        return round(($disputed / count($invoices)) * 100, 2);
    }//end calculateDisputeRate()

    /**
     * Weighted compliance score: 40% on-time, 30% dispute-free, 30% complete.
     *
     * @param float $onTimePct       On-time percentage.
     * @param float $disputeRate     Dispute rate.
     * @param float $completenessPct Field completeness percentage.
     *
     * @return float
     */
    public function calculateComplianceScore(float $onTimePct, float $disputeRate, float $completenessPct=100.0): float
    {
        return round(
            (0.4 * $onTimePct) + (0.3 * (100 - $disputeRate)) + (0.3 * $completenessPct),
            2
        );
    }//end calculateComplianceScore()

    /**
     * Aggregate all four KPIs + sufficientData flag.
     *
     * @param array<int, array<string,mixed>> $invoices Invoices for a single supplier+period.
     *
     * @return array{
     *   avgPaymentDays: float|null,
     *   onTimePercentage: float,
     *   disputeRate: float,
     *   complianceScore: float,
     *   sufficientData: bool,
     *   invoiceCount: int,
     * }
     */
    public function aggregateKpis(array $invoices): array
    {
        $payDays     = $this->calculatePaymentDaysMetric($invoices);
        $onTime      = $this->calculateOnTimePercentage($invoices);
        $disputeRate = $this->calculateDisputeRate($invoices);
        $compliance  = $this->calculateComplianceScore($onTime, $disputeRate);
        return [
            'avgPaymentDays'   => $payDays,
            'onTimePercentage' => $onTime,
            'disputeRate'      => $disputeRate,
            'complianceScore'  => $compliance,
            'sufficientData'   => count($invoices) >= self::MIN_INVOICES_FOR_TREND,
            'invoiceCount'     => count($invoices),
        ];
    }//end aggregateKpis()

    /**
     * Compute the municipal benchmark (mean of mean) across suppliers.
     *
     * @param array<int, array<string,mixed>> $perSupplierKpis Aggregate rows.
     *
     * @return array<string, float>
     */
    public function computeBenchmark(array $perSupplierKpis): array
    {
        $bench = ['avgPaymentDays' => [], 'onTimePercentage' => [], 'disputeRate' => [], 'complianceScore' => []];
        foreach ($perSupplierKpis as $row) {
            foreach ($bench as $k => &$arr) {
                if (isset($row[$k]) === true && $row[$k] !== null) {
                    $arr[] = (float) $row[$k];
                }
            }
        }

        $out = [];
        foreach ($bench as $k => $vals) {
            $out[$k] = count($vals) > 0 ? round(array_sum($vals) / count($vals), 2) : 0.0;
        }

        return $out;
    }//end computeBenchmark()

    /**
     * Build a CSV export of supplier KPIs.
     *
     * @param array<int, array<string,mixed>> $rows KPI rows.
     *
     * @return string CSV body with header.
     */
    public function buildCsvExport(array $rows): string
    {
        $headers = ['period', 'supplierRef', 'avgPaymentDays', 'onTimePercentage', 'disputeRate', 'complianceScore', 'sufficientData'];
        $lines   = [implode(',', $headers)];
        foreach ($rows as $r) {
            $lines[] = implode(
                    ',',
                    [
                        (string) ($r['period'] ?? ''),
                        (string) ($r['supplierRef'] ?? ''),
                        $this->fmtFloat($r['avgPaymentDays'] ?? null),
                        $this->fmtFloat($r['onTimePercentage'] ?? null),
                        $this->fmtFloat($r['disputeRate'] ?? null),
                        $this->fmtFloat($r['complianceScore'] ?? null),
                        (($r['sufficientData'] ?? false) === true) ? 'true' : 'false',
                    ]
                    );
        }

        return implode("\n", $lines)."\n";
    }//end buildCsvExport()

    /**
     * Format a float to 1 decimal (or empty for null).
     *
     * @param float|int|null $val Value.
     *
     * @return string
     */
    private function fmtFloat(float|int|null $val): string
    {
        if ($val === null) {
            return '';
        }

        return number_format((float) $val, 1, '.', '');
    }//end fmtFloat()
}//end class
