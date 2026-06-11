<?php

/**
 * TenantOnboardingService Unit Tests
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\TenantOnboardingService;
use OCA\Procest\Service\TenantSaasService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantOnboardingService
 */
class TenantOnboardingServiceTest extends TestCase
{
    private TenantOnboardingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TenantOnboardingService(
            tenantSaasService: $this->createMock(TenantSaasService::class),
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testStepsConstantDeclaresSevenCanonicalSteps(): void
    {
        $this->assertCount(7, TenantOnboardingService::STEPS);
        $this->assertSame(
            ['contract', 'mandate_import', 'sso_setup', 'branding', 'zaaktype_selection', 'first_user', 'go_live'],
            TenantOnboardingService::STEPS
        );
    }

    public function testMarkStepCompleteRejectsUnknownStep(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown onboarding step');
        $this->svc->markStepComplete('tenant-1', 'not-a-step', 'alice');
    }

    public function testCreateOnboardingReturnsEmptyWhenOrUnavailable(): void
    {
        $this->assertSame([], $this->svc->createOnboarding('tenant-1'));
    }

    public function testGetProgressReturnsZeroWhenOrUnavailable(): void
    {
        $p = $this->svc->getProgress('tenant-1');
        $this->assertSame(0, $p['completed']);
        $this->assertSame(7, $p['total']);
        $this->assertSame(0.0, $p['fraction']);
        $this->assertSame([], $p['steps']);
    }

    public function testValidateGoLiveReportsMissingWhenOrUnavailable(): void
    {
        $r = $this->svc->validateGoLive('tenant-1');
        $this->assertFalse($r['ready']);
        $this->assertContains('openregister_unavailable', $r['missing']);
    }

    public function testActivateRefusesWhenNotReady(): void
    {
        $r = $this->svc->activate('tenant-1');
        $this->assertFalse($r['activated']);
        $this->assertArrayHasKey('missing', $r);
    }
}
