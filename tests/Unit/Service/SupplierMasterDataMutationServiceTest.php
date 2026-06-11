<?php

/**
 * SupplierMasterDataMutationService Unit Tests
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\External\Kvk\KvkHandelsregisterAdapterInterface;
use OCA\Procest\Service\External\Kvk\KvkLookupResult;
use OCA\Procest\Service\SupplierMasterDataMutationService;
use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\SupplierMasterDataMutationService
 */
class SupplierMasterDataMutationServiceTest extends TestCase
{
    private SupplierMasterDataMutationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $audit = new TenantAuditTrailService($this->createMock(LoggerInterface::class));
        $scope = $this->createMock(SupplierScopeService::class);
        $scope->method('maskIban')->willReturnCallback(fn (string $i) => str_repeat('*', strlen($i) - 4).substr($i, -4));
        $this->svc = new SupplierMasterDataMutationService(
            scopeService: $scope,
            auditTrail: $audit,
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testValidIbanForCorrectDutchSample(): void
    {
        // RABO test IBAN with valid mod-97 check digits.
        $this->assertTrue($this->svc->isValidIban('NL91ABNA0417164300'));
    }

    public function testInvalidIbanForBadFormat(): void
    {
        $this->assertFalse($this->svc->isValidIban('not-an-iban'));
        $this->assertFalse($this->svc->isValidIban('NL00'));
    }

    public function testInvalidIbanForFailedChecksum(): void
    {
        $this->assertFalse($this->svc->isValidIban('NL00ABNA0417164300'));
    }

    public function testRequestIbanChangeRejectsBadIban(): void
    {
        $r = $this->svc->requestIbanChange('s-1', 'not-an-iban', 'alice');
        $this->assertFalse($r['ok']);
        $this->assertSame('Invalid IBAN', $r['reason']);
    }

    public function testRequestIbanChangeRefusesWithoutOpenRegister(): void
    {
        $r = $this->svc->requestIbanChange('s-1', 'NL91ABNA0417164300', 'alice');
        $this->assertFalse($r['ok']);
    }

    public function testUpdateAddressReturnsNullWhenOrUnavailable(): void
    {
        $this->assertNull($this->svc->updateAddress('s-1', ['street' => 'A'], 'alice'));
    }

    public function testSubmitForVerificationReturnsOkFalseWhenOrUnavailable(): void
    {
        $r = $this->svc->submitForVerification('s-1', 'accreditation', ['file:1'], 'alice');
        $this->assertFalse($r['ok']);
    }

    public function testValidateKvkRejectsBadFormat(): void
    {
        $r = $this->svc->validateKvk('abc');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('8 cijfers', $r['reason']);
    }

    public function testValidateKvkWithoutAdapterReturnsFormatOnly(): void
    {
        $r = $this->svc->validateKvk('12345678');
        $this->assertTrue($r['ok']);
        $this->assertSame('FORMAT_ONLY', $r['status']);
        $this->assertTrue($r['dormant']);
    }

    public function testValidateKvkWithDormantAdapterReturnsDeferred(): void
    {
        $audit = new TenantAuditTrailService($this->createMock(LoggerInterface::class));
        $scope = $this->createMock(SupplierScopeService::class);
        $kvk   = $this->createMock(KvkHandelsregisterAdapterInterface::class);
        $kvk->method('lookup')->willReturn(
            new KvkLookupResult(
                lookupStatus: 'LOOKUP_DEFERRED',
                kvkNumber: '12345678',
                entity: [],
                dormant: true,
                extras: ['reason' => 'no-outbound-connector-bound'],
            )
        );

        $svc = new SupplierMasterDataMutationService(
            scopeService: $scope,
            auditTrail: $audit,
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            kvkAdapter: $kvk,
        );

        $r = $svc->validateKvk('12345678', 'Z/2026/1');
        $this->assertTrue($r['ok']);
        $this->assertSame('LOOKUP_DEFERRED', $r['status']);
        $this->assertTrue($r['dormant']);
        $this->assertSame('no-outbound-connector-bound', $r['extras']['reason']);
    }

    public function testValidateKvkWithActiveAdapterReturnsEntity(): void
    {
        $audit = new TenantAuditTrailService($this->createMock(LoggerInterface::class));
        $scope = $this->createMock(SupplierScopeService::class);
        $kvk   = $this->createMock(KvkHandelsregisterAdapterInterface::class);
        $kvk->method('lookup')->willReturn(
            new KvkLookupResult(
                lookupStatus: 'FOUND',
                kvkNumber: '12345678',
                entity: ['statutaireNaam' => 'Conduction B.V.', 'rsin' => '850000000'],
                dormant: false,
            )
        );

        $svc = new SupplierMasterDataMutationService(
            scopeService: $scope,
            auditTrail: $audit,
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            kvkAdapter: $kvk,
        );

        $r = $svc->validateKvk('12345678');
        $this->assertTrue($r['ok']);
        $this->assertSame('FOUND', $r['status']);
        $this->assertFalse($r['dormant']);
        $this->assertSame('Conduction B.V.', $r['entity']['statutaireNaam']);
    }

    public function testValidateKvkWithNotFoundReturnsRejection(): void
    {
        $audit = new TenantAuditTrailService($this->createMock(LoggerInterface::class));
        $scope = $this->createMock(SupplierScopeService::class);
        $kvk   = $this->createMock(KvkHandelsregisterAdapterInterface::class);
        $kvk->method('lookup')->willReturn(
            new KvkLookupResult(
                lookupStatus: 'NOT_FOUND',
                kvkNumber: '99999999',
                entity: [],
                dormant: false,
            )
        );

        $svc = new SupplierMasterDataMutationService(
            scopeService: $scope,
            auditTrail: $audit,
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            kvkAdapter: $kvk,
        );

        $r = $svc->validateKvk('99999999');
        $this->assertFalse($r['ok']);
        $this->assertSame('NOT_FOUND', $r['status']);
    }
}
