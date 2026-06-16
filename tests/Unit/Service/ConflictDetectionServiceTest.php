<?php

/**
 * ConflictDetectionService Unit Tests.
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
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-13
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ConflictDetectionService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConflictDetectionService.
 *
 * @covers \OCA\Procest\Service\ConflictDetectionService
 */
class ConflictDetectionServiceTest extends TestCase
{

    /**
     * @var ConflictDetectionService
     */
    private ConflictDetectionService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new ConflictDetectionService();
    }//end setUp()

    /**
     * A 409 with a server body is a concurrent edit.
     *
     * @return void
     */
    public function testClassifyConcurrentEdit(): void
    {
        $type = $this->service->classify(409, ['status' => 'afgekeurd']);
        $this->assertSame(ConflictDetectionService::TYPE_CONCURRENT_EDIT, $type);
    }//end testClassifyConcurrentEdit()

    /**
     * A 409 with no body, or a 404, is a deleted-remote conflict.
     *
     * @return void
     */
    public function testClassifyDeletedRemote(): void
    {
        $this->assertSame(ConflictDetectionService::TYPE_DELETED_REMOTE, $this->service->classify(409, null));
        $this->assertSame(ConflictDetectionService::TYPE_DELETED_REMOTE, $this->service->classify(409, []));
        $this->assertSame(ConflictDetectionService::TYPE_DELETED_REMOTE, $this->service->classify(404));
    }//end testClassifyDeletedRemote()

    /**
     * A 403 is a permission-lost conflict.
     *
     * @return void
     */
    public function testClassifyPermissionLost(): void
    {
        $this->assertSame(ConflictDetectionService::TYPE_PERMISSION_LOST, $this->service->classify(403));
    }//end testClassifyPermissionLost()

    /**
     * Non-conflict status codes return null.
     *
     * @return void
     */
    public function testClassifyNonConflict(): void
    {
        $this->assertNull($this->service->classify(200, ['ok' => true]));
        $this->assertNull($this->service->classify(503));
        $this->assertNull($this->service->classify(500));
    }//end testClassifyNonConflict()

    /**
     * Only permission-lost conflicts are non-retryable.
     *
     * @return void
     */
    public function testIsRetryable(): void
    {
        $this->assertTrue($this->service->isRetryable(ConflictDetectionService::TYPE_CONCURRENT_EDIT));
        $this->assertTrue($this->service->isRetryable(ConflictDetectionService::TYPE_DELETED_REMOTE));
        $this->assertFalse($this->service->isRetryable(ConflictDetectionService::TYPE_PERMISSION_LOST));
    }//end testIsRetryable()

    /**
     * A conflict record captures both versions and starts unresolved.
     *
     * @return void
     */
    public function testBuildConflictRecord(): void
    {
        $record = $this->service->buildConflictRecord(
            'sync-1',
            ConflictDetectionService::TYPE_CONCURRENT_EDIT,
            ['answer' => 'goedgekeurd'],
            ['answer' => 'afgekeurd'],
            '2026-05-22T11:00:00+00:00'
        );

        $this->assertSame('sync-1', $record['syncQueueRef']);
        $this->assertSame(['answer' => 'goedgekeurd'], $record['clientVersion']);
        $this->assertSame(['answer' => 'afgekeurd'], $record['serverVersion']);
        $this->assertNull($record['resolution']);
        $this->assertSame('2026-05-22T11:00:00+00:00', $record['detectedAt']);
    }//end testBuildConflictRecord()

    /**
     * The version diff lists only the differing fields.
     *
     * @return void
     */
    public function testDiffVersions(): void
    {
        $client = ['answer' => 'goedgekeurd', 'notes' => 'ok', 'tags' => ['a']];
        $server = ['answer' => 'afgekeurd', 'notes' => 'ok', 'tags' => ['b']];

        $diff = $this->service->diffVersions($client, $server);

        $this->assertArrayHasKey('answer', $diff);
        $this->assertArrayHasKey('tags', $diff);
        $this->assertArrayNotHasKey('notes', $diff);
        $this->assertSame('goedgekeurd', $diff['answer']['client']);
        $this->assertSame('afgekeurd', $diff['answer']['server']);
    }//end testDiffVersions()

    /**
     * A valid resolution updates the record with actor and timestamp.
     *
     * @return void
     */
    public function testApplyResolution(): void
    {
        $record   = ['syncQueueRef' => 'sync-1', 'resolution' => null];
        $resolved = $this->service->applyResolution($record, 'client_wins', 'anja.bakker', '2026-05-22T12:00:00+00:00');

        $this->assertSame('client_wins', $resolved['resolution']);
        $this->assertSame('anja.bakker', $resolved['resolvedBy']);
        $this->assertSame('2026-05-22T12:00:00+00:00', $resolved['resolvedAt']);
    }//end testApplyResolution()

    /**
     * An invalid resolution choice is rejected.
     *
     * @return void
     */
    public function testApplyResolutionRejectsInvalidChoice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->applyResolution(['syncQueueRef' => 'sync-1'], 'nonsense', 'anja.bakker');
    }//end testApplyResolutionRejectsInvalidChoice()
}//end class
