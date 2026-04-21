<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SignaleringService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the SignaleringService class.
 *
 * @covers \OCA\Procest\Service\SignaleringService
 */
class SignaleringServiceTest extends TestCase
{
    private SignaleringService $service;
    private IAppManager $appManager;
    private ContainerInterface $container;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new SignaleringService(
            $this->appManager,
            $this->container,
            $this->logger,
        );
    }

    /**
     * Test deadline calculation without opschorting returns correct dates.
     */
    public function testCalculateDeadlineStatusWithoutOpschorting(): void
    {
        $createdAt = (new \DateTime())->sub(new \DateInterval('P20D'))->format(\DateTime::ATOM);
        $case = [
            'id' => 'case-123',
            'createdAt' => $createdAt,
        ];

        $caseType = [
            'processingDeadline' => 'P30D', // 30 days
        ];

        $result = $this->service->calculateDeadlineStatus($case, $caseType);

        // Should have streeftermijn, fatalTermijn, opschorting, overallStatus
        $this->assertArrayHasKey('streeftermijn', $result);
        $this->assertArrayHasKey('fatalTermijn', $result);
        $this->assertArrayHasKey('opschorting', $result);
        $this->assertArrayHasKey('overallStatus', $result);

        // Created 20 days ago + 30 day deadline = should be 10 days remaining
        $this->assertLessThanOrEqual(10, $result['streeftermijn']['daysRemaining']);
        $this->assertGreaterThanOrEqual(9, $result['streeftermijn']['daysRemaining']);
    }

    /**
     * Test deadline calculation with active opschorting.
     */
    public function testCalculateDeadlineStatusWithOpschorting(): void
    {
        $createdAt = (new \DateTime())->sub(new \DateInterval('P20D'))->format(\DateTime::ATOM);
        $case = [
            'id' => 'case-123',
            'createdAt' => $createdAt,
            'opschorting' => [
                'startDate' => (new \DateTime())->sub(new \DateInterval('P5D'))->format(\DateTime::ATOM),
                'endDate' => (new \DateTime())->add(new \DateInterval('P5D'))->format(\DateTime::ATOM),
            ],
        ];

        $caseType = [
            'processingDeadline' => 'P30D',
        ];

        $result = $this->service->calculateDeadlineStatus($case, $caseType);

        // Opschorting should be marked as active
        $this->assertTrue($result['opschorting']['active']);
        $this->assertNotNull($result['opschorting']['startDate']);
        $this->assertNotNull($result['opschorting']['endDate']);
    }

    /**
     * Test threshold checking returns true when warning threshold crossed.
     */
    public function testCheckThresholdsDetectsWarning(): void
    {
        $createdAt = (new \DateTime())->sub(new \DateInterval('P26D'))->format(\DateTime::ATOM);
        $case = [
            'id' => 'case-123',
            'createdAt' => $createdAt,
        ];

        $caseType = [
            'processingDeadline' => 'P30D', // 30 days, 4 days remaining = warning
        ];

        $config = [
            'enabled' => true,
            'warningDaysStreef' => 7,
        ];

        $result = $this->service->checkThresholds($case, $caseType, $config);

        // Should detect warning threshold
        $this->assertTrue($result);
    }

    /**
     * Test threshold checking returns false when configuration is disabled.
     */
    public function testCheckThresholdsReturnsFalseWhenDisabled(): void
    {
        $createdAt = (new \DateTime())->sub(new \DateInterval('P26D'))->format(\DateTime::ATOM);
        $case = [
            'id' => 'case-123',
            'createdAt' => $createdAt,
        ];

        $caseType = [
            'processingDeadline' => 'P30D',
        ];

        $config = [
            'enabled' => false,
        ];

        $result = $this->service->checkThresholds($case, $caseType, $config);

        // Should return false because config is disabled
        $this->assertFalse($result);
    }

    /**
     * Test threshold checking detects overdue status.
     */
    public function testCheckThresholdsDetectsOverdue(): void
    {
        $createdAt = (new \DateTime())->sub(new \DateInterval('P35D'))->format(\DateTime::ATOM);
        $case = [
            'id' => 'case-123',
            'createdAt' => $createdAt,
        ];

        $caseType = [
            'processingDeadline' => 'P30D', // 30 days, now overdue
        ];

        $config = [
            'enabled' => true,
        ];

        $result = $this->service->checkThresholds($case, $caseType, $config);

        // Should detect overdue (which crosses threshold)
        $this->assertTrue($result);
    }

    /**
     * Test on-track status returns false threshold.
     */
    public function testCheckThresholdsReturnsFalseWhenOnTrack(): void
    {
        $createdAt = (new \DateTime())->sub(new \DateInterval('P5D'))->format(\DateTime::ATOM);
        $case = [
            'id' => 'case-123',
            'createdAt' => $createdAt,
        ];

        $caseType = [
            'processingDeadline' => 'P30D', // 30 days, ~25 days remaining = on track
        ];

        $config = [
            'enabled' => true,
        ];

        $result = $this->service->checkThresholds($case, $caseType, $config);

        // Should return false because still on track
        $this->assertFalse($result);
    }
}
