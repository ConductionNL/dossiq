<?php

/**
 * LeverancierViewModelService Unit Tests
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\LeverancierViewModelService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\LeverancierViewModelService
 */
class LeverancierViewModelServiceTest extends TestCase
{
    private LeverancierViewModelService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new LeverancierViewModelService();
    }

    public function testInvoiceBadgeColorsCoverAllStatuses(): void
    {
        $this->assertSame('gray', $this->svc->invoiceBadgeColor('received'));
        $this->assertSame('blue', $this->svc->invoiceBadgeColor('under_review'));
        $this->assertSame('green', $this->svc->invoiceBadgeColor('approved'));
        $this->assertSame('orange', $this->svc->invoiceBadgeColor('disputed'));
        $this->assertSame('red', $this->svc->invoiceBadgeColor('rejected'));
        $this->assertSame('green', $this->svc->invoiceBadgeColor('paid'));
        $this->assertSame('gray', $this->svc->invoiceBadgeColor('mystery'));
    }

    public function testIsOverdue90Plus(): void
    {
        $now = strtotime('2026-04-01');
        $this->assertTrue($this->svc->isOverdue90Plus(['status' => 'received', 'dueDate' => '2025-12-15'], $now));
        $this->assertFalse($this->svc->isOverdue90Plus(['status' => 'received', 'dueDate' => '2026-03-01'], $now));
        $this->assertFalse($this->svc->isOverdue90Plus(['status' => 'paid', 'dueDate' => '2020-01-01'], $now));
        $this->assertFalse($this->svc->isOverdue90Plus(['status' => 'received'], $now));
    }

    public function testShowRenewalButtonRequiresManualWithinWindow(): void
    {
        $now = strtotime('2026-04-01');
        $this->assertTrue($this->svc->showRenewalButton(['renewalOption' => 'manual_request', 'endDate' => '2026-06-01'], $now));
        $this->assertFalse($this->svc->showRenewalButton(['renewalOption' => 'manual_request', 'endDate' => '2026-12-31'], $now));
        $this->assertFalse($this->svc->showRenewalButton(['renewalOption' => 'auto', 'endDate' => '2026-06-01'], $now));
        $this->assertFalse($this->svc->showRenewalButton(['renewalOption' => 'manual_request', 'endDate' => '2025-12-01'], $now));
    }

    public function testRenewalOptionLabel(): void
    {
        $this->assertSame('Automatisch verlengd', $this->svc->renewalOptionLabel('auto'));
        $this->assertSame('Verlenging op verzoek', $this->svc->renewalOptionLabel('manual_request'));
        $this->assertSame('Geen verlenging', $this->svc->renewalOptionLabel('none'));
        $this->assertSame('unknown', $this->svc->renewalOptionLabel('unknown'));
    }

    public function testBenchmarkComparisonLowerBetter(): void
    {
        // avgPaymentDays: lower is better
        $this->assertSame('better', $this->svc->benchmarkComparison(20.0, 30.0, 'avgPaymentDays'));
        $this->assertSame('worse', $this->svc->benchmarkComparison(40.0, 30.0, 'avgPaymentDays'));
        $this->assertSame('same', $this->svc->benchmarkComparison(30.1, 30.0, 'avgPaymentDays'));
    }

    public function testBenchmarkComparisonHigherBetter(): void
    {
        // onTimePercentage: higher is better
        $this->assertSame('better', $this->svc->benchmarkComparison(95.0, 80.0, 'onTimePercentage'));
        $this->assertSame('worse', $this->svc->benchmarkComparison(70.0, 80.0, 'onTimePercentage'));
    }

    public function testShouldPlotPointHonoursSufficientData(): void
    {
        $this->assertTrue($this->svc->shouldPlotPoint(['sufficientData' => true]));
        $this->assertFalse($this->svc->shouldPlotPoint(['sufficientData' => false]));
        $this->assertFalse($this->svc->shouldPlotPoint([]));
    }
}
