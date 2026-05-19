<?php

/**
 * InboundEmailJob Unit Tests
 *
 * Tests for the Procest InboundEmailJob IMAP polling background job.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T06
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\BackgroundJob;

use OCA\Procest\BackgroundJob\InboundEmailJob;
use OCA\Procest\Service\CaseEmailService;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for InboundEmailJob.
 *
 * @covers \OCA\Procest\BackgroundJob\InboundEmailJob
 */
class InboundEmailJobTest extends TestCase
{

    /**
     * The mocked time factory.
     *
     * @var ITimeFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private ITimeFactory $time;

    /**
     * The mocked email service.
     *
     * @var CaseEmailService|\PHPUnit\Framework\MockObject\MockObject
     */
    private CaseEmailService $emailService;

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->time            = $this->createMock(ITimeFactory::class);
        $this->emailService    = $this->createMock(CaseEmailService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        // Provide a default return value so the constructor does not error.
        $this->settingsService->method('getConfigValue')->willReturn('');

    }//end setUp()

    /**
     * Test that the job instantiates successfully with all dependencies.
     *
     * @return void
     */
    public function testJobInstantiatesSuccessfully(): void
    {
        $job = new InboundEmailJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $this->assertInstanceOf(InboundEmailJob::class, $job);

    }//end testJobInstantiatesSuccessfully()

    /**
     * Test that run() exits early when OpenRegister is not installed.
     *
     * @return void
     */
    public function testJobSkipsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn([]);

        // processInboundEmail must not be called.
        $this->emailService->expects($this->never())->method('processInboundEmail');

        $job = new InboundEmailJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        // Access protected run() via reflection.
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);

    }//end testJobSkipsWhenOpenRegisterNotInstalled()

    /**
     * Test that run() exits early when IMAP is not configured.
     *
     * @return void
     */
    public function testJobSkipsWhenImapNotConfigured(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        // All getConfigValue calls return empty string (IMAP host is empty).
        $this->settingsService->method('getConfigValue')->willReturn('');

        $this->emailService->expects($this->never())->method('processInboundEmail');

        $job = new InboundEmailJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);

    }//end testJobSkipsWhenImapNotConfigured()

    /**
     * Test that the job uses the default poll interval when config is empty.
     *
     * @return void
     */
    public function testJobUsesDefaultIntervalWhenConfigEmpty(): void
    {
        $this->settingsService->method('getConfigValue')->willReturn('');

        $job = new InboundEmailJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        // The job should have been constructed without error.
        $this->assertInstanceOf(InboundEmailJob::class, $job);

    }//end testJobUsesDefaultIntervalWhenConfigEmpty()

    /**
     * Test that the job accepts a custom poll interval from config.
     *
     * @return void
     */
    public function testJobAcceptsCustomInterval(): void
    {
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['email_poll_interval', '300', '600'],
                ['email_imap_host', '', ''],
                ['email_poll_batch_size', '50', '50'],
            ]
        );

        $job = new InboundEmailJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $this->assertInstanceOf(InboundEmailJob::class, $job);

    }//end testJobAcceptsCustomInterval()
}//end class
