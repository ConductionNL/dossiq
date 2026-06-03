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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BeschikkingGenerationService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BeschikkingGenerationService.
 *
 * @covers \OCA\Procest\Service\BeschikkingGenerationService
 */
class BeschikkingGenerationServiceTest extends TestCase
{

    /**
     * Mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mocked DI container.
     *
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private ContainerInterface $container;

    /**
     * Mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var BeschikkingGenerationService
     */
    private BeschikkingGenerationService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service = new BeschikkingGenerationService(
            settingsService: $this->settingsService,
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that generateBeschikking throws on invalid outcome.
     *
     * @return void
     */
    public function testGenerateBeschikkingThrowsOnInvalidOutcome(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->expectException(\InvalidArgumentException::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service->generateBeschikking(
            zaakId: 'zaak-123',
            outcome: 'onbekend',
            motivation: 'test'
        );

    }//end testGenerateBeschikkingThrowsOnInvalidOutcome()

    /**
     * Test that generateBeschikking throws when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testGenerateBeschikkingThrowsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->expectException(\RuntimeException::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service->generateBeschikking(
            zaakId: 'zaak-123',
            outcome: 'verleend',
            motivation: 'Voldoet aan criteria.'
        );

    }//end testGenerateBeschikkingThrowsWhenOpenRegisterUnavailable()

    /**
     * Test that generateBeschikking throws when zaak has no vergunningaanvraagRef.
     *
     * @return void
     */
    public function testGenerateBeschikkingThrowsWhenNoVergunningRef(): void
    {
        $objectService = new class {
            /**
             * Stub getObject returning empty vergunningaanvraagRef.
             *
             * @param string $register The register slug
             * @param string $schema   The schema slug
             * @param string $id       The object id
             *
             * @return array<string, mixed>
             */
            public function getObject(string $register, string $schema, string $id): array
            {
                return ['id' => $id, 'vergunningaanvraagRef' => ''];
            }//end getObject()
        };

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($objectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap(
                [
                    ['register', '', 'procest'],
                    ['case_schema', '', 'case'],
                ]
            );

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->expectException(\RuntimeException::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->service->generateBeschikking(
            zaakId: 'zaak-123',
            outcome: 'verleend',
            motivation: 'Test.'
        );

    }//end testGenerateBeschikkingThrowsWhenNoVergunningRef()
}//end class
