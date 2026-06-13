<?php

/**
 * AgendaService Unit Tests
 *
 * Tests for the besluitvorming agenda item manager: appending agenda items to
 * a case's agendaItems[] array, item id assignment, patch-by-itemId updates,
 * and the OpenRegister persistence contract (find + saveObject named args).
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
use Psr\Log\LoggerInterface;

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
     * @return mixed
     */
    public function find(string $id, string $register, string $schema): mixed;

    /**
     * Save or update an object.
     *
     * @param array  $object   The object payload.
     * @param string $register The register slug.
     * @param string $schema   The schema id.
     *
     * @return array
     */
    public function saveObject(array $object, string $register, string $schema): array;
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
        $this->settingsService = $this->createMock(originalClassName: SettingsService::class);
        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                if ($key === 'register') {
                    return 'reg';
                }

                return 'schema-'.$key;
            }
        );

        $this->service = new AgendaService(settingsService: $this->settingsService, logger: $logger);
    }//end setUp()

    /**
     * AddToAgenda appends an item with a generated itemId + createdAt and persists it.
     *
     * @return void
     */
    public function testAddToAgendaAppendsAndPersists(): void
    {
        $objectService = $this->createMock(originalClassName: AgendaObjectServiceStub::class);
        $objectService->method('find')->willReturn(['id' => 'c1', 'agendaItems' => []]);

        $saved = null;
        $objectService->method('saveObject')->willReturnCallback(
            static function (array $object) use (&$saved): array {
                $saved = $object;
                return $object;
            }
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $result = $this->service->addToAgenda('c1', ['meetingDate' => '2026-07-01', 'discussionStatus' => 'gepland']);

        $this->assertSame(expected: 'c1', actual: $result['caseId']);
        $this->assertCount(expectedCount: 1, haystack: $result['agendaItems']);
        $this->assertArrayHasKey(key: 'itemId', array: $result['agendaItems'][0]);
        $this->assertArrayHasKey(key: 'createdAt', array: $result['agendaItems'][0]);
        $this->assertSame(expected: '2026-07-01', actual: $result['agendaItems'][0]['meetingDate']);
        $this->assertNotNull(actual: $saved);
        $this->assertSame(expected: $result['agendaItems'], actual: $saved['agendaItems']);
    }//end testAddToAgendaAppendsAndPersists()

    /**
     * AddToAgenda decodes a JSON-string agendaItems field (procest string-encoding contract).
     *
     * @return void
     */
    public function testAddToAgendaDecodesJsonStringItems(): void
    {
        $objectService = $this->createMock(originalClassName: AgendaObjectServiceStub::class);
        $existing      = json_encode([['itemId' => 'agenda_old', 'meetingDate' => '2026-06-01']]);
        $objectService->method('find')->willReturn(['id' => 'c1', 'agendaItems' => $existing]);
        $objectService->method('saveObject')->willReturnArgument(0);

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $result = $this->service->addToAgenda('c1', ['meetingDate' => '2026-07-01']);

        $this->assertCount(expectedCount: 2, haystack: $result['agendaItems']);
        $this->assertSame(expected: 'agenda_old', actual: $result['agendaItems'][0]['itemId']);
    }//end testAddToAgendaDecodesJsonStringItems()

    /**
     * UpdateAgendaItem merges a patch onto an existing item matched by itemId.
     *
     * @return void
     */
    public function testUpdateAgendaItemMergesByItemId(): void
    {
        $objectService = $this->createMock(originalClassName: AgendaObjectServiceStub::class);
        $objectService->method('find')->willReturn(
            [
                'id'          => 'c1',
                'agendaItems' => [
                    ['itemId' => 'a1', 'meetingDate' => '2026-06-01', 'discussionStatus' => 'gepland'],
                ],
            ]
        );
        $objectService->method('saveObject')->willReturnArgument(0);

        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $result = $this->service->updateAgendaItem('c1', ['itemId' => 'a1', 'discussionStatus' => 'behandeld']);

        $this->assertCount(expectedCount: 1, haystack: $result['agendaItems']);
        $this->assertSame(expected: 'behandeld', actual: $result['agendaItems'][0]['discussionStatus']);
        $this->assertSame(expected: '2026-06-01', actual: $result['agendaItems'][0]['meetingDate']);
    }//end testUpdateAgendaItemMergesByItemId()

    /**
     * UpdateAgendaItem requires an itemId in the patch.
     *
     * @return void
     */
    public function testUpdateAgendaItemRequiresItemId(): void
    {
        $objectService = $this->createMock(originalClassName: AgendaObjectServiceStub::class);
        $objectService->method('find')->willReturn(['id' => 'c1', 'agendaItems' => []]);
        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->expectException(exception: \InvalidArgumentException::class);
        $this->service->updateAgendaItem('c1', ['discussionStatus' => 'behandeld']);
    }//end testUpdateAgendaItemRequiresItemId()

    /**
     * UpdateAgendaItem throws when the itemId is not present on the case.
     *
     * @return void
     */
    public function testUpdateAgendaItemThrowsWhenNotFound(): void
    {
        $objectService = $this->createMock(originalClassName: AgendaObjectServiceStub::class);
        $objectService->method('find')->willReturn(['id' => 'c1', 'agendaItems' => []]);
        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->expectException(exception: \RuntimeException::class);
        $this->service->updateAgendaItem('c1', ['itemId' => 'missing']);
    }//end testUpdateAgendaItemThrowsWhenNotFound()

    /**
     * AddToAgenda throws when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testAddToAgendaThrowsWhenObjectServiceMissing(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->expectException(exception: \RuntimeException::class);
        $this->service->addToAgenda('c1', ['meetingDate' => '2026-07-01']);
    }//end testAddToAgendaThrowsWhenObjectServiceMissing()
}//end class
