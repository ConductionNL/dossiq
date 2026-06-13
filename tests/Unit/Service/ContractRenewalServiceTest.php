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
 * Minimal ObjectService stub with named-parameter signatures.
 *
 * The real OpenRegister ObjectService is resolved at runtime and called with
 * named arguments; a \stdClass mock generates positional-only signatures and
 * fails with "Unknown named parameter". This typed interface lets PHPUnit
 * generate a mock whose signatures accept the named args.
 */
interface ContractRenewalObjectServiceStub
{
    /**
     * List objects (real ObjectService::findAll()).
     *
     * @param string              $register Register slug.
     * @param string              $schema   Schema slug.
     * @param int                 $limit    Page size.
     * @param int                 $offset   Page offset.
     * @param array<string,mixed> $filters  Filters.
     *
     * @return array<int,mixed>
     */
    public function findAll(string $register, string $schema, int $limit=200, int $offset=0, array $filters=[]): array;

    /**
     * Save or update an object (real ObjectService::saveObject()).
     *
     * @param array<string,mixed> $object   Object data.
     * @param string              $register Register slug.
     * @param string              $schema   Schema slug.
     * @param string|null         $uuid     Optional UUID for updates.
     *
     * @return array<string,mixed>
     */
    public function saveObject(array $object, string $register, string $schema, ?string $uuid=null): array;
}//end interface

/**
 * @covers \OCA\Procest\Service\ContractRenewalService
 */
class ContractRenewalServiceTest extends TestCase
{

    private ContractRenewalService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $audit     = new TenantAuditTrailService($this->createMock(LoggerInterface::class));
        $this->svc = new ContractRenewalService(
            scopeService: $this->createMock(SupplierScopeService::class),
            auditTrail: $audit,
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    public function testDaysUntilExpiry(): void
    {
        $now = strtotime('2026-01-01');
        $this->assertSame(30, $this->svc->daysUntilExpiry('2026-01-31', $now));
        $this->assertSame(-10, $this->svc->daysUntilExpiry('2025-12-22', $now));
        $this->assertNull($this->svc->daysUntilExpiry('not-a-date', $now));
    }//end testDaysUntilExpiry()

    public function testIsWithinRenewalWindowAt90Day(): void
    {
        $now = strtotime('2026-01-01');
        $this->assertTrue($this->svc->isWithinRenewalWindow(['endDate' => '2026-04-01'], $now));
        // 90 days
        $this->assertTrue($this->svc->isWithinRenewalWindow(['endDate' => '2026-01-15'], $now));
        // 14 days
        $this->assertFalse($this->svc->isWithinRenewalWindow(['endDate' => '2026-05-01'], $now));
        // 120 days
        $this->assertFalse($this->svc->isWithinRenewalWindow(['endDate' => '2025-12-20'], $now));
        // expired
    }//end testIsWithinRenewalWindowAt90Day()

    public function testScanExpiringContractsFlagsOnce(): void
    {
        $now       = strtotime('2026-01-01');
        $contracts = [
            ['endDate' => '2026-02-15', 'renewalWarning' => false],
            ['endDate' => '2026-12-31', 'renewalWarning' => false],
            ['endDate' => '2026-01-15', 'renewalWarning' => true],
        ];
        $r         = $this->svc->scanExpiringContracts($contracts, $now);
        $this->assertSame(1, count($r), 'only the never-flagged in-window contract gets returned');
        $this->assertTrue($r[0]['renewalWarning']);
    }//end testScanExpiringContractsFlagsOnce()

    public function testCanRequestRenewalRespectsRole(): void
    {
        $this->assertTrue($this->svc->canRequestRenewal('admin'));
        $this->assertTrue($this->svc->canRequestRenewal('contracts'));
        $this->assertFalse($this->svc->canRequestRenewal('finance'));
        $this->assertFalse($this->svc->canRequestRenewal('read_only'));
        $this->assertFalse($this->svc->canRequestRenewal('sales'));
    }//end testCanRequestRenewalRespectsRole()

    public function testRequestRenewalReturnsFailureWithoutOpenRegister(): void
    {
        // Without OR available, requestRenewal can't even reach the endDate check.
        $r = $this->svc->requestRenewal(['endDate' => '2026-12-31'], 'alice');
        $this->assertFalse($r['ok']);
        $this->assertNotSame('', $r['reason']);
    }//end testRequestRenewalReturnsFailureWithoutOpenRegister()

    public function testScanAndFlagExpiringNoOpWithoutOpenRegister(): void
    {
        // Default svc has an IAppManager mock returning no installed apps.
        $r = $this->svc->scanAndFlagExpiring(strtotime('2026-01-01'));
        $this->assertSame(['scanned' => 0, 'flagged' => 0], $r);
    }//end testScanAndFlagExpiringNoOpWithoutOpenRegister()

    public function testScanAndFlagExpiringPersistsOnlyInWindowRows(): void
    {
        $now       = strtotime('2026-01-01');
        $contracts = [
            ['uuid' => 'c1', 'endDate' => '2026-02-15', 'renewalWarning' => false],
        // in window → flag
            ['uuid' => 'c2', 'endDate' => '2026-12-31', 'renewalWarning' => false],
        // far out → skip
            ['uuid' => 'c3', 'endDate' => '2026-01-20', 'renewalWarning' => true],
        // already flagged → skip
        ];

        $os = $this->createMock(ContractRenewalObjectServiceStub::class);
        $os->expects($this->once())->method('findAll')->willReturn($contracts);
        // Only c1 should be persisted.
        $os->expects($this->once())->method('saveObject')
            ->with(
                $this->callback(fn ($o) => ($o['uuid'] ?? '') === 'c1' && ($o['renewalWarning'] ?? false) === true),
                'procest',
                'supplierContract',
                'c1'
            )
            ->willReturn([]);

        $svc = $this->buildServiceWithObjectService($os);
        $r   = $svc->scanAndFlagExpiring($now);
        $this->assertSame(3, $r['scanned']);
        $this->assertSame(1, $r['flagged']);
    }//end testScanAndFlagExpiringPersistsOnlyInWindowRows()

    /**
     * Build a ContractRenewalService whose container resolves OR to the given
     * ObjectService stub and whose app-manager reports OR installed.
     *
     * @param object $objectService ObjectService stub mock.
     *
     * @return ContractRenewalService
     */
    private function buildServiceWithObjectService(object $objectService): ContractRenewalService
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['openregister']);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        return new ContractRenewalService(
            scopeService: $this->createMock(SupplierScopeService::class),
            auditTrail: new TenantAuditTrailService($this->createMock(LoggerInterface::class)),
            appManager: $appManager,
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end buildServiceWithObjectService()
}//end class
