<?php

/**
 * SupplierKpiAggregationService Unit Tests
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-13-kpi-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SupplierKpiAggregationService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\SupplierKpiAggregationService
 */
class SupplierKpiAggregationServiceTest extends TestCase
{
    private SupplierKpiAggregationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SupplierKpiAggregationService();
    }

    public function testPaymentDaysMetricExcludesOutliers(): void
    {
        $invoices = [
            ['status' => 'paid', 'invoiceDate' => '2026-01-01', 'actualPaymentDate' => '2026-01-31'], // 30
            ['status' => 'paid', 'invoiceDate' => '2026-02-01', 'actualPaymentDate' => '2026-03-03'], // 30
            ['status' => 'paid', 'invoiceDate' => '2026-01-01', 'actualPaymentDate' => '2027-01-01'], // 365 → outlier
            ['status' => 'received', 'invoiceDate' => '2026-01-01', 'actualPaymentDate' => '2026-01-31'], // not paid → excluded
        ];
        $this->assertSame(30.0, $this->svc->calculatePaymentDaysMetric($invoices));
    }

    public function testPaymentDaysMetricReturnsNullWhenNoData(): void
    {
        $this->assertNull($this->svc->calculatePaymentDaysMetric([]));
    }

    public function testOnTimePercentage(): void
    {
        $invoices = [
            ['status' => 'paid', 'dueDate' => '2026-01-31', 'actualPaymentDate' => '2026-01-30'], // on time
            ['status' => 'paid', 'dueDate' => '2026-02-15', 'actualPaymentDate' => '2026-02-20'], // late
            ['status' => 'paid', 'dueDate' => '2026-03-15', 'actualPaymentDate' => '2026-03-15'], // on time
        ];
        $this->assertSame(66.67, $this->svc->calculateOnTimePercentage($invoices));
    }

    public function testDisputeRate(): void
    {
        $invoices = [
            ['status' => 'paid'],
            ['status' => 'disputed'],
            ['status' => 'received'],
            ['status' => 'disputed'],
        ];
        $this->assertSame(50.0, $this->svc->calculateDisputeRate($invoices));
        $this->assertSame(0.0, $this->svc->calculateDisputeRate([]));
    }

    public function testComplianceScoreWeights(): void
    {
        // 80% on-time, 10% dispute, 100% complete
        $r = $this->svc->calculateComplianceScore(80, 10, 100);
        // 0.4 * 80 + 0.3 * 90 + 0.3 * 100 = 32 + 27 + 30 = 89
        $this->assertSame(89.0, $r);
    }

    public function testAggregateKpisProducesAllMetricsAndSufficientDataFlag(): void
    {
        $invoices = [
            ['status' => 'paid', 'invoiceDate' => '2026-01-01', 'actualPaymentDate' => '2026-01-15', 'dueDate' => '2026-01-31'],
            ['status' => 'paid', 'invoiceDate' => '2026-02-01', 'actualPaymentDate' => '2026-02-15', 'dueDate' => '2026-02-28'],
            ['status' => 'disputed', 'invoiceDate' => '2026-03-01', 'dueDate' => '2026-03-31'],
        ];
        $r = $this->svc->aggregateKpis($invoices);
        $this->assertNotNull($r['avgPaymentDays']);
        $this->assertGreaterThan(0, $r['onTimePercentage']);
        $this->assertSame(33.33, $r['disputeRate']);
        $this->assertTrue($r['sufficientData']);
        $this->assertSame(3, $r['invoiceCount']);
    }

    public function testAggregateKpisMarksInsufficientDataBelowThree(): void
    {
        $r = $this->svc->aggregateKpis([['status' => 'received']]);
        $this->assertFalse($r['sufficientData']);
    }

    public function testBenchmarkMeansAcrossSuppliers(): void
    {
        $rows = [
            ['avgPaymentDays' => 20, 'onTimePercentage' => 90, 'disputeRate' => 5, 'complianceScore' => 85],
            ['avgPaymentDays' => 30, 'onTimePercentage' => 80, 'disputeRate' => 10, 'complianceScore' => 75],
        ];
        $b = $this->svc->computeBenchmark($rows);
        $this->assertSame(25.0, $b['avgPaymentDays']);
        $this->assertSame(85.0, $b['onTimePercentage']);
    }

    public function testCsvExportShape(): void
    {
        $csv = $this->svc->buildCsvExport([
            ['period' => '2026-03', 'supplierRef' => 's-1', 'avgPaymentDays' => 25.5, 'onTimePercentage' => 90.0, 'disputeRate' => 5.0, 'complianceScore' => 88.5, 'sufficientData' => true],
        ]);
        $this->assertStringStartsWith('period,supplierRef', $csv);
        $this->assertStringContainsString('25.5,90.0,5.0,88.5,true', $csv);
    }
}
