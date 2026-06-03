<?php

/**
 * DsoDeadlineJob Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#V05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\BackgroundJob;

use OCA\Procest\BackgroundJob\DsoDeadlineJob;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\BackgroundJob\DsoDeadlineJob
 */
class DsoDeadlineJobTest extends TestCase
{

    private ITimeFactory $timeFactory;

    private DsoCaseService $dsoCaseService;

    private SettingsService $settingsService;

    private IAppManager $appManager;

    private INotificationManager $notificationManager;

    private LoggerInterface $logger;

    private DsoDeadlineJob $job;

    protected function setUp(): void
    {
        $this->timeFactory         = $this->createMock(ITimeFactory::class);
        $this->dsoCaseService      = $this->createMock(DsoCaseService::class);
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->appManager          = $this->createMock(IAppManager::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->job = new DsoDeadlineJob(
            time: $this->timeFactory,
            dsoCaseService: $this->dsoCaseService,
            settingsService: $this->settingsService,
            appManager: $this->appManager,
            notificationManager: $this->notificationManager,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * DsoDeadlineJob can be instantiated with all dependencies.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V05
     */
    public function testJobCanBeInstantiated(): void
    {
        $this->assertInstanceOf(DsoDeadlineJob::class, $this->job);
    }//end testJobCanBeInstantiated()

    /**
     * DsoDeadlineJob skips execution when openregister is not installed.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V05
     */
    public function testJobSkipsWhenOpenregisterNotInstalled(): void
    {
        $this->appManager
            ->expects($this->once())
            ->method('getInstalledApps')
            ->willReturn(['procest', 'files']);

        // ObjectService should NOT be called.
        $this->settingsService
            ->expects($this->never())
            ->method('getObjectService');

        // Invoke run via reflection (protected method).
        $reflection = new \ReflectionMethod($this->job, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($this->job, null);
    }//end testJobSkipsWhenOpenregisterNotInstalled()

    /**
     * DsoDeadlineJob skips execution when ObjectService is null.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V05
     */
    public function testJobSkipsWhenObjectServiceNull(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'procest']);

        $this->settingsService
            ->expects($this->once())
            ->method('getObjectService')
            ->willReturn(null);

        $reflection = new \ReflectionMethod($this->job, 'run');
        $reflection->setAccessible(true);
        $reflection->invoke($this->job, null);

        // No exception thrown = pass.
        $this->assertTrue(true);
    }//end testJobSkipsWhenObjectServiceNull()
}//end class
