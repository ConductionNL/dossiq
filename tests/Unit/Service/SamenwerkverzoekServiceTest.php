<?php

/**
 * SamenwerkverzoekService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#V08
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SamenwerkverzoekService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\SamenwerkverzoekService
 */
class SamenwerkverzoekServiceTest extends TestCase
{

    private SettingsService $settingsService;

    private IEventDispatcher $dispatcher;

    private LoggerInterface $logger;

    private SamenwerkverzoekService $service;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->dispatcher      = $this->createMock(IEventDispatcher::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new SamenwerkverzoekService(
            settingsService: $this->settingsService,
            dispatcher: $this->dispatcher,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * initiateSamenwerking throws when OpenRegister is unavailable.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V08
     */
    public function testInitiateSamenwerkingThrowsWhenNoObjectService(): void
    {
        $this->settingsService
            ->expects($this->once())
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister is not available');

        $this->service->initiateSamenwerking(
            zaakId: 'test-zaak',
            aangezochtBevoegdGezag: 'Waterschap Test',
            rationale: 'Test rationale',
            initiatorBevoegdGezag: 'Gemeente Test',
        );
    }//end testInitiateSamenwerkingThrowsWhenNoObjectService()

    /**
     * respondToSamenwerking throws when samenwerkverzoek not found.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V08
     */
    public function testRespondToSamenwerkingThrowsWhenNotFound(): void
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
                        ['dso_samenwerkverzoek_schema', '', 'samenwerk-schema-id'],
                    ]
                    );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('samenwerkverzoek_not_found');

        $this->service->respondToSamenwerking(
            samenwerkId: 'nonexistent',
            accept: true,
            advies: null,
        );
    }//end testRespondToSamenwerkingThrowsWhenNotFound()

    /**
     * authorizeSamenwerkResponse allows admin users regardless.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V08
     */
    public function testAuthorizeSamenwerkResponseAllowsAdmin(): void
    {
        // Should not throw for admin.
        $this->service->authorizeSamenwerkResponse(
            samenwerk: ['status' => 'aangevraagd'],
            userId: 'admin',
            isAdmin: true,
        );
        $this->assertTrue(true);
    }//end testAuthorizeSamenwerkResponseAllowsAdmin()

    /**
     * authorizeSamenwerkResponse throws when samenwerk is empty.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V08
     */
    public function testAuthorizeSamenwerkResponseThrowsForEmptySamenwerk(): void
    {
        $this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);

        $this->service->authorizeSamenwerkResponse(
            samenwerk: [],
            userId: 'testuser',
            isAdmin: false,
        );
    }//end testAuthorizeSamenwerkResponseThrowsForEmptySamenwerk()
}//end class
