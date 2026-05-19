<?php

/**
 * EmailPdfRetryJob Unit Tests
 *
 * Tests for the Procest EmailPdfRetryJob Docudesk PDF retry background job.
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

use OCA\Procest\BackgroundJob\EmailPdfRetryJob;
use OCA\Procest\Service\CaseEmailService;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for EmailPdfRetryJob.
 *
 * @covers \OCA\Procest\BackgroundJob\EmailPdfRetryJob
 */
class EmailPdfRetryJobTest extends TestCase
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

        $this->settingsService->method('getConfigValue')->willReturn('');

    }//end setUp()

    /**
     * Test that the job instantiates successfully with all dependencies.
     *
     * @return void
     */
    public function testJobInstantiatesSuccessfully(): void
    {
        $job = new EmailPdfRetryJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $this->assertInstanceOf(EmailPdfRetryJob::class, $job);

    }//end testJobInstantiatesSuccessfully()

    /**
     * Test that run() exits early when OpenRegister is not installed.
     *
     * @return void
     */
    public function testJobSkipsWhenOpenRegisterNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn([]);
        $this->settingsService->expects($this->never())->method('getObjectService');

        $job = new EmailPdfRetryJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);

    }//end testJobSkipsWhenOpenRegisterNotInstalled()

    /**
     * Test that run() exits early when the object service is unavailable.
     *
     * @return void
     */
    public function testJobSkipsWhenObjectServiceUnavailable(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->emailService->expects($this->never())->method('schedulePdfConversion');

        $job = new EmailPdfRetryJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);

    }//end testJobSkipsWhenObjectServiceUnavailable()

    /**
     * Test that run() skips processing when email_message_schema is not configured.
     *
     * @return void
     */
    public function testJobSkipsWhenMessageSchemaNotConfigured(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        // All config values empty, so email_message_schema is empty.
        $this->settingsService->method('getConfigValue')->willReturn('');

        // findObjects must not be called.
        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->expects($this->never())->method('findObjects');
        // phpcs:enable CustomSn.Functions.NamedParameters

        $job = new EmailPdfRetryJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);

    }//end testJobSkipsWhenMessageSchemaNotConfigured()

    /**
     * Test that run() does not call schedulePdfConversion when no failed messages exist.
     *
     * @return void
     */
    public function testJobSkipsConversionWhenNoFailedMessages(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['register', '', 'procest'],
                ['email_message_schema', '', 'emailMessage'],
                ['register', 'procest'],
                ['email_message_schema', 'emailMessage'],
            ]
        );

        // No failed messages.
        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->method('findObjects')->willReturn([]);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $this->emailService->expects($this->never())->method('schedulePdfConversion');

        $job = new EmailPdfRetryJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);

    }//end testJobSkipsConversionWhenNoFailedMessages()

    /**
     * Test that run() retries conversion and calls schedulePdfConversion for eligible messages.
     *
     * @return void
     */
    public function testJobRetriesConversionForEligibleMessages(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['register', '', 'procest'],
                ['email_message_schema', '', 'emailMessage'],
                ['register', 'procest'],
                ['email_message_schema', 'emailMessage'],
            ]
        );

        $failedMessages = [
            ['id' => 'msg-uuid-1', 'pdfStatus' => 'failed', 'pdfRetryCount' => 0],
        ];

        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->method('findObjects')->willReturn($failedMessages);
        $objectService->method('saveObject')->willReturn([]);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $this->emailService->expects($this->once())->method('schedulePdfConversion')
            ->with(messageId: 'msg-uuid-1');

        $job = new EmailPdfRetryJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);

    }//end testJobRetriesConversionForEligibleMessages()

    /**
     * Test that run() skips messages that have exceeded max retries.
     *
     * @return void
     */
    public function testJobSkipsMessagesExceedingMaxRetries(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['register', '', 'procest'],
                ['email_message_schema', '', 'emailMessage'],
                ['register', 'procest'],
                ['email_message_schema', 'emailMessage'],
            ]
        );

        // Message has already been retried 3 times (= MAX_RETRIES).
        $failedMessages = [
            ['id' => 'msg-uuid-2', 'pdfStatus' => 'failed', 'pdfRetryCount' => 3],
        ];

        // phpcs:disable CustomSn.Functions.NamedParameters
        $objectService->method('findObjects')->willReturn($failedMessages);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $this->emailService->expects($this->never())->method('schedulePdfConversion');

        $job = new EmailPdfRetryJob(
            $this->time,
            $this->emailService,
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );

        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, null);

    }//end testJobSkipsMessagesExceedingMaxRetries()

    /**
     * Build a minimal object-service mock with no-op methods.
     *
     * @return object
     */
    private function createObjectServiceMock(): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects', 'findObject', 'saveObject'])
            ->getMock();
        // phpcs:disable CustomSn.Functions.NamedParameters
        $mock->method('saveObject')->willReturn([]);
        $mock->method('findObject')->willReturn(null);
        $mock->method('findObjects')->willReturn([]);
        // phpcs:enable CustomSn.Functions.NamedParameters
        return $mock;

    }//end createObjectServiceMock()
}//end class
