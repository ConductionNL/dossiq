<?php

/**
 * ZaakdossierService Unit Tests
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T02
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ZaakdossierService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\InformatieobjectAccessGuard;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ZaakdossierService.
 *
 * @covers \OCA\Procest\Service\ZaakdossierService
 */
class ZaakdossierServiceTest extends TestCase
{

    private SettingsService $settingsService;
    private InformatieobjectAccessGuard $accessGuard;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private ZaakdossierService $service;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->accessGuard     = $this->createMock(InformatieobjectAccessGuard::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new ZaakdossierService(
            settingsService: $this->settingsService,
            accessGuard: $this->accessGuard,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that transitionStatus throws when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testTransitionStatusThrowsWhenServiceUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unavailable/i');

        $this->service->transitionStatus(
            infoObjectId: 'some-uuid',
            newStatus: 'definitief',
        );
    }//end testTransitionStatusThrowsWhenServiceUnavailable()

    /**
     * Test that transitionStatus throws on an invalid transition.
     *
     * @return void
     */
    public function testTransitionStatusRejectsInvalidTransition(): void
    {
        $mockObjectService = new class {
            public function findObject(string $register, string $schema, string $id): ?array
            {
                return ['id' => $id, 'status' => 'definitief', 'titel' => 'Test'];
            }
        };

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($mockObjectService);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturn('informatieobject');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not allowed/i');

        $this->service->transitionStatus(
            infoObjectId: 'some-uuid',
            newStatus: 'concept',
        );
    }//end testTransitionStatusRejectsInvalidTransition()

    /**
     * Test that bulkTransitionStatus returns per-id results.
     *
     * @return void
     */
    public function testBulkTransitionStatusReturnsPerIdResults(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $results = $this->service->bulkTransitionStatus(
            infoObjectIds: ['id-1', 'id-2'],
            newStatus: 'definitief',
        );

        $this->assertArrayHasKey('id-1', $results);
        $this->assertArrayHasKey('id-2', $results);
        $this->assertFalse($results['id-1']['success']);
        $this->assertFalse($results['id-2']['success']);
    }//end testBulkTransitionStatusReturnsPerIdResults()

    /**
     * Test getDossierForCase throws when service is unavailable.
     *
     * @return void
     */
    public function testGetDossierForCaseThrowsWhenUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);

        $this->service->getDossierForCase(caseId: 'case-123');
    }//end testGetDossierForCaseThrowsWhenUnavailable()

    /**
     * Test unlinkInformatieobject throws when service is unavailable.
     *
     * @return void
     */
    public function testUnlinkThrowsWhenServiceUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);

        $this->service->unlinkInformatieobject(caseId: 'c1', infoObjectId: 'i1');
    }//end testUnlinkThrowsWhenServiceUnavailable()

}//end class
