<?php

/**
 * ConsultationService Unit Tests
 *
 * Tests for the Procest ConsultationService — status machine, dependency
 * validation, blocking consultation detection, and overdue detection.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consultation-management/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ConsultationService;
use OCA\Procest\Service\SettingsService;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the ConsultationService class.
 *
 * @covers \OCA\Procest\Service\ConsultationService
 */
class ConsultationServiceTest extends TestCase
{

    /**
     * Mocked SettingsService.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mocked IUserSession.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * Mocked INotificationManager.
     *
     * @var INotificationManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private INotificationManager $notificationManager;

    /**
     * Mocked LoggerInterface.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * Service under test.
     *
     * @var ConsultationService
     */
    private ConsultationService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->service = new ConsultationService(
            $this->settingsService,
            $this->userSession,
            $this->notificationManager,
            $this->logger,
        );

    }//end setUp()


    /**
     * Test createConsultation throws when parentZaak is missing.
     *
     * @return void
     */
    public function testCreateConsultationThrowsWhenParentZaakMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('parentZaak is required');

        $this->service->createConsultation(
            data: ['adviesInstantie' => 'Brandweer', 'onderwerp' => 'Test'],
            requesterId: 'user1',
        );

    }//end testCreateConsultationThrowsWhenParentZaakMissing()


    /**
     * Test createConsultation throws when adviesInstantie is missing.
     *
     * @return void
     */
    public function testCreateConsultationThrowsWhenAdviesInstantieMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('adviesInstantie is required');

        $this->service->createConsultation(
            data: ['parentZaak' => 'zaak-uuid-001', 'onderwerp' => 'Test'],
            requesterId: 'user1',
        );

    }//end testCreateConsultationThrowsWhenAdviesInstantieMissing()


    /**
     * Test createConsultation throws when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testCreateConsultationThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister is not available');

        $this->service->createConsultation(
            data: [
                'parentZaak'      => 'zaak-uuid-001',
                'adviesInstantie' => 'Brandweer',
                'onderwerp'       => 'Brandveiligheid',
            ],
            requesterId: 'user1',
        );

    }//end testCreateConsultationThrowsWhenOpenRegisterUnavailable()


    /**
     * Test getConsultation returns null when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testGetConsultationReturnsNullWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $result = $this->service->getConsultation(id: 'some-uuid');

        $this->assertNull($result);

    }//end testGetConsultationReturnsNullWhenOpenRegisterUnavailable()


    /**
     * Test getConsultationsForCase returns empty array when OpenRegister unavailable.
     *
     * @return void
     */
    public function testGetConsultationsForCaseReturnsEmptyWhenUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $result = $this->service->getConsultationsForCase(caseId: 'zaak-uuid-001');

        $this->assertIsArray($result);
        $this->assertEmpty($result);

    }//end testGetConsultationsForCaseReturnsEmptyWhenUnavailable()


    /**
     * Test transitionStatus throws on invalid status.
     *
     * @return void
     */
    public function testTransitionStatusThrowsOnInvalidStatus(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid status');

        $this->service->transitionStatus(
            consultationId: 'consult-uuid-001',
            newStatus: 'invalid_status',
        );

    }//end testTransitionStatusThrowsOnInvalidStatus()


    /**
     * Test transitionStatus throws when non-coordinator attempts backward transition.
     *
     * The status machine only allows forward transitions for regular users.
     * This test verifies that trying to go from a terminal status triggers an
     * exception rather than silently succeeding.
     *
     * @return void
     */
    public function testTransitionStatusThrowsOnIllegalBackwardTransitionForNonCoordinator(): void
    {
        $objectService = $this->createMock(\stdClass::class);
        $objectService->method('findObject')->willReturn([
            'id'     => 'consult-uuid-001',
            'status' => 'afgesloten',
        ]);

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($objectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap([
                ['register', 'procest'],
                ['consultation_schema', 'consultation'],
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not allowed');

        $this->service->transitionStatus(
            consultationId: 'consult-uuid-001',
            newStatus: 'open',
            isCoordinator: false,
        );

    }//end testTransitionStatusThrowsOnIllegalBackwardTransitionForNonCoordinator()


    /**
     * Test validateDependencyCycle returns false when no cycle.
     *
     * @return void
     */
    public function testValidateDependencyCycleReturnsTrueWhenNoCycle(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $result = $this->service->validateDependencyCycle(
            newDependsOn: ['other-id-1', 'other-id-2'],
            consultationId: 'consult-uuid-001',
        );

        // When ObjectService unavailable, no lookups possible — no cycle detected.
        $this->assertTrue($result);

    }//end testValidateDependencyCycleReturnsTrueWhenNoCycle()


    /**
     * Test validateDependencyCycle detects direct self-reference.
     *
     * @return void
     */
    public function testValidateDependencyCycleDetectsSelfReference(): void
    {
        $result = $this->service->validateDependencyCycle(
            newDependsOn: ['consult-uuid-001'],
            consultationId: 'consult-uuid-001',
        );

        $this->assertFalse($result);

    }//end testValidateDependencyCycleDetectsSelfReference()


    /**
     * Test getBlockingConsultations returns empty when OpenRegister unavailable.
     *
     * @return void
     */
    public function testGetBlockingConsultationsReturnsEmptyWhenUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $result = $this->service->getBlockingConsultations(zaakId: 'zaak-uuid-001');

        $this->assertIsArray($result);
        $this->assertEmpty($result);

    }//end testGetBlockingConsultationsReturnsEmptyWhenUnavailable()


    /**
     * Test getOverdueConsultations returns empty when OpenRegister unavailable.
     *
     * @return void
     */
    public function testGetOverdueConsultationsReturnsEmptyWhenUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $result = $this->service->getOverdueConsultations();

        $this->assertIsArray($result);
        $this->assertEmpty($result);

    }//end testGetOverdueConsultationsReturnsEmptyWhenUnavailable()


    /**
     * Test submitResponse throws when advies type is invalid.
     *
     * @return void
     */
    public function testSubmitResponseThrowsOnInvalidAdviesType(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid advies type');

        $this->service->submitResponse(
            consultationId: 'consult-uuid-001',
            response: ['advies' => 'unknown_type'],
        );

    }//end testSubmitResponseThrowsOnInvalidAdviesType()


    /**
     * Test processExternalResponse returns null when OpenRegister unavailable.
     *
     * @return void
     */
    public function testProcessExternalResponseReturnsNullWhenUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $result = $this->service->processExternalResponse(
            token: str_repeat('a', 64),
            response: ['advies' => 'positief', 'toelichting' => 'OK', 'datum' => '2026-05-19'],
        );

        $this->assertNull($result);

    }//end testProcessExternalResponseReturnsNullWhenUnavailable()
}//end class
