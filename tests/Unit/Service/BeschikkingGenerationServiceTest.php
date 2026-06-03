<?php

/**
 * BeschikkingGenerationService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#V07
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BeschikkingGenerationService;
use OCA\Procest\Service\SettingsService;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\BeschikkingGenerationService
 */
class BeschikkingGenerationServiceTest extends TestCase
{

    private SettingsService $settingsService;

    private INotificationManager $notificationManager;

    private IUserSession $userSession;

    private ContainerInterface $container;

    private LoggerInterface $logger;

    private BeschikkingGenerationService $service;

    protected function setUp(): void
    {
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new BeschikkingGenerationService(
            settingsService: $this->settingsService,
            notificationManager: $this->notificationManager,
            userSession: $this->userSession,
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * generateBeschikking throws when OpenRegister is unavailable.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V07
     */
    public function testGenerateBeschikkingThrowsWhenNoObjectService(): void
    {
        $this->settingsService
            ->expects($this->once())
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister is not available');

        $this->service->generateBeschikking(
            zaakId: 'test-id',
            outcome: 'verleend',
            motivation: 'Test motivation',
        );
    }//end testGenerateBeschikkingThrowsWhenNoObjectService()

    /**
     * generateBeschikking throws when outcome is invalid (rejected at controller).
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V07
     */
    public function testGenerateBeschikkingThrowsWhenZaakNotFound(): void
    {
        $mockObjectService = new class {
            public function findObject(string $register, string $schema, string $id): ?array
            {
                return null;
            }//end findObject()
        };

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($mockObjectService);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap(
                    [
                        ['register', '', 'procest-register'],
                        ['case_schema', '', 'case-schema-id'],
                    ]
                    );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zaak_not_found');

        $this->service->generateBeschikking(
            zaakId: 'nonexistent-id',
            outcome: 'verleend',
            motivation: 'Motivation',
        );
    }//end testGenerateBeschikkingThrowsWhenZaakNotFound()
}//end class
