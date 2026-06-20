<?php

/**
 * Procest Supplier Dashboard Service
 *
 * Aggregates the four dashboard cards (tenders, invoices, contracts, KPI)
 * for the supplier portal landing page.
 *
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * Dashboard summary aggregation.
 */
class SupplierDashboardService
{
    /**
     * Constructor.
     *
     * @param SupplierScopeService          $scopeService    Supplier scope service.
     * @param InvoicePaymentForecastService $invoiceForecast Invoice forecast service.
     * @param ContractRenewalService        $contractRenewal Contract renewal service.
     * @param LeverancierViewModelService   $viewModel       View model service.
     */
    public function __construct(
        private readonly SupplierScopeService $scopeService,
        private readonly InvoicePaymentForecastService $invoiceForecast,
        private readonly ContractRenewalService $contractRenewal,
        private readonly LeverancierViewModelService $viewModel,
    ) {
    }//end __construct()

    /**
     * Build the dashboard summary for a supplier.
     *
     * @param string $supplierRef Supplier UUID.
     * @param int    $nowTs       Reference timestamp.
     *
     * @return array{
     *   tenders: array{count:int, awarded:int, evaluating:int, rejected:int},
     *   invoices: array{count:int, overdue90Plus:int, disputed:int, ageAnalysis:array<string, array<string,mixed>>},
     *   contracts: array{count:int, expiringSoon:int, autoRenewing:int},
     *   kpi: array{ready:bool, period:string},
     * }
     */
    public function buildSummary(string $supplierRef, int $nowTs): array
    {
        $tenders   = $this->scopeService->listSupplierObjects($supplierRef, 'supplierTender');
        $invoices  = $this->scopeService->listSupplierObjects($supplierRef, 'supplierInvoice');
        $contracts = $this->scopeService->listSupplierObjects($supplierRef, 'supplierContract');

        return [
            'tenders'   => $this->summariseTenders(tenders: $tenders),
            'invoices'  => $this->summariseInvoices(invoices: $invoices, nowTs: $nowTs),
            'contracts' => $this->summariseContracts(contracts: $contracts, nowTs: $nowTs),
            'kpi'       => [
                'ready'  => count($invoices) >= 3,
                'period' => (new \DateTimeImmutable('@'.$nowTs))->format('Y-m'),
            ],
        ];
    }//end buildSummary()

    /**
     * Tender card.
     *
     * @param array<int, array<string,mixed>> $tenders Tenders.
     *
     * @return array{count:int, awarded:int, evaluating:int, rejected:int}
     */
    public function summariseTenders(array $tenders): array
    {
        $awarded    = 0;
        $evaluating = 0;
        $rejected   = 0;
        foreach ($tenders as $t) {
            switch ((string) ($t['status'] ?? '')) {
                case 'awarded':    $awarded++;

                    break;
                case 'evaluating': $evaluating++;

                    break;
                case 'rejected':   $rejected++;

                    break;
            }
        }

        return ['count' => count($tenders), 'awarded' => $awarded, 'evaluating' => $evaluating, 'rejected' => $rejected];
    }//end summariseTenders()

    /**
     * Invoice card.
     *
     * @param array<int, array<string,mixed>> $invoices Invoices.
     * @param int                             $nowTs    Reference timestamp.
     *
     * @return array{count:int, overdue90Plus:int, disputed:int, ageAnalysis:array<string, array<string,mixed>>}
     */
    public function summariseInvoices(array $invoices, int $nowTs): array
    {
        $overdue  = 0;
        $disputed = 0;
        foreach ($invoices as $inv) {
            if ($this->viewModel->isOverdue90Plus($inv, $nowTs) === true) {
                $overdue++;
            }

            if ((string) ($inv['status'] ?? '') === 'disputed') {
                $disputed++;
            }
        }

        return [
            'count'         => count($invoices),
            'overdue90Plus' => $overdue,
            'disputed'      => $disputed,
            'ageAnalysis'   => $this->invoiceForecast->getAgeAnalysis($invoices, $nowTs),
        ];
    }//end summariseInvoices()

    /**
     * Contract card.
     *
     * @param array<int, array<string,mixed>> $contracts Contracts.
     * @param int                             $nowTs     Reference timestamp.
     *
     * @return array{count:int, expiringSoon:int, autoRenewing:int}
     */
    public function summariseContracts(array $contracts, int $nowTs): array
    {
        $expiring     = 0;
        $autoRenewing = 0;
        foreach ($contracts as $c) {
            if ($this->contractRenewal->isWithinRenewalWindow($c, $nowTs) === true) {
                $expiring++;
            }

            if ((string) ($c['renewalOption'] ?? '') === 'auto') {
                $autoRenewing++;
            }
        }

        return ['count' => count($contracts), 'expiringSoon' => $expiring, 'autoRenewing' => $autoRenewing];
    }//end summariseContracts()
}//end class
