<?php

/**
 * SyncQueueReplayService Unit Tests.
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
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\SyncBackoffService;
use OCA\Procest\Service\SyncQueueReplayService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SyncQueueReplayService.
 *
 * @covers \OCA\Procest\Service\SyncQueueReplayService
 */
class SyncQueueReplayServiceTest extends TestCase
{

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var SyncQueueReplayService
     */
    private SyncQueueReplayService $service;

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

        $this->service = new SyncQueueReplayService(
            $this->settingsService,
            new SyncBackoffService(),
            $logger
        );
    }//end setUp()

    /**
     * Build a stub ObjectService returning the supplied rows from findAll.
     *
     * @param array<int, array<string, mixed>> $rows Rows to return.
     *
     * @return object The stub object service.
     */
    private function stubObjectService(array $rows): object
    {
        return new class($rows) {
            /**
             * @param array<int, array<string, mixed>> $rows Rows.
             */
            public function __construct(private array $rows)
            {
            }

            /**
             * @param array<string, mixed> $query Query.
             *
             * @return array<int, array<string, mixed>> Rows.
             */
            public function findAll(array $query): array
            {
                return $this->rows;
            }

            /**
             * @param mixed ...$args Arguments.
             *
             * @return array<string, mixed> Saved object.
             */
            public function saveObject(...$args): array
            {
                return ($args['object'] ?? []);
            }
        };
    }//end stubObjectService()

    /**
     * Pending operations are returned ordered by queuedAt and scoped to owner.
     *
     * @return void
     */
    public function testListPendingOrdersAndScopesByInspector(): void
    {
        $rows = [
            ['id' => '2', 'deviceId' => 'd1', 'inspectorRef' => 'anja', 'status' => 'pending', 'queuedAt' => '2026-05-22T09:30:00+00:00'],
            ['id' => '1', 'deviceId' => 'd1', 'inspectorRef' => 'anja', 'status' => 'pending', 'queuedAt' => '2026-05-22T09:00:00+00:00'],
            ['id' => 'x', 'deviceId' => 'd1', 'inspectorRef' => 'someone-else', 'status' => 'pending', 'queuedAt' => '2026-05-22T08:00:00+00:00'],
            ['id' => '3', 'deviceId' => 'd1', 'inspectorRef' => 'anja', 'status' => 'synced', 'queuedAt' => '2026-05-22T07:00:00+00:00'],
        ];
        $this->settingsService->method('getObjectService')->willReturn($this->stubObjectService($rows));

        $result = $this->service->listPending('d1', 'anja');
        $ids    = array_column($result, 'id');

        // 'x' belongs to another inspector (dropped); '3' is already synced
        // (not in pending/conflict); remaining are ordered by queuedAt.
        $this->assertSame(['1', '2'], $ids);
    }//end testListPendingOrdersAndScopesByInspector()

    /**
     * Failed operations are only included when requested.
     *
     * @return void
     */
    public function testListPendingIncludesFailedOnlyWhenRequested(): void
    {
        $rows = [
            ['id' => 'f', 'deviceId' => 'd1', 'inspectorRef' => 'anja', 'status' => 'failed', 'queuedAt' => '2026-05-22T09:00:00+00:00'],
        ];
        $this->settingsService->method('getObjectService')->willReturn($this->stubObjectService($rows));

        $this->assertCount(0, $this->service->listPending('d1', 'anja'));
        $this->assertCount(1, $this->service->listPending('d1', 'anja', includeFailed: true));
    }//end testListPendingIncludesFailedOnlyWhenRequested()

    /**
     * With no ObjectService available the queue is empty.
     *
     * @return void
     */
    public function testListPendingWithoutObjectService(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);
        $this->assertSame([], $this->service->listPending('d1', 'anja'));
    }//end testListPendingWithoutObjectService()

    /**
     * recordOutcome applies the status transition and returns the saved op.
     *
     * @return void
     */
    public function testRecordOutcomePersistsTransition(): void
    {
        $this->settingsService->method('getObjectService')->willReturn($this->stubObjectService([]));

        $op      = ['id' => '1', 'status' => 'syncing', 'attemptCount' => 0];
        $updated = $this->service->recordOutcome($op, 'success', null);

        $this->assertSame(SyncBackoffService::STATUS_SYNCED, $updated['status']);
    }//end testRecordOutcomePersistsTransition()
}//end class
