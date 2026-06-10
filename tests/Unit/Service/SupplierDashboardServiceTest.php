<?php

/**
 * SupplierDashboardService Unit Tests
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ContractRenewalService;
use OCA\Procest\Service\InvoicePaymentForecastService;
use OCA\Procest\Service\LeverancierViewModelService;
use OCA\Procest\Service\SupplierDashboardService;
use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\SupplierDashboardService
 */
class SupplierDashboardServiceTest extends TestCase
{
    private SupplierDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $logger = $this->createMock(LoggerInterface::class);
        $scope  = $this->createMock(SupplierScopeService::class);
        $audit  = new TenantAuditTrailService($logger);
        $this->svc = new SupplierDashboardService(
            scopeService: $scope,
            invoiceForecast: new InvoicePaymentForecastService($scope, $logger),
            contractRenewal: new ContractRenewalService($scope, $audit, $this->createMock(IAppManager::class), $this->createMock(ContainerInterface::class), $logger),
            viewModel: new LeverancierViewModelService(),
        );
    }

    public function testSummariseTendersGroupsByStatus(): void
    {
        $tenders = [
            ['status' => 'submitted'],
            ['status' => 'awarded'],
            ['status' => 'rejected'],
            ['status' => 'evaluating'],
            ['status' => 'rejected'],
        ];
        $r = $this->svc->summariseTenders($tenders);
        $this->assertSame(5, $r['count']);
        $this->assertSame(1, $r['awarded']);
        $this->assertSame(1, $r['evaluating']);
        $this->assertSame(2, $r['rejected']);
    }

    public function testSummariseInvoicesCountsOverdueAndDisputed(): void
    {
        $now = strtotime('2026-04-01');
        $invoices = [
            ['status' => 'received', 'dueDate' => '2025-12-01'], // 121d overdue
            ['status' => 'disputed', 'dueDate' => '2026-03-25'],
            ['status' => 'paid', 'dueDate' => '2020-01-01'],
        ];
        $r = $this->svc->summariseInvoices($invoices, $now);
        $this->assertSame(3, $r['count']);
        $this->assertSame(1, $r['overdue90Plus']);
        $this->assertSame(1, $r['disputed']);
        $this->assertArrayHasKey('ageAnalysis', $r);
    }

    public function testSummariseContractsCountsExpiringAndAuto(): void
    {
        $now = strtotime('2026-01-01');
        $contracts = [
            ['endDate' => '2026-03-01', 'renewalOption' => 'manual_request'], // in window
            ['endDate' => '2027-01-01', 'renewalOption' => 'auto'],            // not in window
            ['endDate' => '2026-12-31', 'renewalOption' => 'auto'],            // not in window
        ];
        $r = $this->svc->summariseContracts($contracts, $now);
        $this->assertSame(3, $r['count']);
        $this->assertSame(1, $r['expiringSoon']);
        $this->assertSame(2, $r['autoRenewing']);
    }
}
