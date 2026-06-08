<?php

/**
 * DsoDeadlineJob Unit Tests
 *
 * Tests for the DSO deadline monitoring background job. Covers working-day
 * calculation, per-zaak exception isolation, and notification dispatch.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T14
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\BackgroundJob;

use OCA\Procest\BackgroundJob\DsoDeadlineJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService stub with named-parameter signatures.
 *
 * The OpenRegister ObjectService is resolved at runtime and called with named
 * arguments; a \stdClass-based mock generates positional-only signatures and
 * fails at call time with "Unknown named parameter". This typed interface lets
 * PHPUnit generate a mock whose method signatures accept the named arguments.
 */
interface DsoDeadlineObjectServiceStub
{
    /**
     * Find objects matching the given parameters.
     *
     * @param string              $register Register slug
     * @param string              $schema   Schema slug
     * @param array<string,mixed> $params   Query parameters
     *
     * @return array<int,array<string,mixed>>
     */
    public function findObjects(string $register, string $schema, array $params): array;

    /**
     * Save or update an object.
     *
     * @param array<string,mixed> $object   Object data
     * @param string              $register Register slug
     * @param string              $schema   Schema slug
     * @param string|null         $uuid     Optional object UUID for updates
     *
     * @return array<string,mixed>
     */
    public function saveObject(array $object, string $register, string $schema, ?string $uuid=null): array;
}//end interface

/**
 * Unit tests for DsoDeadlineJob.
 *
 * @covers \OCA\Procest\BackgroundJob\DsoDeadlineJob
 */
class DsoDeadlineJobTest extends TestCase
{

    /**
     * The ITimeFactory mock.
     *
     * @var ITimeFactory|MockObject
     */
    private ITimeFactory $timeFactory;

    /**
     * The IAppConfig mock.
     *
     * @var IAppConfig|MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The ContainerInterface mock.
     *
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface $container;

    /**
     * The INotificationManager mock.
     *
     * @var INotificationManager|MockObject
     */
    private INotificationManager $notificationManager;

    /**
     * The LoggerInterface mock.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->timeFactory         = $this->createMock(ITimeFactory::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build a DsoDeadlineJob instance for testing.
     *
     * @return DsoDeadlineJob
     */
    private function buildJob(): DsoDeadlineJob
    {
        return new DsoDeadlineJob(
            timeFactory: $this->timeFactory,
            appConfig: $this->appConfig,
            container: $this->container,
            notificationManager: $this->notificationManager,
            logger: $this->logger,
        );
    }//end buildJob()

    /**
     * Test that getRemainingWorkingDays returns a positive value for a future date.
     *
     * We use reflection to access the private method.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T14
     */
    public function testGetRemainingWorkingDaysReturnsPositiveForFutureDate(): void
    {
        $job    = $this->buildJob();
        $method = new \ReflectionMethod(DsoDeadlineJob::class, 'getRemainingWorkingDays');
        $method->setAccessible(true);

        // Use a date far in the future.
        $futureDate = (new \DateTimeImmutable('today'))->modify('+90 days')->format('Y-m-d');
        $remaining  = $method->invoke($job, $futureDate);

        $this->assertGreaterThan(0, $remaining, 'A future deadline should have positive remaining days.');
    }//end testGetRemainingWorkingDaysReturnsPositiveForFutureDate()

    /**
     * Test that getRemainingWorkingDays returns a negative value for a past date.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T14
     */
    public function testGetRemainingWorkingDaysReturnsNegativeForPastDate(): void
    {
        $job    = $this->buildJob();
        $method = new \ReflectionMethod(DsoDeadlineJob::class, 'getRemainingWorkingDays');
        $method->setAccessible(true);

        // Use a date well in the past.
        $pastDate  = (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
        $remaining = $method->invoke($job, $pastDate);

        $this->assertLessThan(0, $remaining, 'A past deadline should return a negative remaining count.');
    }//end testGetRemainingWorkingDaysReturnsNegativeForPastDate()

    /**
     * Test that run() catches per-zaak exceptions and continues processing.
     *
     * When one zaak throws an exception it must not abort the whole job.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T14
     */
    public function testRunCatchesExceptionsPerTask(): void
    {
        $objectServiceMock = $this->createMock(DsoDeadlineObjectServiceStub::class);

        // Return a zaak with a past deadline so the overdue branch runs,
        // and inject a sub-call that throws to verify per-zaak isolation.
        $pastDeadline = (new \DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d');
        $zaken        = [
            [
                'id'              => 'zaak-overdue-1',
                'status'          => 'ingediend',
                'caseType'        => 'omgevingsvergunning',
                'deadlineDatum'   => $pastDeadline,
                'assigneeUserId'  => '',
                'deadlineOverdue' => false,
                'activityLog'     => [],
            ],
        ];

        $objectServiceMock
            ->expects($this->once())
            ->method('findObjects')
            ->willReturn($zaken);

        // saveObject throws for this zaak — the job must swallow it.
        $objectServiceMock
            ->expects($this->once())
            ->method('saveObject')
            ->willThrowException(new \RuntimeException('DB write failed'));

        $this->container
            ->method('get')
            ->willReturn($objectServiceMock);

        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(
                    function (string $app, string $key, string $default='') {
                        $map = [
                            'register'                            => 'procest-register',
                            'case_schema'                         => 'case-schema-id',
                            'dso_deadline_warning_weeks_warning'  => '14',
                            'dso_deadline_warning_weeks_critical' => '5',
                        ];
                        return $map[$key] ?? $default;
                    }
                    );

        // Error must be logged but the job should NOT re-throw.
        $this->logger->expects($this->atLeastOnce())->method('error');

        $job = $this->buildJob();

        // run() is protected; use reflection.
        $method = new \ReflectionMethod(DsoDeadlineJob::class, 'run');
        $method->setAccessible(true);

        // Should not throw.
        $method->invoke($job, null);

        // If we reach this assertion, the exception was swallowed correctly.
        $this->assertTrue(true);
    }//end testRunCatchesExceptionsPerTask()
}//end class
