<?php

/**
 * VergaderingDeadlineJob Unit Tests
 *
 * Tests for the nightly background job that advances vergadering-backed
 * cases when their agenda-publication deadline is reached.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\BackgroundJob;

use OCA\Procest\BackgroundJob\VergaderingDeadlineJob;
use OCA\Procest\Service\VergaderingCaseService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for VergaderingDeadlineJob.
 *
 * @covers \OCA\Procest\BackgroundJob\VergaderingDeadlineJob
 */
class VergaderingDeadlineJobTest extends TestCase
{

    /**
     * The mocked time factory.
     *
     * @var ITimeFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * The mocked VergaderingCaseService.
     *
     * @var VergaderingCaseService|\PHPUnit\Framework\MockObject\MockObject
     */
    private VergaderingCaseService $vergaderingCaseService;

    /**
     * The mocked app manager.
     *
     * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppManager $appManager;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The job under test.
     *
     * @var VergaderingDeadlineJob
     */
    private VergaderingDeadlineJob $job;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->timeFactory           = $this->createMock(ITimeFactory::class);
        $this->vergaderingCaseService = $this->createMock(VergaderingCaseService::class);
        $this->appManager            = $this->createMock(IAppManager::class);
        $this->logger                = $this->createMock(LoggerInterface::class);

        $this->job = new VergaderingDeadlineJob(
            time: $this->timeFactory,
            vergaderingCases: $this->vergaderingCaseService,
            appManager: $this->appManager,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that run() exits early when OpenRegister is not installed.
     *
     * @return void
     */
    public function testRunExitsEarlyWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn(['procest', 'contacts']);

        $this->vergaderingCaseService
            ->expects($this->never())
            ->method('checkDeadlines');

        $ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke($this->job, null);

    }//end testRunExitsEarlyWhenOpenRegisterNotInstalled()


    /**
     * Test that run() calls checkDeadlines() when OpenRegister is installed.
     *
     * @return void
     */
    public function testRunCallsCheckDeadlinesWhenOpenRegisterInstalled(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'procest']);

        $this->vergaderingCaseService
            ->expects($this->once())
            ->method('checkDeadlines')
            ->willReturn(0);

        $ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke($this->job, null);

    }//end testRunCallsCheckDeadlinesWhenOpenRegisterInstalled()


    /**
     * Test that run() logs when cases are advanced.
     *
     * @return void
     */
    public function testRunLogsWhenCasesAdvanced(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'procest']);

        $this->vergaderingCaseService
            ->method('checkDeadlines')
            ->willReturn(3);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with($this->stringContains('3 case(s)'));

        $ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke($this->job, null);

    }//end testRunLogsWhenCasesAdvanced()


}//end class
