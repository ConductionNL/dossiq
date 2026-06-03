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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T05
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
 * Unit tests for DsoDeadlineJob.
 *
 * @covers \OCA\Procest\BackgroundJob\DsoDeadlineJob
 */
class DsoDeadlineJobTest extends TestCase
{

    /**
     * Mocked time factory.
     *
     * @var ITimeFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * Mocked DSO case service.
     *
     * @var DsoCaseService|\PHPUnit\Framework\MockObject\MockObject
     */
    private DsoCaseService $dsoCaseService;

    /**
     * Mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mocked notification manager.
     *
     * @var INotificationManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private INotificationManager $notificationManager;

    /**
     * Mocked app manager.
     *
     * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppManager $appManager;

    /**
     * Mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The job under test.
     *
     * @var DsoDeadlineJob
     */
    private DsoDeadlineJob $job;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->timeFactory         = $this->createMock(ITimeFactory::class);
        $this->dsoCaseService      = $this->createMock(DsoCaseService::class);
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->appManager          = $this->createMock(IAppManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->timeFactory->method('getTime')->willReturn(time());
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->job = new DsoDeadlineJob(
            time: $this->timeFactory,
            dsoCaseService: $this->dsoCaseService,
            settingsService: $this->settingsService,
            notificationManager: $this->notificationManager,
            appManager: $this->appManager,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that the job exits early when openregister is not installed.
     *
     * @return void
     */
    public function testJobExitsEarlyWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn([]);

        $this->settingsService
            ->expects($this->never())
            ->method('getObjectService');

        $ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
        $ref->invoke($this->job, null);

    }//end testJobExitsEarlyWhenOpenRegisterNotInstalled()

    /**
     * Test that the job exits early when OpenRegister is not available.
     *
     * @return void
     */
    public function testJobExitsEarlyWhenObjectServiceNull(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $this->notificationManager
            ->expects($this->never())
            ->method('notify');

        $ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
        $ref->invoke($this->job, null);

    }//end testJobExitsEarlyWhenObjectServiceNull()

    /**
     * Test that the job interval is set to daily (86400 seconds).
     *
     * @return void
     */
    public function testJobIntervalIsDaily(): void
    {
        $ref      = new \ReflectionProperty(class: DsoDeadlineJob::class, property: 'interval');
        $interval = $ref->getValue(object: $this->job);

        $this->assertSame(expected: 86400, actual: $interval);

    }//end testJobIntervalIsDaily()
}//end class
