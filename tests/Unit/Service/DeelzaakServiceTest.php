<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Procest DeelzaakService Unit Tests
 *
 * Tests for deelzaak (sub-case) creation, hierarchy retrieval,
 * closure guard, and vervolg-zaak creation.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\DeelzaakService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DeelzaakService.
 *
 * @covers \OCA\Procest\Service\DeelzaakService
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */
class DeelzaakServiceTest extends TestCase
{

    /**
     * Mock PSR logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Mock settings service.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService $settingsService;

    /**
     * The service under test.
     *
     * @var DeelzaakService
     */
    private DeelzaakService $service;

    /**
     * Set up the test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);

        $this->service = new DeelzaakService(
            settingsService: $this->settingsService,
            logger: $this->logger
        );
    }//end setUp()

    // -------------------------------------------------------------------------
    // createDeelzaak() tests
    // -------------------------------------------------------------------------

    /**
     * CreateDeelzaak throws RuntimeException when objectService unavailable.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V01
     */
    public function testCreateDeelzaakThrowsWhenNoObjectService(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/objectService unavailable/');

        $this->service->createDeelzaak(
            parentCaseId: 'parent-uuid',
            caseTypeId: 'type-uuid'
        );
    }//end testCreateDeelzaakThrowsWhenNoObjectService()

    /**
     * CreateDeelzaak throws RuntimeException when parent case not found.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V01
     */
    public function testCreateDeelzaakThrowsWhenParentNotFound(): void
    {
        $objectService = $this->createMockObjectService();
        $objectService->method('find')->willThrowException(new \Exception('not found'));

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'         => 'reg1',
                    'case_schema'      => 'cs1',
                    'case_type_schema' => 'cts1',
                    default            => '',
                };
            }
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Parent case.*not found/');

        $this->service->createDeelzaak(
            parentCaseId: '00000000-0000-0000-0000-000000000001',
            caseTypeId: '00000000-0000-0000-0000-000000000002'
        );
    }//end testCreateDeelzaakThrowsWhenParentNotFound()

    /**
     * CreateDeelzaak throws RuntimeException when caseTypeId is not in subCaseTypes.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V02
     */
    public function testCreateDeelzaakThrowsWhenTypeNotAllowed(): void
    {
        $parentCaseUuid     = '00000000-0000-0000-0000-000000000001';
        $parentCaseTypeUuid = '00000000-0000-0000-0000-000000000002';
        $allowedTypeUuid    = '00000000-0000-0000-0000-000000000003';
        $disallowedTypeUuid = '00000000-0000-0000-0000-000000000099';

        $parentCase = [
            'id'       => $parentCaseUuid,
            'title'    => 'Parent case',
            'caseType' => $parentCaseTypeUuid,
        ];

        $parentCaseType = [
            'id'           => $parentCaseTypeUuid,
            'title'        => 'Parent Type',
            'subCaseTypes' => [$allowedTypeUuid],
        ];

        $objectService = $this->createMockObjectService();
        $objectService->method('find')->willReturnCallback(
            static function (string $id) use ($parentCaseUuid, $parentCase, $parentCaseTypeUuid, $parentCaseType): array {
                return match ($id) {
                    $parentCaseUuid     => $parentCase,
                    $parentCaseTypeUuid => $parentCaseType,
                    default             => throw new \Exception("not found: {$id}"),
                };
            }
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'         => 'reg1',
                    'case_schema'      => 'cs1',
                    'case_type_schema' => 'cts1',
                    default            => '',
                };
            }
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not configured as an allowed deelzaaktype/');

        $this->service->createDeelzaak(
            parentCaseId: $parentCaseUuid,
            caseTypeId: $disallowedTypeUuid
        );
    }//end testCreateDeelzaakThrowsWhenTypeNotAllowed()

    /**
     * CreateDeelzaak inherits deadline and archiveNomination from parent.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V01
     */
    public function testCreateDeelzaakInheritsParentFields(): void
    {
        $parentCaseUuid     = '00000000-0000-0000-0000-000000000001';
        $parentCaseTypeUuid = '00000000-0000-0000-0000-000000000002';
        $deelzaakTypeUuid   = '00000000-0000-0000-0000-000000000003';

        $parentCase = [
            'id'                => $parentCaseUuid,
            'title'             => 'Parent case',
            'caseType'          => $parentCaseTypeUuid,
            'deadline'          => '2026-12-31',
            'archiveNomination' => 'vernietigen',
            'confidentiality'   => 'intern',
        ];

        $parentCaseType = [
            'id'           => $parentCaseTypeUuid,
            'title'        => 'Parent Type',
            'subCaseTypes' => [],
        ];

        $deelzaakType = [
            'id'    => $deelzaakTypeUuid,
            'title' => 'Advies Deelzaak',
        ];

        $capturedObject = [];

        $objectService = $this->createMockObjectService();
        $objectService->method('find')->willReturnCallback(
            static function (string $id) use ($parentCaseUuid, $parentCase, $parentCaseTypeUuid, $parentCaseType, $deelzaakTypeUuid, $deelzaakType): array {
                return match ($id) {
                    $parentCaseUuid     => $parentCase,
                    $parentCaseTypeUuid => $parentCaseType,
                    $deelzaakTypeUuid   => $deelzaakType,
                    default             => throw new \Exception("not found: {$id}"),
                };
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            static function (string $register, string $schema, array $object) use (&$capturedObject): array {
                $capturedObject = $object;
                return array_merge(['id' => '00000000-0000-0000-0000-000000000099'], $object);
            }
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'         => 'reg1',
                    'case_schema'      => 'cs1',
                    'case_type_schema' => 'cts1',
                    default            => '',
                };
            }
        );

        // subCaseTypes empty = all types allowed.
        $result = $this->service->createDeelzaak(
            parentCaseId: $parentCaseUuid,
            caseTypeId: $deelzaakTypeUuid
        );

        self::assertArrayHasKey('id', $result);
        self::assertSame('2026-12-31', $capturedObject['deadline']);
        self::assertSame('vernietigen', $capturedObject['archiveNomination']);
        self::assertSame($parentCaseUuid, $capturedObject['parentCase']);
    }//end testCreateDeelzaakInheritsParentFields()

    // -------------------------------------------------------------------------
    // validateClosureAllowed() tests
    // -------------------------------------------------------------------------

    /**
     * ValidateClosureAllowed throws when objectService unavailable.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V04
     */
    public function testValidateClosureAllowedThrowsWhenNoService(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->expectException(\RuntimeException::class);

        $this->service->validateClosureAllowed(caseId: 'some-id');
    }//end testValidateClosureAllowedThrowsWhenNoService()

    /**
     * ValidateClosureAllowed returns canClose=true when caseType does NOT require deelzaken closed.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V04
     */
    public function testValidateClosureAllowedWhenNotRequired(): void
    {
        $caseUuid     = '00000000-0000-0000-0000-000000000001';
        $caseTypeUuid = '00000000-0000-0000-0000-000000000002';

        $caseData = [
            'id'       => $caseUuid,
            'caseType' => $caseTypeUuid,
        ];

        $caseTypeData = [
            'id'                        => $caseTypeUuid,
            'requireAllDeelzakenClosed' => false,
        ];

        $objectService = $this->createMockObjectService();
        $objectService->method('find')->willReturnCallback(
            static function (string $id) use ($caseUuid, $caseData, $caseTypeUuid, $caseTypeData): array {
                return match ($id) {
                    $caseUuid     => $caseData,
                    $caseTypeUuid => $caseTypeData,
                    default       => throw new \Exception("not found: {$id}"),
                };
            }
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'         => 'reg1',
                    'case_schema'      => 'cs1',
                    'case_type_schema' => 'cts1',
                    default            => '',
                };
            }
        );

        $result = $this->service->validateClosureAllowed(caseId: $caseUuid);

        self::assertTrue($result['canClose']);
        self::assertEmpty($result['openDeelzaken']);
    }//end testValidateClosureAllowedWhenNotRequired()

    /**
     * ValidateClosureAllowed returns canClose=false when open deelzaken exist.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V04
     */
    public function testValidateClosureBlockedWhenOpenDeelzakenExist(): void
    {
        $caseUuid     = '00000000-0000-0000-0000-000000000001';
        $caseTypeUuid = '00000000-0000-0000-0000-000000000002';
        $childUuid    = '00000000-0000-0000-0000-000000000003';

        $caseData = [
            'id'       => $caseUuid,
            'caseType' => $caseTypeUuid,
        ];

        $caseTypeData = [
            'id'                        => $caseTypeUuid,
            'requireAllDeelzakenClosed' => true,
        ];

        $childCase = [
            'id'      => $childUuid,
            'title'   => 'Open deelzaak',
            'status'  => 'in-behandeling',
            'endDate' => null,
        ];

        $objectService = $this->createMockObjectService();
        $objectService->method('find')->willReturnCallback(
            static function (string $id) use ($caseUuid, $caseData, $caseTypeUuid, $caseTypeData): array {
                return match ($id) {
                    $caseUuid     => $caseData,
                    $caseTypeUuid => $caseTypeData,
                    default       => throw new \Exception("not found: {$id}"),
                };
            }
        );

        $objectService->method('buildSearchQuery')->willReturn([]);
        $objectService->method('searchObjectsPaginated')->willReturn(
            ['results' => [$childCase]]
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'         => 'reg1',
                    'case_schema'      => 'cs1',
                    'case_type_schema' => 'cts1',
                    default            => '',
                };
            }
        );

        $result = $this->service->validateClosureAllowed(caseId: $caseUuid);

        self::assertFalse($result['canClose']);
        self::assertCount(1, $result['openDeelzaken']);
        self::assertSame($childUuid, $result['openDeelzaken'][0]['id']);
    }//end testValidateClosureBlockedWhenOpenDeelzakenExist()

    // -------------------------------------------------------------------------
    // createVervolgzaak() tests
    // -------------------------------------------------------------------------

    /**
     * CreateVervolgzaak throws RuntimeException when objectService unavailable.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V05
     */
    public function testCreateVervolgzaakThrowsWhenNoObjectService(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/objectService unavailable/');

        $this->service->createVervolgzaak(
            sourceCaseId: 'source-uuid',
            caseTypeId: 'type-uuid'
        );
    }//end testCreateVervolgzaakThrowsWhenNoObjectService()

    /**
     * CreateVervolgzaak throws RuntimeException when source case not found.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V05
     */
    public function testCreateVervolgzaakThrowsWhenSourceNotFound(): void
    {
        $objectService = $this->createMockObjectService();
        $objectService->method('find')->willThrowException(new \Exception('not found'));

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'         => 'reg1',
                    'case_schema'      => 'cs1',
                    'case_type_schema' => 'cts1',
                    default            => '',
                };
            }
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Source case.*not found/');

        $this->service->createVervolgzaak(
            sourceCaseId: '00000000-0000-0000-0000-000000000001',
            caseTypeId: '00000000-0000-0000-0000-000000000002'
        );
    }//end testCreateVervolgzaakThrowsWhenSourceNotFound()

    /**
     * CreateVervolgzaak creates a new case with predecessor relatie link.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V05
     */
    public function testCreateVervolgzaakCreatesCaseWithPredecessorLink(): void
    {
        $sourceUuid = '00000000-0000-0000-0000-000000000001';
        $typeUuid   = '00000000-0000-0000-0000-000000000002';

        $sourceCase = [
            'id'    => $sourceUuid,
            'title' => 'Original case',
            'uri'   => "http://test/cases/{$sourceUuid}",
        ];

        $vervolgType = [
            'id'    => $typeUuid,
            'title' => 'Vervolg type',
        ];

        $capturedObjects = [];

        $objectService = $this->createMockObjectService();
        $objectService->method('find')->willReturnCallback(
            static function (string $id) use ($sourceUuid, $sourceCase, $typeUuid, $vervolgType): array {
                return match ($id) {
                    $sourceUuid => $sourceCase,
                    $typeUuid   => $vervolgType,
                    default     => throw new \Exception("not found: {$id}"),
                };
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            static function (string $register, string $schema, array $object) use (&$capturedObjects): array {
                $capturedObjects[] = $object;
                return array_merge(['id' => '00000000-0000-0000-0000-000000000099'], $object);
            }
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'         => 'reg1',
                    'case_schema'      => 'cs1',
                    'case_type_schema' => 'cts1',
                    default            => '',
                };
            }
        );

        $result = $this->service->createVervolgzaak(
            sourceCaseId: $sourceUuid,
            caseTypeId: $typeUuid
        );

        self::assertArrayHasKey('id', $result);
        self::assertSame('Vervolg type', $result['title']);

        // First save is the vervolg-zaak itself; it must have predecessor relatie.
        $vervolgData = $capturedObjects[0];
        self::assertArrayHasKey('relatedCases', $vervolgData);

        $relaties = json_decode($vervolgData['relatedCases'], true);
        self::assertCount(1, $relaties);
        self::assertSame('onderwerp', $relaties[0]['aardRelatie']);
    }//end testCreateVervolgzaakCreatesCaseWithPredecessorLink()

    // -------------------------------------------------------------------------
    // getHierarchy() tests
    // -------------------------------------------------------------------------

    /**
     * GetHierarchy returns a tree with the root case and its direct children.
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#V03
     */
    public function testGetHierarchyReturnsNestedTree(): void
    {
        $rootUuid = '00000000-0000-0000-0000-000000000001';
        $child1Id = '00000000-0000-0000-0000-000000000002';
        $child2Id = '00000000-0000-0000-0000-000000000003';

        $rootCase = ['id' => $rootUuid, 'title' => 'Root case'];
        $child1   = ['id' => $child1Id, 'title' => 'Child 1'];
        $child2   = ['id' => $child2Id, 'title' => 'Child 2'];

        $objectService = $this->createMockObjectService();
        $objectService->method('find')->willReturnCallback(
            static function (string $id) use ($rootUuid, $rootCase, $child1Id, $child1, $child2Id, $child2): array {
                return match ($id) {
                    $rootUuid => $rootCase,
                    $child1Id => $child1,
                    $child2Id => $child2,
                    default   => throw new \Exception("not found: {$id}"),
                };
            }
        );

        // First call for root → return 2 children; subsequent calls → empty.
        $callCount = 0;
        $objectService->method('buildSearchQuery')->willReturn([]);
        $objectService->method('searchObjectsPaginated')->willReturnCallback(
            static function () use (&$callCount, $child1, $child2): array {
                $callCount++;
                return match ($callCount) {
                    1       => ['results' => [$child1, $child2]],
                    default => ['results' => []],
                };
            }
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'    => 'reg1',
                    'case_schema' => 'cs1',
                    default       => '',
                };
            }
        );

        $tree = $this->service->getHierarchy(caseId: $rootUuid);

        self::assertSame($rootUuid, $tree['case']['id']);
        self::assertCount(2, $tree['children']);
        self::assertSame($child1Id, $tree['children'][0]['case']['id']);
        self::assertSame($child2Id, $tree['children'][1]['case']['id']);
    }//end testGetHierarchyReturnsNestedTree()

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a minimal mock objectService with all needed methods.
     *
     * @return MockObject
     */
    private function createMockObjectService(): MockObject
    {
        return $this->getMockBuilder(\stdClass::class)
            ->addMethods(['find', 'saveObject', 'buildSearchQuery', 'searchObjectsPaginated'])
            ->getMock();
    }//end createMockObjectService()
}//end class
