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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SamenwerkverzoekService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SamenwerkverzoekService.
 *
 * @covers \OCA\Procest\Service\SamenwerkverzoekService
 */
class SamenwerkverzoekServiceTest extends TestCase
{

    /**
     * Mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var SamenwerkverzoekService
     */
    private SamenwerkverzoekService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service = new SamenwerkverzoekService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test initiateSamenwerking throws when OpenRegister unavailable.
     *
     * @return void
     */
    public function testInitiateSamenwerkingThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->expectException(\RuntimeException::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service->initiateSamenwerking(
            zaakId: 'zaak-123',
            aangezochtBevoegdGezag: 'Provincie Noord-Holland',
            rationale: 'Multi-authority coordination needed.'
        );

    }//end testInitiateSamenwerkingThrowsWhenOpenRegisterUnavailable()

    /**
     * Test respondToSamenwerking throws when OpenRegister unavailable.
     *
     * @return void
     */
    public function testRespondToSamenwerkingThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->expectException(\RuntimeException::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service->respondToSamenwerking(
            samenwerkId: 'sw-123',
            accept: true,
            advies: null
        );

    }//end testRespondToSamenwerkingThrowsWhenOpenRegisterUnavailable()

    /**
     * Test respondToSamenwerking sets status to geweigerd when accept false.
     *
     * @return void
     */
    public function testRespondToSamenwerkingSetStatusGeweigerd(): void
    {
        $objectService = new class {

            /**
             * Tracks the last saved object.
             *
             * @var array<string, mixed>|null
             */
            public ?array $lastSaved = null;

            /**
             * Stub getObject returning aangevraagd samenwerkverzoek.
             *
             * @param string $register Unused register param
             * @param string $schema   Unused schema param
             * @param string $id       The object id
             *
             * @return array<string, mixed>
             */
            public function getObject(string $register, string $schema, string $id): array
            {
                return ['id' => $id, 'status' => 'aangevraagd'];
            }//end getObject()

            /**
             * Stub saveObject recording the saved data.
             *
             * @param string               $register Unused register param
             * @param string               $schema   Unused schema param
             * @param array<string, mixed> $object   The object to save
             *
             * @return array<string, mixed>
             */
            public function saveObject(string $register, string $schema, array $object): array
            {
                $this->lastSaved = $object;
                return $object;
            }//end saveObject()
        };

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($objectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap(
                [
                    ['register', '', 'procest'],
                    ['dso_samenwerkverzoek_schema', '', ''],
                ]
            );

        $result = $this->service->respondToSamenwerking(
            samenwerkId: 'sw-123',
            accept: false,
            advies: null
        );

        $this->assertSame(expected: 'geweigerd', actual: $result['status']);

    }//end testRespondToSamenwerkingSetStatusGeweigerd()
}//end class
