<?php

/**
 * InvoicePaymentForecastService Unit Tests
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-07-invoice-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\InvoicePaymentForecastService;
use OCA\Procest\Service\SupplierScopeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\InvoicePaymentForecastService
 */
class InvoicePaymentForecastServiceTest extends TestCase
{
    private InvoicePaymentForecastService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new InvoicePaymentForecastService(
            scopeService: $this->createMock(SupplierScopeService::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testCalculateExpectedPaymentDateAddsRoutingPlusTerms(): void
    {
        $r = $this->svc->calculateExpectedPaymentDate(['invoiceDate' => '2026-01-01'], 7, 30);
        $this->assertSame('2026-02-07', $r);
    }

    public function testCalculateExpectedPaymentDateUsesFallback(): void
    {
        $r = $this->svc->calculateExpectedPaymentDate(['invoiceDate' => '2026-01-01']);
        // Default routing 5 + default terms 30 = 35 days
        $this->assertSame('2026-02-05', $r);
    }

    public function testCalculateExpectedPaymentDateNullForBadDate(): void
    {
        $this->assertNull($this->svc->calculateExpectedPaymentDate(['invoiceDate' => 'not-a-date']));
    }

    public function testAgeAnalysisBucketsBoundaries(): void
    {
        $now = strtotime('2026-04-01');
        // Build invoices with dueDate at known ages from today.
        $invoices = [
            ['status' => 'received', 'dueDate' => (new \DateTimeImmutable('@'.$now))->modify('-10 days')->format('Y-m-d'), 'amount' => 100],   // 10 days → 0-30
            ['status' => 'received', 'dueDate' => (new \DateTimeImmutable('@'.$now))->modify('-45 days')->format('Y-m-d'), 'amount' => 100],   // 45 → 31-60
            ['status' => 'received', 'dueDate' => (new \DateTimeImmutable('@'.$now))->modify('-75 days')->format('Y-m-d'), 'amount' => 100],   // 75 → 61-90
            ['status' => 'received', 'dueDate' => (new \DateTimeImmutable('@'.$now))->modify('-120 days')->format('Y-m-d'), 'amount' => 100],  // 120 → 90+
            ['status' => 'paid',     'dueDate' => (new \DateTimeImmutable('@'.$now))->modify('-20 days')->format('Y-m-d'), 'amount' => 999],   // skipped
        ];
        $r = $this->svc->getAgeAnalysis($invoices, $now);
        $this->assertSame(1, $r['0-30']['count']);
        $this->assertSame(1, $r['31-60']['count']);
        $this->assertSame(1, $r['61-90']['count']);
        $this->assertSame(1, $r['90+']['count']);
        $this->assertSame(25.0, $r['0-30']['percentage']);
    }

    public function testFilterOverdueByThreshold(): void
    {
        $now      = strtotime('2026-04-01');
        $invoices = [
            ['status' => 'received', 'dueDate' => (new \DateTimeImmutable('@'.$now))->modify('-91 days')->format('Y-m-d')],
            ['status' => 'received', 'dueDate' => (new \DateTimeImmutable('@'.$now))->modify('-1 days')->format('Y-m-d')],
            ['status' => 'paid',     'dueDate' => (new \DateTimeImmutable('@'.$now))->modify('-180 days')->format('Y-m-d')],
        ];
        $over = $this->svc->filterOverdueByThreshold($invoices, 90, $now);
        $this->assertSame(1, count($over));
    }

    public function testBuildDisputeUpdateSetsFields(): void
    {
        $update = $this->svc->buildDisputeUpdate(['status' => 'received'], 'aantal verschilt', 'alice');
        $this->assertSame('disputed', $update['status']);
        $this->assertSame('aantal verschilt', $update['disputeReason']);
    }
}
