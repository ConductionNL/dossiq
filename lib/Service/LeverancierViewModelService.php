<?php

/**
 * Procest Leverancier View-Model Service
 *
 * Centralises the presentation-layer constants the supplier portal Vue
 * components consume (status-badge maps for invoices/contracts/tenders,
 * age-analysis bucket colours, KPI comparison thresholds). Lives server-
 * side so an automated test can prove the contract without spinning up
 * a Vue runtime.
 *
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-08-invoice-frontend/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-10-contract-frontend/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-14-kpi-frontend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * Supplier portal view-model helpers.
 */
class LeverancierViewModelService
{
    /**
     * Invoice status → badge colour. Used by InvoiceList + InvoiceDetail.
     *
     * @var array<string, string>
     */
    public const INVOICE_BADGE_COLORS = [
        'received'     => 'gray',
        'under_review' => 'blue',
        'approved'     => 'green',
        'disputed'     => 'orange',
        'rejected'     => 'red',
        'paid'         => 'green',
    ];

    /**
     * Age-analysis bucket colours.
     *
     * @var array<string, string>
     */
    public const AGE_BUCKET_COLORS = [
        '0-30'  => 'green',
        '31-60' => 'yellow',
        '61-90' => 'orange',
        '90+'   => 'red',
    ];

    /**
     * Renewal-option → human label (Dutch).
     *
     * @var array<string, string>
     */
    public const RENEWAL_OPTION_LABELS = [
        'auto'           => 'Automatisch verlengd',
        'manual_request' => 'Verlenging op verzoek',
        'none'           => 'Geen verlenging',
    ];

    /**
     * KPI benchmark comparison icons.
     *
     * @var array<string, string>
     */
    public const BENCHMARK_INDICATORS = [
        'better' => 'arrow-up-green',
        'same'   => 'minus-gray',
        'worse'  => 'arrow-down-red',
    ];

    /**
     * Invoice status → badge colour.
     *
     * @param string $status Invoice status.
     *
     * @return string
     */
    public function invoiceBadgeColor(string $status): string
    {
        return self::INVOICE_BADGE_COLORS[$status] ?? 'gray';
    }//end invoiceBadgeColor()

    /**
     * Whether an invoice is 90+ days overdue.
     *
     * @param array<string,mixed> $invoice Invoice row.
     * @param int                 $nowTs   Reference timestamp.
     *
     * @return bool
     */
    public function isOverdue90Plus(array $invoice, int $nowTs): bool
    {
        $status = (string) ($invoice['status'] ?? '');
        if (in_array($status, ['paid', 'rejected'], true) === true) {
            return false;
        }

        $due = strtotime((string) ($invoice['dueDate'] ?? ''));
        if ($due === false) {
            return false;
        }

        $age = (int) floor(($nowTs - $due) / 86400);
        return $age > 90;
    }//end isOverdue90Plus()

    /**
     * Show "Verlenging aanvragen" button? Only when renewalOption='manual_request'
     * AND contract is within the 90-day window AND not already requested.
     *
     * @param array<string,mixed> $contract Contract row.
     * @param int                 $nowTs    Reference timestamp.
     *
     * @return bool
     */
    public function showRenewalButton(array $contract, int $nowTs): bool
    {
        if ((string) ($contract['renewalOption'] ?? '') !== 'manual_request') {
            return false;
        }

        $end = strtotime((string) ($contract['endDate'] ?? ''));
        if ($end === false) {
            return false;
        }

        $days = (int) floor(($end - $nowTs) / 86400);
        return $days >= 0 && $days <= 90;
    }//end showRenewalButton()

    /**
     * Renewal-option human label.
     *
     * @param string $option Renewal option.
     *
     * @return string
     */
    public function renewalOptionLabel(string $option): string
    {
        return self::RENEWAL_OPTION_LABELS[$option] ?? $option;
    }//end renewalOptionLabel()

    /**
     * Compare own metric to benchmark — return indicator key.
     *
     * @param float  $own    Own metric value.
     * @param float  $bench  Benchmark value.
     * @param string $metric Metric key (drives the "lower-is-better" sign).
     *
     * @return string Indicator key ('better' | 'same' | 'worse').
     */
    public function benchmarkComparison(float $own, float $bench, string $metric): string
    {
        $lowerBetter = in_array($metric, ['avgPaymentDays', 'disputeRate'], true);
        $diff        = ($own - $bench);
        if (abs($diff) < 0.5) {
            return 'same';
        }

        if ($lowerBetter === true) {
            if ($diff < 0) {
                return 'better';
            }

            return 'worse';
        }

        if ($diff > 0) {
            return 'better';
        }

        return 'worse';
    }//end benchmarkComparison()

    /**
     * Whether a chart data point should be plotted (skip insufficientData months).
     *
     * @param array<string,mixed> $kpiRow KPI row.
     *
     * @return bool
     */
    public function shouldPlotPoint(array $kpiRow): bool
    {
        return (bool) ($kpiRow['sufficientData'] ?? false);
    }//end shouldPlotPoint()
}//end class
