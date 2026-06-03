<?php

/**
 * AdvisoryBodyService Unit Tests
 *
 * Tests for AdvisoryBodyService covering search ranking, token issuance, and CRUD.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-03
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\AdvisoryBodyService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub for advisory body tests.
 */
interface AdvisoryObjectServiceStub
{
    public function findObjects(string $register, string $schema, array $filters, array $order, int $limit): array;
    public function saveObject(string $register, string $schema, array $data, string $id = ''): object;
    public function deleteObject(string $register, string $schema, string $id): void;

}//end interface

/**
 * Unit tests for AdvisoryBodyService.
 *
 * @covers \OCA\Procest\Service\AdvisoryBodyService
 */
class AdvisoryBodyServiceTest extends TestCase
{

    /**
     * Mocked SettingsService.
     *
     * @var SettingsService|MockObject
     */
    private SettingsService $settings;

    /**
     * Mocked LoggerInterface.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * Service under test.
     *
     * @var AdvisoryBodyService
     */
    private AdvisoryBodyService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settings = $this->createMock(SettingsService::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->service  = new AdvisoryBodyService(
            settingsService: $this->settings,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * searchBySpecialization returns bodies with matching tags first.
     *
     * @return void
     */
    public function testSearchBySpecializationRanksMatchingFirst(): void
    {
        $objectService = $this->createMock(AdvisoryObjectServiceStub::class);

        $bodies = [
            ['id' => 'b1', 'name' => 'Welstandscommissie', 'active' => true, 'specializations' => ['welstand', 'esthetiek']],
            ['id' => 'b2', 'name' => 'Brandweer',          'active' => true, 'specializations' => ['brandveiligheid']],
            ['id' => 'b3', 'name' => 'Milieudienst',       'active' => true, 'specializations' => ['milieu', 'brandveiligheid']],
        ];

        $objectService->method('findObjects')->willReturn($bodies);
        $this->settings->method('getObjectService')->willReturn($objectService);
        $this->settings->method('getConfigValue')->willReturn('some-id');

        $results = $this->service->searchBySpecialization(query: 'brand');

        // b2 and b3 match 'brand' in their specializations — should appear before b1.
        $this->assertCount(3, $results);
        $ids = array_column($results, 'id');
        $this->assertContains('b2', array_slice($ids, 0, 2));
        $this->assertContains('b3', array_slice($ids, 0, 2));
        $this->assertSame('b1', $ids[2]);

    }//end testSearchBySpecializationRanksMatchingFirst()


    /**
     * issueSecureToken returns a 64-character hexadecimal string.
     *
     * @return void
     */
    public function testIssueSecureTokenReturns64CharHexString(): void
    {
        $objectService = $this->createMock(AdvisoryObjectServiceStub::class);
        $savedObj      = new \stdClass();

        $objectService->method('saveObject')->willReturn($savedObj);
        $this->settings->method('getObjectService')->willReturn($objectService);
        $this->settings->method('getConfigValue')->willReturn('some-id');

        $token = $this->service->issueSecureToken(consultationId: 'con-uuid');

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

    }//end testIssueSecureTokenReturns64CharHexString()


    /**
     * findAll returns empty array when ObjectService is unavailable.
     *
     * @return void
     */
    public function testFindAllReturnsEmptyArrayWhenObjectServiceUnavailable(): void
    {
        $this->settings->method('getObjectService')->willReturn(null);

        $result = $this->service->findAll();
        $this->assertSame([], $result);

    }//end testFindAllReturnsEmptyArrayWhenObjectServiceUnavailable()


    /**
     * save throws RuntimeException when ObjectService is unavailable.
     *
     * @return void
     */
    public function testSaveThrowsWhenObjectServiceUnavailable(): void
    {
        $this->settings->method('getObjectService')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister is not available');

        $this->service->save(data: ['name' => 'Test'], id: '');

    }//end testSaveThrowsWhenObjectServiceUnavailable()


}//end class
