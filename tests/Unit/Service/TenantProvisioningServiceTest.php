<?php

/**
 * TenantProvisioningService Unit Tests
 *
 * Tests the schema-name builder (≤63 chars, prefix-shape, hyphen sanitisation)
 * and the rollback behaviour (drops the schema when `createSchema` step ran).
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\TenantProvisioningService;
use OCA\Procest\Service\TenantSaasService;
use OCA\Procest\Service\TenantSchemaProvisioner;
use OCA\Procest\Service\TenantSeedService;
use OCA\Procest\Service\TenantWelcomeMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantProvisioningService
 */
class TenantProvisioningServiceTest extends TestCase
{
    private TenantProvisioningService $service;
    private TenantSchemaProvisioner $schemaProvisioner;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $tenantSaas              = $this->createMock(TenantSaasService::class);
        $this->schemaProvisioner = $this->createMock(TenantSchemaProvisioner::class);
        $seed                    = $this->createMock(TenantSeedService::class);
        $mailer                  = $this->createMock(TenantWelcomeMailer::class);
        $logger                  = $this->createMock(LoggerInterface::class);

        $this->service = new TenantProvisioningService(
            tenantSaasService: $tenantSaas,
            schemaProvisioner: $this->schemaProvisioner,
            seedService: $seed,
            welcomeMailer: $mailer,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Schema name follows the documented shape: tenant_<uuid8>_<slug>.
     *
     * @return void
     */
    public function testBuildSchemaNameBasicShape(): void
    {
        $name = $this->service->buildSchemaName(
            uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            slug: 'amsterdam'
        );
        $this->assertSame('tenant_a1b2c3d4_amsterdam', $name);
    }//end testBuildSchemaNameBasicShape()

    /**
     * Schema name caps at 63 chars (PostgreSQL identifier limit).
     *
     * @return void
     */
    public function testBuildSchemaNameCapsAt63Chars(): void
    {
        $longSlug = str_repeat('x', 100);
        $name     = $this->service->buildSchemaName(
            uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            slug: $longSlug
        );
        $this->assertLessThanOrEqual(63, strlen($name));
        $this->assertStringStartsWith('tenant_a1b2c3d4_', $name);
    }//end testBuildSchemaNameCapsAt63Chars()

    /**
     * Hyphens in slug become underscores (PG identifier-safe).
     *
     * @return void
     */
    public function testBuildSchemaNameConvertsHyphensToUnderscores(): void
    {
        $name = $this->service->buildSchemaName(
            uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            slug: 'gemeente-amsterdam-test'
        );
        $this->assertSame('tenant_a1b2c3d4_gemeente_amsterdam_test', $name);
    }//end testBuildSchemaNameConvertsHyphensToUnderscores()

    /**
     * Empty uuid or slug throws.
     *
     * @return void
     */
    public function testBuildSchemaNameRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->buildSchemaName(uuid: '', slug: 'x');
    }//end testBuildSchemaNameRejectsEmpty()

    /**
     * Rollback drops the schema when `createSchema` step ran.
     *
     * @return void
     */
    public function testRollbackDropsSchemaWhenCreateSchemaRan(): void
    {
        $this->schemaProvisioner->expects($this->once())
            ->method('dropSchema')
            ->with('tenant_abc');

        $this->service->rollback('tenant_abc', ['createSchema', 'cloneApplicationTables']);
    }//end testRollbackDropsSchemaWhenCreateSchemaRan()

    /**
     * Rollback is a no-op when `createSchema` never ran.
     *
     * @return void
     */
    public function testRollbackNoOpWhenCreateSchemaNeverRan(): void
    {
        $this->schemaProvisioner->expects($this->never())->method('dropSchema');
        $this->service->rollback('tenant_abc', []);
    }//end testRollbackNoOpWhenCreateSchemaNeverRan()

    /**
     * getDefaultRoles returns the three documented roles.
     *
     * @return void
     */
    public function testGetDefaultRolesReturnsTriad(): void
    {
        $this->assertSame(
            ['tenant_admin', 'case_handler', 'viewer'],
            $this->service->getDefaultRoles()
        );
    }//end testGetDefaultRolesReturnsTriad()
}//end class
