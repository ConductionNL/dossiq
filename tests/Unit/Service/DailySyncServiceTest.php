<?php

/**
 * DailySyncService Unit Tests.
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
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\DailySyncService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DailySyncService.
 *
 * @covers \OCA\Procest\Service\DailySyncService
 */
class DailySyncServiceTest extends TestCase
{

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var DailySyncService
     */
    private DailySyncService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $logger                = $this->createMock(LoggerInterface::class);
        $this->settingsService->method('getConfigValue')->willReturn('1');

        $this->service = new DailySyncService($this->settingsService, $logger);
    }//end setUp()

    /**
     * Build a stub ObjectService returning fixed rows per schema.
     *
     * @param array<string, array<int, array<string, mixed>>> $bySchema Rows keyed by schema.
     *
     * @return object The stub object service.
     */
    private function stubObjectService(array $bySchema): object
    {
        return new class($bySchema) {
            /**
             * @param array<string, array<int, array<string, mixed>>> $bySchema Rows keyed by schema.
             */
            public function __construct(private array $bySchema)
            {
            }

            /**
             * @param array<string, mixed> $query Query.
             *
             * @return array<int, array<string, mixed>> Rows for the queried schema.
             */
            public function findAll(array $query): array
            {
                $schema = ($query['filters']['schema'] ?? '');
                return ($this->bySchema[$schema] ?? []);
            }
        };
    }//end stubObjectService()

    /**
     * Only the requesting inspector's inspections for the date are returned.
     *
     * @return void
     */
    public function testGetScheduledInspectionsScopesByInspectorAndDate(): void
    {
        $rows = [
            ['id' => 'a', 'inspectorRef' => 'anja', 'scheduledAt' => '2026-05-22T09:00:00+00:00'],
            ['id' => 'b', 'inspectorRef' => 'anja', 'scheduledAt' => '2026-05-23T09:00:00+00:00'],
            ['id' => 'c', 'inspectorRef' => 'piet', 'scheduledAt' => '2026-05-22T09:00:00+00:00'],
        ];
        $this->settingsService->method('getObjectService')->willReturn(
            $this->stubObjectService(['fieldInspection' => $rows])
        );

        $result = $this->service->getScheduledInspections('anja', '2026-05-22');
        $ids    = array_column($result, 'id');

        $this->assertSame(['a'], $ids);
    }//end testGetScheduledInspectionsScopesByInspectorAndDate()

    /**
     * The daily payload bundles cases, checklists, manifest and expiry.
     *
     * @return void
     */
    public function testGetDailyPayloadShape(): void
    {
        $inspections = [
            ['id' => 'a', 'inspectorRef' => 'anja', 'scheduledAt' => '2026-05-22T09:00:00+00:00'],
        ];
        $checklists  = [
            ['id' => 'chk-1', 'name' => 'Bouwtoezicht Fase 1'],
        ];
        $this->settingsService->method('getObjectService')->willReturn(
            $this->stubObjectService(
                [
                    'fieldInspection'     => $inspections,
                    'inspectionChecklist' => $checklists,
                ]
            )
        );

        $payload = $this->service->getDailyPayload('anja', '2026-05-22');

        $this->assertSame('2026-05-22', $payload['date']);
        $this->assertSame('anja', $payload['inspectorRef']);
        $this->assertCount(1, $payload['cases']);
        $this->assertCount(1, $payload['checklists']);
        $this->assertTrue($payload['readyOffline']);
        $this->assertArrayHasKey('manifest', $payload);
        $this->assertSame(1, $payload['manifest']['caseCount']);
        $this->assertNotEmpty($payload['expiresAt']);
    }//end testGetDailyPayloadShape()

    /**
     * Only checklist templates referenced by today's inspections are bundled.
     *
     * @return void
     */
    public function testGetDailyPayloadFiltersChecklistsByReference(): void
    {
        $inspections = [
            ['id' => 'a', 'inspectorRef' => 'anja', 'scheduledAt' => '2026-05-22T09:00:00+00:00', 'checklistTemplateRef' => 'chk-1'],
        ];
        $checklists  = [
            ['id' => 'chk-1', 'name' => 'Bouwtoezicht Fase 1'],
            ['id' => 'chk-2', 'name' => 'Horeca controle'],
        ];
        $this->settingsService->method('getObjectService')->willReturn(
            $this->stubObjectService(
                [
                    'fieldInspection'     => $inspections,
                    'inspectionChecklist' => $checklists,
                ]
            )
        );

        $payload = $this->service->getDailyPayload('anja', '2026-05-22');

        $this->assertCount(1, $payload['checklists']);
        $this->assertSame('chk-1', $payload['checklists'][0]['id']);
    }//end testGetDailyPayloadFiltersChecklistsByReference()

    /**
     * The manifest flags slow connections for large payloads.
     *
     * @return void
     */
    public function testManifestFlagsSlowConnection(): void
    {
        $cases = [];
        for ($i = 0; $i < 12; $i++) {
            $cases[] = ['id' => 'case-'.$i];
        }

        $manifest = $this->service->buildManifest($cases, []);

        $this->assertSame(12, $manifest['caseCount']);
        $this->assertTrue($manifest['slowConnectionWarning']);
        $this->assertNotEmpty($manifest['zoomLevels']);
    }//end testManifestFlagsSlowConnection()

    /**
     * An empty schedule is not marked ready-offline.
     *
     * @return void
     */
    public function testEmptyScheduleNotReadyOffline(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(
            $this->stubObjectService([])
        );

        $payload = $this->service->getDailyPayload('anja', '2026-05-22');
        $this->assertFalse($payload['readyOffline']);
        $this->assertSame([], $payload['cases']);
    }//end testEmptyScheduleNotReadyOffline()
}//end class
