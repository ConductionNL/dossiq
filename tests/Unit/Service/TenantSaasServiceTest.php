<?php

/**
 * TenantSaasService Unit Tests
 *
 * Validates slug generation (lowercasing, hyphenation, 64-char cap) and
 * the lifecycle state machine (legal vs illegal transitions). Persistence
 * paths are not exercised here — they require a live OR; chain member 12
 * adds the integration tests.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\TenantAuditTrailService;
use OCA\Procest\Service\TenantSaasService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantSaasService
 */
class TenantSaasServiceTest extends TestCase
{
    private TenantSaasService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $appManager = $this->createMock(IAppManager::class);
        $container  = $this->createMock(ContainerInterface::class);
        $logger     = $this->createMock(LoggerInterface::class);

        $this->service = new TenantSaasService(
            appManager: $appManager,
            container: $container,
            logger: $logger,
            audit: new TenantAuditTrailService($this->createMock(LoggerInterface::class)),
            userSession: $this->createMock(IUserSession::class),
        );
    }//end setUp()

    /**
     * create() emits a `tenant.provisioned` audit row — the wiring that backs
     * the hardening-checklist `audit_logged_mutations` claim (procest#223 #2).
     *
     * @return void
     */
    public function testCreateEmitsProvisioningAuditRow(): void
    {
        $auditLogger = $this->createMock(LoggerInterface::class);
        $user        = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $service = $this->getMockBuilder(TenantSaasService::class)
            ->setConstructorArgs([
                $this->createMock(IAppManager::class),
                $this->createMock(ContainerInterface::class),
                $this->createMock(LoggerInterface::class),
                new TenantAuditTrailService($auditLogger),
                $userSession,
            ])
            ->onlyMethods(['slugExists', 'saveTenant'])
            ->getMock();
        $service->method('slugExists')->willReturn(false);
        $service->method('saveTenant')->willReturn(['id' => 't-1', 'slug' => 'gemeente-x', 'status' => 'onboarding']);

        $auditLogger->expects($this->once())
            ->method('info')
            ->with(
                'Procest AUDIT',
                $this->callback(static fn (array $e): bool => $e['action'] === 'tenant.provisioned' && $e['actor'] === 'admin')
            );

        $service->create('Gemeente X', '12345678', 'basic');
    }//end testCreateEmitsProvisioningAuditRow()

    /**
     * updateStatus() emits a `tenant.status_changed` audit row on every
     * status mutation.
     *
     * @return void
     */
    public function testUpdateStatusEmitsStatusChangeAuditRow(): void
    {
        $auditLogger = $this->createMock(LoggerInterface::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $service = $this->getMockBuilder(TenantSaasService::class)
            ->setConstructorArgs([
                $this->createMock(IAppManager::class),
                $this->createMock(ContainerInterface::class),
                $this->createMock(LoggerInterface::class),
                new TenantAuditTrailService($auditLogger),
                $userSession,
            ])
            ->onlyMethods(['getById', 'saveTenant'])
            ->getMock();
        $service->method('getById')->willReturn(['id' => 't-1', 'status' => 'onboarding']);
        $service->method('saveTenant')->willReturn(['id' => 't-1', 'status' => 'active']);

        $auditLogger->expects($this->once())
            ->method('info')
            ->with(
                'Procest AUDIT',
                $this->callback(static fn (array $e): bool => $e['action'] === 'tenant.status_changed' && $e['actor'] === 'system')
            );

        $service->updateStatus('t-1', 'active');
    }//end testUpdateStatusEmitsStatusChangeAuditRow()

    /**
     * Slugify lowercases and collapses non-alphanumerics into single hyphens.
     *
     * @return void
     */
    public function testSlugifyBasic(): void
    {
        $this->assertSame('gemeente-amsterdam', $this->service->slugify('Gemeente Amsterdam'));
    }//end testSlugifyBasic()

    /**
     * Slugify collapses multiple spaces, special chars, and trims hyphens.
     *
     * @return void
     */
    public function testSlugifyCollapses(): void
    {
        $this->assertSame('foo-bar-baz', $this->service->slugify('  Foo!!  --Bar??  Baz  '));
    }//end testSlugifyCollapses()

    /**
     * Slugify caps the slug at 64 chars.
     *
     * @return void
     */
    public function testSlugifyEnforces64CharCap(): void
    {
        $long  = str_repeat('a', 80);
        $slug  = $this->service->slugify($long);
        $this->assertLessThanOrEqual(64, mb_strlen($slug));
    }//end testSlugifyEnforces64CharCap()

    /**
     * Slugify handles unicode-letter input gracefully.
     *
     * @return void
     */
    public function testSlugifyHandlesUnicode(): void
    {
        $slug = $this->service->slugify('Café Münster');
        $this->assertNotSame('', $slug);
        $this->assertStringContainsString('café', $slug);
    }//end testSlugifyHandlesUnicode()

    /**
     * Legal transition: onboarding → active.
     *
     * @return void
     */
    public function testAssertLegalTransitionOnboardingToActive(): void
    {
        $this->service->assertLegalTransition('onboarding', 'active');
        $this->expectNotToPerformAssertions();
    }//end testAssertLegalTransitionOnboardingToActive()

    /**
     * Legal: active → suspended → active.
     *
     * @return void
     */
    public function testAssertLegalTransitionActiveSuspendedRoundTrip(): void
    {
        $this->service->assertLegalTransition('active', 'suspended');
        $this->service->assertLegalTransition('suspended', 'active');
        $this->expectNotToPerformAssertions();
    }//end testAssertLegalTransitionActiveSuspendedRoundTrip()

    /**
     * Legal: active → terminated.
     *
     * @return void
     */
    public function testAssertLegalTransitionActiveToTerminated(): void
    {
        $this->service->assertLegalTransition('active', 'terminated');
        $this->expectNotToPerformAssertions();
    }//end testAssertLegalTransitionActiveToTerminated()

    /**
     * Illegal: onboarding cannot jump to terminated.
     *
     * @return void
     */
    public function testAssertLegalTransitionRejectsOnboardingToTerminated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->assertLegalTransition('onboarding', 'terminated');
    }//end testAssertLegalTransitionRejectsOnboardingToTerminated()

    /**
     * Illegal: terminated is terminal — cannot resurrect.
     *
     * @return void
     */
    public function testAssertLegalTransitionRejectsTerminatedReactivation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->assertLegalTransition('terminated', 'active');
    }//end testAssertLegalTransitionRejectsTerminatedReactivation()

    /**
     * Illegal: no-op transition.
     *
     * @return void
     */
    public function testAssertLegalTransitionRejectsNoOp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->assertLegalTransition('active', 'active');
    }//end testAssertLegalTransitionRejectsNoOp()

    /**
     * Illegal: unknown source status.
     *
     * @return void
     */
    public function testAssertLegalTransitionRejectsUnknownSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->assertLegalTransition('purgatory', 'active');
    }//end testAssertLegalTransitionRejectsUnknownSource()

    /**
     * Lifecycle graph: onboarding has exactly one outgoing transition.
     *
     * @return void
     */
    public function testLifecycleGraphShape(): void
    {
        $g = $this->service->getLifecycleGraph();
        $this->assertSame(['active'], $g['onboarding']);
        $this->assertSame(['suspended', 'terminated'], $g['active']);
        $this->assertSame(['active', 'terminated'], $g['suspended']);
        $this->assertSame([], $g['terminated']);
    }//end testLifecycleGraphShape()

    /**
     * Create rejects invalid tier without touching OR.
     *
     * @return void
     */
    public function testCreateRejectsInvalidTier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid tier');
        $this->service->create(name: 'Test', kvkNumber: '12345678', tier: 'gold');
    }//end testCreateRejectsInvalidTier()
}//end class
