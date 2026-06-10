<?php

/**
 * TenantAuthenticationService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\TenantAuthenticationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantAuthenticationService
 */
class TenantAuthenticationServiceTest extends TestCase
{
    private TenantAuthenticationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TenantAuthenticationService(
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testIsAllowedHonoursRoleSpecificAction(): void
    {
        $matrix = ['case_handler' => ['create' => true, 'edit' => true]];
        $this->assertTrue($this->svc->isAllowed($matrix, 'case_handler', 'create'));
        $this->assertFalse($this->svc->isAllowed($matrix, 'case_handler', 'delete'));
    }

    public function testIsAllowedHonoursWildcardAction(): void
    {
        $matrix = ['tenant_admin' => ['*' => true]];
        $this->assertTrue($this->svc->isAllowed($matrix, 'tenant_admin', 'delete'));
        $this->assertTrue($this->svc->isAllowed($matrix, 'tenant_admin', 'create'));
    }

    public function testIsAllowedHonoursWildcardRole(): void
    {
        $matrix = ['*' => ['view' => true]];
        $this->assertTrue($this->svc->isAllowed($matrix, 'viewer', 'view'));
        $this->assertFalse($this->svc->isAllowed($matrix, 'viewer', 'create'));
    }

    public function testIsAllowedFailsClosedOnMissingEntry(): void
    {
        $this->assertFalse($this->svc->isAllowed([], 'viewer', 'create'));
        $this->assertFalse($this->svc->isAllowed(['case_handler' => []], 'case_handler', 'create'));
    }

    public function testValidateMandateMatrixFailsClosedWhenOrUnavailable(): void
    {
        // OR unavailable → fail-closed decision (no allowed:true reachable).
        $decision = $this->svc->validateMandateMatrix(
            tenantId: 'tenant-1',
            userId: 'alice',
            action: 'edit'
        );
        $this->assertFalse($decision['allowed']);
        $this->assertNotSame('', $decision['reason']);
    }

    public function testIsAllowedConsidersAllCandidates(): void
    {
        // Role-specific entry denies, wildcard role entry allows → allow wins.
        $matrix = [
            'viewer' => ['edit' => false],
            '*'      => ['edit' => true],
        ];
        $this->assertTrue($this->svc->isAllowed($matrix, 'viewer', 'edit'));
    }
}
