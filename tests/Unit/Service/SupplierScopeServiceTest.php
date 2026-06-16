<?php

/**
 * SupplierScopeService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/leverancier-zaakportaal-04-supplier-scope-security/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\TenantJwtService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\SupplierScopeService
 */
class SupplierScopeServiceTest extends TestCase
{
    private SupplierScopeService $svc;
    private TenantJwtService $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new TenantJwtService('test-secret-32-chars-long-please!!');
        $this->svc = new SupplierScopeService(
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            jwt: $this->jwt,
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testValidateSupplierAccessAllowsMatch(): void
    {
        $this->assertTrue($this->svc->validateSupplierAccess(['supplierRef' => 's-1'], 's-1'));
    }

    public function testValidateSupplierAccessRejectsMismatch(): void
    {
        $this->assertFalse($this->svc->validateSupplierAccess(['supplierRef' => 's-1'], 's-2'));
    }

    public function testValidateSupplierAccessRejectsMissingRef(): void
    {
        $this->assertFalse($this->svc->validateSupplierAccess([], 's-1'));
    }

    public function testMaskIbanShowsLastFour(): void
    {
        $this->assertSame('**************0000', $this->svc->maskIban('NL00BANK0000000000'));
    }

    public function testMaskIbanHandlesShort(): void
    {
        $this->assertSame('****', $this->svc->maskIban('AB1'));
    }

    public function testMaskEmailKeepsDomain(): void
    {
        $this->assertSame('***@example.nl', $this->svc->maskEmail('alice@example.nl'));
        $this->assertSame('***', $this->svc->maskEmail('not-an-email'));
    }

    public function testMaskPhoneKeepsLastThree(): void
    {
        $this->assertSame('***********890', $this->svc->maskPhone('+31 20 123 4567890'));
        $this->assertSame('***', $this->svc->maskPhone('12'));
    }

    public function testMaskSensitiveAppliesAllMasks(): void
    {
        $masked = $this->svc->maskSensitive([
            'iban'  => 'NL00BANK0000000000',
            'email' => 'alice@example.nl',
            'phone' => '0612345678',
            'name'  => 'Alice',
        ]);
        $this->assertSame('**************0000', $masked['iban']);
        $this->assertSame('***@example.nl', $masked['email']);
        $this->assertSame('*******678', $masked['phone'], 'mask 10 digits keeps last 3');
        $this->assertSame('Alice', $masked['name']);
    }

    public function testListSupplierObjectsRejectsNonSupplierSchema(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->listSupplierObjects('s-1', 'tenant');
    }

    public function testResolveFromBearerRejectsMalformed(): void
    {
        $this->assertNull($this->svc->resolveFromBearer('Basic abc'));
        $this->assertNull($this->svc->resolveFromBearer('Bearer not-a-jwt'));
    }

    public function testResolveFromBearerExtractsSupplierRole(): void
    {
        $tok = $this->jwt->createToken('user-1', 'tenant-1', 'acme', ['supplier:finance', 'eh:level:3']);
        $r   = $this->svc->resolveFromBearer('Bearer '.$tok);
        $this->assertSame('user-1', $r['supplierUserId']);
        $this->assertSame('finance', $r['role']);
        $this->assertSame('acme', $r['supplierRef']);
    }

    public function testResolveFromBearerDefaultsToReadOnly(): void
    {
        $tok = $this->jwt->createToken('user-1', 't-1', 'acme', []);
        $r   = $this->svc->resolveFromBearer('Bearer '.$tok);
        $this->assertSame('read_only', $r['role']);
    }
}
