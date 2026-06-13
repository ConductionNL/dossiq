<?php

/**
 * AgendaService Unit Tests
 *
 * Tests for the agenda compiler: ready-item filtering by gremium, item
 * classification, agenda confirmation, and ordered document generation.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\AgendaService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ObjectService stub matching the named-argument signatures used by AgendaService.
 */
interface AgendaObjectServiceStub
{
    /**
     * Find a single object by id.
     *
     * @param string $id       The object id.
     * @param string $register The register slug.
     * @param string $schema   The schema id.
     *
     * @return array
     */
    public function find(string $id, string $register, string $schema): array;

    /**
     * Save or update an object.
     *
     * @param string $register The register slug.
     * @param string $schema   The schema id.
     * @param array  $object   The object payload.
     * @param string $id       Optional id for update.
     *
     * @return array
     */
    public function saveObject(string $register, string $schema, array $object, string $id=''): array;

    /**
     * Find objects.
     *
     * @param array $params The query params.
     *
     * @return array
     */
    public function findAll(array $params=[]): array;
}//end interface

/**
 * Unit tests for AgendaService.
 *
 * @covers \OCA\Procest\Service\AgendaService
 */
class AgendaServiceTest extends TestCase
{
    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked container.
     *
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private ContainerInterface $container;

    /**
     * The service under test.
     *
     * @var AgendaService
     */
    private AgendaService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $logger                = $this->createMock(LoggerInterface::class);

        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static fn (string $key): string => $key === 'register' ? 'reg' : 'schema-'.$key,
        );

        $this->service = new AgendaService($this->settingsService, $this->container, $logger);
    }//end setUp()

    /**
     * addItem rejects invalid classifications.
     *
     * @return void
     */
    public function testAddItemRejectsInvalidClassification(): void
    {
        $objectService = $this->createMock(AgendaObjectServiceStub::class);
        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->expectException(RuntimeException::class);
        $this->service->addItem('c1', 'onbekend', 1);
    }//end testAddItemRejectsInvalidClassification()

    /**
     * addItem persists the behandeling caseProperty.
     *
     * @return void
     */
    public function testAddItemPersistsBehandeling(): void
    {
        $saved = [];
        $objectService = $this->createMock(AgendaObjectServiceStub::class);
        $objectService->method('findAll')->willReturn(['results' => []]);
        $objectService->method('saveObject')->willReturnCallback(
            static function (string $r, string $s, array $o) use (&$saved): array {
                $saved[] = $o;
                return $o;
            },
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $result = $this->service->addItem('c1', AgendaService::BEHANDELING_BESPREEKSTUK, 2);

        $this->assertSame('bespreekstuk', $result['behandeling']);
        $this->assertSame(2, $result['order']);
        $names = array_column($saved, 'name');
        $this->assertContains('behandeling', $names);
    }//end testAddItemPersistsBehandeling()

    /**
     * getReadyItems returns the cases the register reports.
     *
     * @return void
     */
    public function testGetReadyItemsReturnsCases(): void
    {
        $objectService = $this->createMock(AgendaObjectServiceStub::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $params): array {
                $filters = $params['filters'] ?? [];
                // caseType resolution + statusType resolution return single rows;
                // the case query returns two cases.
                if (($filters['schema'] ?? '') === 'schema-case_type_schema') {
                    return ['results' => [['id' => 'ct-college']]];
                }

                if (($filters['schema'] ?? '') === 'schema-status_type_schema') {
                    return ['results' => [['id' => 'status-gereed']]];
                }

                return ['results' => [['id' => 'c1', 'title' => 'Zaak 1'], ['id' => 'c2', 'title' => 'Zaak 2']]];
            },
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $items = $this->service->getReadyItems('College-besluit');
        $this->assertCount(2, $items);
        $this->assertSame('Zaak 1', $items[0]['title']);
    }//end testGetReadyItemsReturnsCases()

    /**
     * generateAgendaDocument orders hamerstukken before bespreekstukken.
     *
     * @return void
     */
    public function testGenerateAgendaOrdersHamerstukkenFirst(): void
    {
        $this->container->method('has')->willReturn(false);

        $objectService = $this->createMock(AgendaObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            static fn (string $id): array => ['id' => $id, 'title' => 'Zaak '.$id],
        );
        $objectService->method('findAll')->willReturnCallback(
            static function (array $params): array {
                $filters = $params['filters'] ?? [];
                $case    = $filters['case'] ?? '';
                $name    = $filters['name'] ?? '';
                if ($name === 'behandeling') {
                    return ['results' => [['value' => $case === 'b1' ? 'bespreekstuk' : 'hamerstuk']]];
                }

                return ['results' => [['value' => '1.1']]];
            },
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $result = $this->service->generateAgendaDocument(['b1', 'h1']);
        $this->assertSame('h1', $result['items'][0]['case']);
        $this->assertSame('b1', $result['items'][1]['case']);
    }//end testGenerateAgendaOrdersHamerstukkenFirst()
}//end class
