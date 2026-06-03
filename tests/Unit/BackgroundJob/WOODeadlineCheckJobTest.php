<?php

/**
 * WOODeadlineCheckJob Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\BackgroundJob;

use OCA\Procest\BackgroundJob\WOODeadlineCheckJob;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\WOODeadlineService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WOODeadlineCheckJob.
 *
 * @covers \OCA\Procest\BackgroundJob\WOODeadlineCheckJob
 */
class WOODeadlineCheckJobTest extends TestCase
{

    /**
     * @var ITimeFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * @var WOODeadlineService|\PHPUnit\Framework\MockObject\MockObject
     */
    private WOODeadlineService $deadlineService;

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppManager $appManager;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var WOODeadlineCheckJob
     */
    private WOODeadlineCheckJob $job;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->timeFactory     = $this->createMock(ITimeFactory::class);
        $this->deadlineService = $this->createMock(WOODeadlineService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->job = new WOODeadlineCheckJob(
            $this->timeFactory,
            $this->deadlineService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );
    }//end setUp()

    /**
     * Job can be instantiated with the correct dependencies.
     *
     * @return void
     */
    public function testJobInstantiates(): void
    {
        $this->assertInstanceOf(WOODeadlineCheckJob::class, $this->job);
    }//end testJobInstantiates()

    /**
     * Job returns early when OpenRegister is not installed.
     *
     * @return void
     */
    public function testJobSkipsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['procest']);

        // DeadlineService::checkAndWarn should NOT be called.
        $this->deadlineService->expects($this->never())->method('checkAndWarn');

        // Call the protected run() method via reflection.
        $method = new \ReflectionMethod(WOODeadlineCheckJob::class, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, null);
    }//end testJobSkipsWhenOpenRegisterNotInstalled()

    /**
     * Job skips when settings service returns no object service.
     *
     * @return void
     */
    public function testJobSkipsWhenObjectServiceUnavailable(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister', 'procest']);
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->deadlineService->expects($this->never())->method('checkAndWarn');

        $method = new \ReflectionMethod(WOODeadlineCheckJob::class, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, null);
    }//end testJobSkipsWhenObjectServiceUnavailable()

}//end class
