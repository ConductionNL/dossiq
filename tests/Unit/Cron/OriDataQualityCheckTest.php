<?php

/**
 * OriDataQualityCheck Cron Job Unit Tests
 *
 * Tests for the nightly ORI data quality check background job.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Cron
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

namespace OCA\Procest\Tests\Unit\Cron;

use OCA\Procest\Cron\OriDataQualityCheck;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService stub matching named-argument signatures used in
 * OriDataQualityCheck so createMock() honours named args.
 */
interface QualityObjectServiceStub
{
    /**
     * Find objects matching params.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param array  $params   Query parameters
     *
     * @return array
     */
    public function findObjects(string $register, string $schema, array $params): array;

    /**
     * Find a single object by ID or slug.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID or slug
     *
     * @return array|null
     */
    public function findObject(string $register, string $schema, string $id): ?array;

    /**
     * Save an object.
     *
     * @param array  $object   The object data
     * @param string $register The register slug
     * @param string $schema   The schema slug
     *
     * @return array
     */
    public function saveObject(array $object, string $register, string $schema): array;
}//end interface

/**
 * Unit tests for OriDataQualityCheck.
 *
 * @covers \OCA\Procest\Cron\OriDataQualityCheck
 */
class OriDataQualityCheckTest extends TestCase
{

    /**
     * The mocked time factory.
     *
     * @var ITimeFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private ITimeFactory $timeFactory;

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
     * The job under test.
     *
     * @var OriDataQualityCheck
     */
    private OriDataQualityCheck $job;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->timeFactory     = $this->createMock(ITimeFactory::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->job = new OriDataQualityCheck(
            time: $this->timeFactory,
            settingsService: $this->settingsService,
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

        $this->settingsService
            ->expects($this->never())
            ->method('getObjectService');

        $ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke($this->job, null);

    }//end testRunExitsEarlyWhenOpenRegisterNotInstalled()


    /**
     * Test that run() exits early when ObjectService is unavailable.
     *
     * @return void
     */
    public function testRunExitsEarlyWhenObjectServiceUnavailable(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'procest']);

        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke($this->job, null);

        $this->assertTrue(true);

    }//end testRunExitsEarlyWhenObjectServiceUnavailable()


    /**
     * Test that run() invokes data quality checks and logs completion when available.
     *
     * @return void
     */
    public function testRunInvokesQualityChecksWhenAvailable(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn(['openregister', 'procest']);

        $objectService = $this->createMock(QualityObjectServiceStub::class);
        $objectService->method('findObjects')->willReturn([]);
        $objectService->method('findObject')->willReturn(null);
        $objectService->method('saveObject')->willReturn([]);

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturn('procest-register');

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
        $ref->setAccessible(accessible: true);
        $ref->invoke($this->job, null);

    }//end testRunInvokesQualityChecksWhenAvailable()


}//end class
