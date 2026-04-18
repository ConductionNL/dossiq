<?php

/**
 * Tests for SlaConfigurationService
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SlaConfigurationService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test case for SlaConfigurationService
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-19
 */
class SlaConfigurationServiceTest extends TestCase
{

    private SlaConfigurationService $service;
    private SettingsService $settingsService;
    private LoggerInterface $logger;


    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new SlaConfigurationService(
            $this->settingsService,
            $this->logger
        );
    }


    /**
     * Test retrieving SLA configuration for a case type.
     *
     * @return void
     */
    public function testGetSlaForCaseType(): void
    {
        $caseTypeId = 'bezwaarschrift';

        $config = $this->service->getSlForCaseType($caseTypeId);

        $this->assertIsArray($config);
        $this->assertArrayHasKey('caseTypeId', $config);
        $this->assertArrayHasKey('streeftermijn', $config);
        $this->assertArrayHasKey('fatalTermijn', $config);
        $this->assertEquals($caseTypeId, $config['caseTypeId']);
        $this->assertIsInt($config['streeftermijn']);
        $this->assertIsInt($config['fatalTermijn']);
    }


    /**
     * Test retrieving SLA configuration for a process step.
     *
     * @return void
     */
    public function testGetSlaForStep(): void
    {
        $caseTypeId = 'bezwaarschrift';
        $stepId = 'intake';

        $config = $this->service->getSlAforStep($caseTypeId, $stepId);

        $this->assertIsArray($config);
        $this->assertArrayHasKey('caseTypeId', $config);
        $this->assertArrayHasKey('processStepId', $config);
        $this->assertArrayHasKey('streeftermijn', $config);
        $this->assertArrayHasKey('fatalTermijn', $config);
    }


    /**
     * Test getting all SLA configurations.
     *
     * @return void
     */
    public function testGetAllConfigurations(): void
    {
        $configs = $this->service->getAllConfigurations();

        $this->assertIsArray($configs);
        $this->assertGreaterThan(0, count($configs));

        foreach ($configs as $config) {
            $this->assertIsArray($config);
            $this->assertArrayHasKey('caseTypeId', $config);
            $this->assertArrayHasKey('streeftermijn', $config);
            $this->assertArrayHasKey('fatalTermijn', $config);
        }
    }


    /**
     * Test saving SLA configuration.
     *
     * @return void
     */
    public function testSaveConfiguration(): void
    {
        $caseTypeId = 'beroep';
        $config = [
            'streeftermijn' => 45,
            'fatalTermijn' => 90,
        ];

        $saved = $this->service->saveConfiguration($caseTypeId, $config);

        $this->assertIsArray($saved);
        $this->assertArrayHasKey('caseTypeId', $saved);
        $this->assertArrayHasKey('createdAt', $saved);
        $this->assertArrayHasKey('updatedAt', $saved);
        $this->assertEquals($caseTypeId, $saved['caseTypeId']);
        $this->assertEquals(45, $saved['streeftermijn']);
        $this->assertEquals(90, $saved['fatalTermijn']);
    }


    /**
     * Test getting default SLA configuration.
     *
     * @return void
     */
    public function testGetDefaultConfiguration(): void
    {
        $defaults = $this->service->getDefaultConfiguration();

        $this->assertIsArray($defaults);
        $this->assertArrayHasKey('streeftermijn', $defaults);
        $this->assertArrayHasKey('fatalTermijn', $defaults);
        $this->assertArrayHasKey('suspensionStatus', $defaults);
        $this->assertEquals(30, $defaults['streeftermijn']);
        $this->assertEquals(60, $defaults['fatalTermijn']);
    }
}
