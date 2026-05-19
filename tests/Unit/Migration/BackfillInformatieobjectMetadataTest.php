<?php

/**
 * BackfillInformatieobjectMetadata Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T09
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Migration;

use OCA\Procest\Migration\BackfillInformatieobjectMetadata;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BackfillInformatieobjectMetadata repair step.
 *
 * @covers \OCA\Procest\Migration\BackfillInformatieobjectMetadata
 */
class BackfillInformatieobjectMetadataTest extends TestCase
{

    private SettingsService $settingsService;
    private LoggerInterface $logger;
    private BackfillInformatieobjectMetadata $repairStep;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->repairStep = new BackfillInformatieobjectMetadata(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test getName returns a non-empty string.
     *
     * @return void
     */
    public function testGetNameReturnsNonEmptyString(): void
    {
        $name = $this->repairStep->getName();

        $this->assertIsString($name);
        $this->assertNotEmpty($name);
        $this->assertStringContainsStringIgnoringCase('procest', $name);
    }//end testGetNameReturnsNonEmptyString()

    /**
     * Test run exits gracefully when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testRunExitsGracefullyWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('unavailable'));

        $this->repairStep->run($output);
    }//end testRunExitsGracefullyWhenOpenRegisterUnavailable()

    /**
     * Test run exits when schemas are not configured.
     *
     * @return void
     */
    public function testRunExitsWhenSchemasNotConfigured(): void
    {
        $mockService = new class {
            public function findObjects(string $register, string $schema, array $params): array { return []; }
            public function saveObject(string $register, string $schema, array $object): array { return []; }
        };

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($mockService);

        $this->settingsService
            ->method('getConfigValue')
            ->willReturn('');

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('schemas'));

        $this->repairStep->run($output);
    }//end testRunExitsWhenSchemasNotConfigured()

}//end class
