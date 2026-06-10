<?php

/**
 * ContractRenewalService Unit Tests
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ContractRenewalService;
use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\ContractRenewalService
 */
class ContractRenewalServiceTest extends TestCase
{
    private ContractRenewalService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $audit = new TenantAuditTrailService($this->createMock(LoggerInterface::class));
        $this->svc = new ContractRenewalService(
            scopeService: $this->createMock(SupplierScopeService::class),
            auditTrail: $audit,
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testDaysUntilExpiry(): void
    {
        $now = strtotime('2026-01-01');
        $this->assertSame(30, $this->svc->daysUntilExpiry('2026-01-31', $now));
        $this->assertSame(-10, $this->svc->daysUntilExpiry('2025-12-22', $now));
        $this->assertNull($this->svc->daysUntilExpiry('not-a-date', $now));
    }

    public function testIsWithinRenewalWindowAt90Day(): void
    {
        $now = strtotime('2026-01-01');
        $this->assertTrue($this->svc->isWithinRenewalWindow(['endDate' => '2026-04-01'], $now)); // 90 days
        $this->assertTrue($this->svc->isWithinRenewalWindow(['endDate' => '2026-01-15'], $now)); // 14 days
        $this->assertFalse($this->svc->isWithinRenewalWindow(['endDate' => '2026-05-01'], $now)); // 120 days
        $this->assertFalse($this->svc->isWithinRenewalWindow(['endDate' => '2025-12-20'], $now)); // expired
    }

    public function testScanExpiringContractsFlagsOnce(): void
    {
        $now = strtotime('2026-01-01');
        $contracts = [
            ['endDate' => '2026-02-15', 'renewalWarning' => false],
            ['endDate' => '2026-12-31', 'renewalWarning' => false],
            ['endDate' => '2026-01-15', 'renewalWarning' => true],
        ];
        $r = $this->svc->scanExpiringContracts($contracts, $now);
        $this->assertSame(1, count($r), 'only the never-flagged in-window contract gets returned');
        $this->assertTrue($r[0]['renewalWarning']);
    }

    public function testCanRequestRenewalRespectsRole(): void
    {
        $this->assertTrue($this->svc->canRequestRenewal('admin'));
        $this->assertTrue($this->svc->canRequestRenewal('contracts'));
        $this->assertFalse($this->svc->canRequestRenewal('finance'));
        $this->assertFalse($this->svc->canRequestRenewal('read_only'));
        $this->assertFalse($this->svc->canRequestRenewal('sales'));
    }

    public function testRequestRenewalReturnsFailureWithoutOpenRegister(): void
    {
        // Without OR available, requestRenewal can't even reach the endDate check.
        $r = $this->svc->requestRenewal(['endDate' => '2026-12-31'], 'alice');
        $this->assertFalse($r['ok']);
        $this->assertNotSame('', $r['reason']);
    }
}
