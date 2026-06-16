<?php

/**
 * SupplierAuthService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/leverancier-zaakportaal-02-eherkenning-auth/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\Auth\BrokerAssertionResult;
use OCA\Procest\Service\Auth\EHerkenningSamlAdapterInterface;
use OCA\Procest\Service\SupplierAuthService;
use OCA\Procest\Service\TenantJwtService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\SupplierAuthService
 */
class SupplierAuthServiceTest extends TestCase
{
    private SupplierAuthService $svc;
    private TenantJwtService $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new TenantJwtService('test-secret-32-chars-long-please!!');
        $this->svc = new SupplierAuthService(
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            jwt: $this->jwt,
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testValidateKvKClaimRejectsBadFormat(): void
    {
        $r = $this->svc->validateKvKClaim('abc');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ongeldig formaat', $r['reason']);
    }

    public function testValidateKvKClaimRejectsUnknownSupplier(): void
    {
        $r = $this->svc->validateKvKClaim('12345678');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Onbekende leverancier', $r['reason']);
    }

    public function testIssueSessionTokenIncludesSupplierRole(): void
    {
        $r = $this->svc->issueSessionToken(
            supplierUserId: 'su-1',
            supplier: ['tenantRef' => 'tenant-1', 'slug' => 'acme'],
            claim: ['role' => 'finance', 'eherkenningLevel' => '3'],
            financialReauth: true
        );

        $claims = $this->jwt->validate($r['token']);
        $this->assertContains('supplier:finance', $claims['roles']);
        $this->assertContains('eh:level:3', $claims['roles']);
        $this->assertSame('su-1', $claims['sub']);
        $this->assertTrue($r['financialReauthRequired']);
        $this->assertSame(SupplierAuthService::SESSION_TTL, $r['expiresIn']);
    }

    public function testNeedsRefreshTrueInWindow(): void
    {
        $now = time();
        $this->assertTrue($this->svc->needsRefresh($now + 60));
    }

    public function testNeedsRefreshFalseOutsideWindow(): void
    {
        $now = time();
        $this->assertFalse($this->svc->needsRefresh($now + 7000));
    }

    public function testAuthenticateRejectsEmptyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->authenticateViaEHerkenning('');
    }

    public function testAuthenticateThrowsWithoutBroker(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->authenticateViaEHerkenning('any-code');
    }

    public function testFindSupplierByKvkReturnsNullWhenOrUnavailable(): void
    {
        $this->assertNull($this->svc->findSupplierByKvk('12345678'));
    }

    public function testCreateSessionFromEHerkenningReturnsErrorWhenAdapterMissing(): void
    {
        // Default ctor in setUp() omits the adapter — make sure the absence is reported gracefully.
        $r = $this->svc->createSessionFromEHerkenning('saml-resp', 'relay-1');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('niet geconfigureerd', $r['reason']);
    }

    public function testCreateSessionFromEHerkenningReturnsErrorWhenAdapterThrows(): void
    {
        $adapter = $this->createMock(EHerkenningSamlAdapterInterface::class);
        $adapter->method('decodeAssertion')->willThrowException(new RuntimeException('broker offline'));

        $svc = new SupplierAuthService(
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            jwt: $this->jwt,
            logger: $this->createMock(LoggerInterface::class),
            eherkenningAdapter: $adapter,
        );

        $r = $svc->createSessionFromEHerkenning('saml-resp', 'relay-1');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('broker offline', $r['reason']);
    }

    public function testCreateSessionFromEHerkenningStopsOnUnknownSupplier(): void
    {
        $adapter = $this->createMock(EHerkenningSamlAdapterInterface::class);
        $adapter->method('decodeAssertion')->willReturn(
            BrokerAssertionResult::forEHerkenning(
                kvkNummer: '99887766',
                assertionId: 'asrt-1',
                level: 3,
                issuer: 'https://broker.example/eh',
                attributes: ['email' => 'partner@example.com'],
            )
        );

        $svc = new SupplierAuthService(
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            jwt: $this->jwt,
            logger: $this->createMock(LoggerInterface::class),
            eherkenningAdapter: $adapter,
        );

        $r = $svc->createSessionFromEHerkenning('saml-resp', 'relay-2');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Onbekende leverancier', $r['reason']);
        $this->assertSame('99887766', $r['assertion']['kvkNummer']);
    }
}
