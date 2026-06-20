<?php

/**
 * Procest Invoice Payment Forecast Service
 *
 * Computes the expected payment date for a supplier invoice and produces
 * an age analysis with 30/60/90-day buckets.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-07-invoice-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Forecast service.
 */
class InvoicePaymentForecastService
{
    /**
     * Default mandate-routing delay (days) when Decidesk is not reachable.
     */
    public const DEFAULT_ROUTING_DAYS_FALLBACK = 5;

    /**
     * Default payment terms in days when the invoice carries none.
     */
    public const DEFAULT_PAYMENT_TERMS_DAYS = 30;

    public function __construct(
        private readonly SupplierScopeService $scopeService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute the expected payment date.
     *
     * @param array<string,mixed> $invoice            Invoice row.
     * @param int|null            $mandateRoutingDays Days the mandate routing adds (null → fallback).
     * @param int|null            $paymentTermsDays   Days for payment terms (null → DEFAULT_PAYMENT_TERMS_DAYS).
     *
     * @return string|null ISO date.
     */
    public function calculateExpectedPaymentDate(array $invoice, ?int $mandateRoutingDays=null, ?int $paymentTermsDays=null): ?string
    {
        $invoiceDate = strtotime((string) ($invoice['invoiceDate'] ?? ''));
        if ($invoiceDate === false) {
            return null;
        }

        $routing = ($mandateRoutingDays ?? self::DEFAULT_ROUTING_DAYS_FALLBACK);
        $terms   = ($paymentTermsDays ?? self::DEFAULT_PAYMENT_TERMS_DAYS);
        $sum     = ($routing + $terms);
        return (new DateTimeImmutable('@'.$invoiceDate))->modify('+'.$sum.' days')->format('Y-m-d');
    }//end calculateExpectedPaymentDate()

    /**
     * Bucket open invoices by age. Returns counts + amount totals + percentages.
     *
     * @param array<int, array<string,mixed>> $invoices Invoices.
     * @param int                             $nowTs    Reference timestamp.
     *
     * @return array<string, array{count:int, amount:float, percentage:float}>
     */
    public function getAgeAnalysis(array $invoices, int $nowTs): array
    {
        $buckets     = [
            '0-30'  => ['count' => 0, 'amount' => 0.0],
            '31-60' => ['count' => 0, 'amount' => 0.0],
            '61-90' => ['count' => 0, 'amount' => 0.0],
            '90+'   => ['count' => 0, 'amount' => 0.0],
        ];
        $totalAmount = 0.0;
        foreach ($invoices as $inv) {
            if (in_array((string) ($inv['status'] ?? ''), ['paid', 'rejected'], true) === true) {
                continue;
            }

            $due = strtotime((string) ($inv['dueDate'] ?? ''));
            if ($due === false) {
                continue;
            }

            $age    = (int) floor(($nowTs - $due) / 86400);
            $amount = (float) ($inv['amount'] ?? 0);
            $bucket = '0-30';
            if ($age > 90) {
                $bucket = '90+';
            } else if ($age > 60) {
                $bucket = '61-90';
            } else if ($age > 30) {
                $bucket = '31-60';
            }

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['amount'] += $amount;
            $totalAmount += $amount;
        }//end foreach

        foreach ($buckets as $k => $b) {
            $buckets[$k]['percentage'] = $totalAmount > 0 ? round(($b['amount'] / $totalAmount) * 100, 2) : 0.0;
        }

        return $buckets;
    }//end getAgeAnalysis()

    /**
     * Filter invoices that have been overdue for more than the threshold.
     *
     * @param array<int, array<string,mixed>> $invoices      Invoices.
     * @param int                             $thresholdDays Threshold.
     * @param int                             $nowTs         Reference timestamp.
     *
     * @return array<int, array<string,mixed>>
     */
    public function filterOverdueByThreshold(array $invoices, int $thresholdDays, int $nowTs): array
    {
        $out = [];
        foreach ($invoices as $inv) {
            if ((string) ($inv['status'] ?? '') === 'paid') {
                continue;
            }

            $due = strtotime((string) ($inv['dueDate'] ?? ''));
            if ($due === false) {
                continue;
            }

            $age = (int) floor(($nowTs - $due) / 86400);
            if ($age > $thresholdDays) {
                $out[] = $inv;
            }
        }

        return $out;
    }//end filterOverdueByThreshold()

    /**
     * Audit an invoice dispute write.
     *
     * @param array<string,mixed> $invoice Invoice.
     * @param string              $reason  Dispute reason.
     * @param string              $actor   Actor user ID.
     *
     * @return array<string,mixed>
     */
    public function buildDisputeUpdate(array $invoice, string $reason, string $actor): array
    {
        $invoice['status']        = 'disputed';
        $invoice['disputeReason'] = $reason;
        return $invoice;
    }//end buildDisputeUpdate()
}//end class
