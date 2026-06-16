<?php

/**
 * SupplierUserManagementService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/leverancier-zaakportaal-03-rbac-user-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\SupplierUserManagementService;
use OCA\Procest\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\SupplierUserManagementService
 */
class SupplierUserManagementServiceTest extends TestCase
{
    private SupplierUserManagementService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $audit = new TenantAuditTrailService($this->createMock(LoggerInterface::class));
        $this->svc = new SupplierUserManagementService(
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            auditTrail: $audit,
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testAssertValidRoleAcceptsAllowed(): void
    {
        foreach (SupplierUserManagementService::ALLOWED_ROLES as $r) {
            $this->svc->assertValidRole($r);
        }

        $this->expectNotToPerformAssertions();
    }

    public function testAssertValidRoleRejectsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->assertValidRole('overlord');
    }

    public function testCanAccessTabHonoursMatrix(): void
    {
        $this->assertTrue($this->svc->canAccessTab('admin', 'team'));
        $this->assertFalse($this->svc->canAccessTab('finance', 'team'));
        $this->assertTrue($this->svc->canAccessTab('finance', 'invoices'));
        $this->assertFalse($this->svc->canAccessTab('read_only', 'team'));
        $this->assertTrue($this->svc->canAccessTab('read_only', 'messages'));
    }

    public function testGetTabsForUnknownRoleReturnsEmpty(): void
    {
        $this->assertSame([], $this->svc->getTabsForRole('overlord'));
    }

    public function testInviteRejectsBadEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->inviteSupplierUser('s-1', 'not-an-email', 'finance', 'admin');
    }

    public function testInviteRejectsBadRole(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->inviteSupplierUser('s-1', 'a@b.example', 'overlord', 'admin');
    }

    public function testIsInviteExpiredHonoursTtl(): void
    {
        $this->assertFalse($this->svc->isInviteExpired((new \DateTimeImmutable('-1 day'))->format(DATE_ATOM), 7));
        $this->assertTrue($this->svc->isInviteExpired((new \DateTimeImmutable('-30 days'))->format(DATE_ATOM), 7));
        $this->assertTrue($this->svc->isInviteExpired('not-a-date', 7));
    }

    public function testRoleTabMatrixCoversAllFiveRoles(): void
    {
        $this->assertSame(5, count(SupplierUserManagementService::ROLE_TAB_MATRIX));
        foreach (SupplierUserManagementService::ALLOWED_ROLES as $r) {
            $this->assertArrayHasKey($r, SupplierUserManagementService::ROLE_TAB_MATRIX);
        }
    }
}
