<?php

/**
 * Tests for DoorlooptijdService
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\DoorlooptijdService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DoorlooptijdService
 */
class DoorlooptijdServiceTest extends TestCase
{
    private DoorlooptijdService $service;
    private SettingsService $settingsService;
    private LoggerInterface $logger;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new DoorlooptijdService(
            $this->settingsService,
            $this->logger
        );
    }//end setUp()


    /**
     * Test getMetrics returns empty metrics when OpenRegister is unavailable
     *
     * @return void
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-03
     */
    public function testGetMetricsReturnsEmptyWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $result = $this->service->getMetrics('2025-01-01', '2025-01-31');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('slaCompliance', $result);
        $this->assertArrayHasKey('distribution', $result);
        $this->assertArrayHasKey('monthlyTrend', $result);
        $this->assertArrayHasKey('atRiskCases', $result);
        $this->assertArrayHasKey('performanceTable', $result);

        // Verify empty structure
        $this->assertNull($result['slaCompliance']['overallRate']);
        $this->assertCount(0, $result['slaCompliance']['byType']);
        $this->assertCount(0, $result['distribution']['bins']);
        $this->assertCount(0, $result['monthlyTrend']);
        $this->assertCount(0, $result['atRiskCases']);
        $this->assertCount(0, $result['performanceTable']);
    }//end testGetMetricsReturnsEmptyWhenOpenRegisterUnavailable()


    /**
     * Test getMetrics returns empty metrics when schemas not configured
     *
     * @return void
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-03
     */
    public function testGetMetricsReturnsEmptyWhenSchemasNotConfigured(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);
        $this->settingsService->method('getObjectService')->willReturn($mockObjectService);
        $this->settingsService->method('getConfigValue')->willReturn(null);

        $result = $this->service->getMetrics('2025-01-01', '2025-01-31');

        $this->assertIsArray($result);
        $this->assertNull($result['slaCompliance']['overallRate']);
    }//end testGetMetricsReturnsEmptyWhenSchemasNotConfigured()


    /**
     * Test service structure returns all required metrics
     *
     * @return void
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-03
     */
    public function testServiceReturnsCompleteMetricsStructure(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);
        $this->settingsService->method('getObjectService')->willReturn($mockObjectService);

        // Mock config values
        $this->settingsService->method('getConfigValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'register' => 'procest',
                    'case_schema' => 'case',
                    'case_type_schema' => 'caseType',
                    default => null,
                };
            });

        // Mock empty results
        $mockObjectService->expects($this->any())
            ->method('findObjects')
            ->willReturn([]);

        $result = $this->service->getMetrics('2025-01-01', '2025-01-31');

        // Verify all expected keys are present
        $this->assertArrayHasKey('slaCompliance', $result);
        $this->assertArrayHasKey('distribution', $result);
        $this->assertArrayHasKey('monthlyTrend', $result);
        $this->assertArrayHasKey('atRiskCases', $result);
        $this->assertArrayHasKey('performanceTable', $result);

        // Verify slaCompliance structure
        $this->assertArrayHasKey('overallRate', $result['slaCompliance']);
        $this->assertArrayHasKey('withinSla', $result['slaCompliance']);
        $this->assertArrayHasKey('total', $result['slaCompliance']);
        $this->assertArrayHasKey('excluded', $result['slaCompliance']);
        $this->assertArrayHasKey('byType', $result['slaCompliance']);

        // Verify distribution structure
        $this->assertArrayHasKey('bins', $result['distribution']);
        $this->assertArrayHasKey('slaTargetDays', $result['distribution']);
    }//end testServiceReturnsCompleteMetricsStructure()
}//end class
