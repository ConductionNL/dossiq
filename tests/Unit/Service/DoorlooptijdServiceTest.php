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
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\DoorlooptijdService;
use OCA\Procest\Service\SettingsService;
use OCP\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test case for DoorlooptijdService
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-18
 */
class DoorlooptijdServiceTest extends TestCase
{

    private DoorlooptijdService $service;
    private SettingsService $settingsService;
    private LoggerInterface $logger;
    private IAppManager $appManager;
    private ContainerInterface $container;


    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container = $this->createMock(ContainerInterface::class);

        // Create service
        $this->service = new DoorlooptijdService(
            $this->settingsService,
            $this->logger,
            $this->appManager,
            $this->container
        );
    }


    /**
     * Test calculating case duration without suspension.
     *
     * @return void
     */
    public function testCalculateCaseDurationWithoutSuspension(): void
    {
        $case = [
            'id' => 'case-1',
            'createdAt' => '2024-01-01',
            'closedAt' => '2024-01-10',
        ];

        $duration = $this->service->calculateCaseDuration($case);

        $this->assertNotNull($duration);
        $this->assertEquals(9, $duration);
    }


    /**
     * Test calculating case duration with suspension periods.
     *
     * @return void
     */
    public function testCalculateCaseDurationWithSuspension(): void
    {
        $case = [
            'id' => 'case-1',
            'createdAt' => '2024-01-01',
            'closedAt' => '2024-01-15',
            'suspensions' => [
                [
                    'startDate' => '2024-01-05',
                    'endDate' => '2024-01-08',
                ],
            ],
        ];

        $duration = $this->service->calculateCaseDuration($case);

        // 14 days total - 3 days suspended = 11 days
        $this->assertNotNull($duration);
        $this->assertEquals(11, $duration);
    }


    /**
     * Test SLA adherence calculation.
     *
     * @return void
     */
    public function testCalculateSlaAdherence(): void
    {
        $cases = [
            ['createdAt' => '2024-01-01', 'closedAt' => '2024-01-15'],
            ['createdAt' => '2024-01-02', 'closedAt' => '2024-01-20'],
            ['createdAt' => '2024-01-03', 'closedAt' => '2024-02-10'],
        ];

        $slaConfig = ['streeftermijn' => 20];

        $adherence = $this->service->calculateSLAAdherence($cases, $slaConfig);

        $this->assertIsArray($adherence);
        $this->assertArrayHasKey('percentage', $adherence);
        $this->assertArrayHasKey('withinSLA', $adherence);
        $this->assertArrayHasKey('overdue', $adherence);
    }


    /**
     * Test getting SLA configuration.
     *
     * @return void
     */
    public function testGetSlaConfiguration(): void
    {
        $caseTypeId = 'test-case-type';

        $config = $this->service->getSLAConfiguration($caseTypeId);

        $this->assertIsArray($config);
        $this->assertArrayHasKey('caseTypeId', $config);
        $this->assertArrayHasKey('streeftermijn', $config);
        $this->assertArrayHasKey('fatalTermijn', $config);
        $this->assertEquals($caseTypeId, $config['caseTypeId']);
    }
}
