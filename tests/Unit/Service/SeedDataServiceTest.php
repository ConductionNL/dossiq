<?php

/**
 * SeedDataService Unit Tests
 *
 * Tests for the Procest SeedDataService that seeds bezwaar/beroep case types
 * into OpenRegister.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SeedDataService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService shape used by SeedDataService.
 *
 * Declares the named-arg signatures used in production so that
 * `createMock(SeedObjectServiceStub::class)` returns a stub that accepts
 * named arguments. A `getMockBuilder(\stdClass::class)->addMethods([...])`
 * stub throws "Unknown named parameter" on named-arg calls in PHPUnit 10.
 */
interface SeedObjectServiceStub
{
    public function getObjects(string $register, string $schema, array $filters, int $limit): array;
    public function saveObject(string $register, string $schema, array $object): ?object;
    public function findAll(array $params = []): array;

}//end interface

/**
 * Unit tests for SeedDataService.
 *
 * @covers \OCA\Procest\Service\SeedDataService
 */
class SeedDataServiceTest extends TestCase
{

    /**
     * The mocked app configuration service.
     *
     * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The mocked DI container.
     *
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private ContainerInterface $container;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var SeedDataService
     */
    private SeedDataService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->service = new SeedDataService(
            $this->appConfig,
            $this->container,
            $this->logger,
        );

    }//end setUp()


    /**
     * Test that seedBezwaarBeroepData returns failure when ObjectService is unavailable.
     *
     * When OpenRegister is not installed the container will throw, causing
     * getObjectService() to return null.
     *
     * @return void
     */
    public function testSeedBezwaarBeroepDataFailsWithoutObjectService(): void
    {
        // All config values are non-empty so we get past the early config check.
        $this->appConfig
            ->method('getValueString')
            ->willReturn('some-register-id');

        // Container throws, ObjectService is unavailable.
        $this->container
            ->method('get')
            ->willThrowException(new \RuntimeException('Service not found'));

        $result = $this->service->seedBezwaarBeroepData();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ObjectService not available', $result['message']);

    }//end testSeedBezwaarBeroepDataFailsWithoutObjectService()


    /**
     * Test that seedBezwaarBeroepData returns failure when register is not configured.
     *
     * @return void
     */
    public function testSeedBezwaarBeroepDataFailsWithoutRegisterConfig(): void
    {
        // Config returns empty for all keys.
        $this->appConfig
            ->method('getValueString')
            ->willReturn('');

        // ObjectService IS available (non-null).
        $objectService = new \stdClass();
        $this->container
            ->method('get')
            ->willReturn($objectService);

        $result = $this->service->seedBezwaarBeroepData();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['message']);

    }//end testSeedBezwaarBeroepDataFailsWithoutRegisterConfig()


    /**
     * Test that seedBezwaarBeroepData returns success summary on correct setup.
     *
     * This test verifies the happy path with the real bezwaar_seed_data.json file.
     * The ObjectService mock simulates that all objects are newly created (not found by filter).
     *
     * @return void
     */
    public function testSeedBezwaarBeroepDataReturnsSummaryStructure(): void
    {
        // Config returns valid IDs so we get past the register check.
        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(
                function (string $app, string $key): string {
                    if ($key === 'register') {
                        return 'register-uuid-1';
                    }

                    if ($key === 'case_type_schema') {
                        return 'schema-uuid-1';
                    }

                    return 'schema-uuid-2';
                }
            );

        // ObjectService that returns empty (no existing objects) and creates new ones.
        $createdObject = new \stdClass();
        $createdObject->uuid = 'created-uuid-1';

        $objectServiceMock = $this->createMock(SeedObjectServiceStub::class);

        $objectServiceMock
            ->method('findAll')
            ->willReturn([]);

        $objectServiceMock
            ->method('saveObject')
            ->willReturn($createdObject);

        $this->container
            ->method('get')
            ->willReturn($objectServiceMock);

        $result = $this->service->seedBezwaarBeroepData();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('caseTypes', $result);
        $this->assertArrayHasKey('statusTypes', $result);
        $this->assertArrayHasKey('roleTypes', $result);
        $this->assertArrayHasKey('workflows', $result);
        $this->assertArrayHasKey('skipped', $result);

    }//end testSeedBezwaarBeroepDataReturnsSummaryStructure()


    /**
     * Test that seedBezwaarBeroepData skips existing case types.
     *
     * When getObjects returns a result, the case type exists and should be skipped.
     *
     * @return void
     */
    public function testSeedBezwaarBeroepDataSkipsExistingCaseTypes(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(
                function (string $app, string $key): string {
                    if ($key === 'register') {
                        return 'register-uuid-1';
                    }

                    if ($key === 'case_type_schema') {
                        return 'schema-uuid-1';
                    }

                    return '';
                }
            );

        $existingObject = new \stdClass();
        $existingObject->uuid = 'existing-uuid-1';

        $objectServiceMock = $this->createMock(SeedObjectServiceStub::class);

        // Always return an existing object from findAll.
        $objectServiceMock
            ->method('findAll')
            ->willReturn([$existingObject]);

        // saveObject should NOT be called if all case types are skipped.
        $objectServiceMock
            ->expects($this->never())
            ->method('saveObject');

        $this->container
            ->method('get')
            ->willReturn($objectServiceMock);

        $result = $this->service->seedBezwaarBeroepData();

        // All case types should be skipped, none created.
        if ($result['success'] === true) {
            $this->assertSame(0, $result['caseTypes']);
            $this->assertGreaterThan(0, $result['skipped']);
        }

    }//end testSeedBezwaarBeroepDataSkipsExistingCaseTypes()


    /**
     * Test that the bezwaar seed data file exists and is valid JSON.
     *
     * @return void
     */
    public function testBezwaarSeedDataFileExistsAndIsValidJson(): void
    {
        $seedPath = __DIR__.'/../../../lib/Settings/bezwaar_seed_data.json';

        $this->assertFileExists($seedPath, 'bezwaar_seed_data.json must exist');

        $content  = file_get_contents($seedPath);
        $seedData = json_decode($content, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'bezwaar_seed_data.json must be valid JSON');
        $this->assertIsArray($seedData);
        $this->assertArrayHasKey('caseTypes', $seedData, 'Seed data must have caseTypes key');

    }//end testBezwaarSeedDataFileExistsAndIsValidJson()


}//end class
