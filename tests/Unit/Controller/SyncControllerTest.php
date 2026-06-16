<?php

/**
 * SyncController Unit Tests.
 *
 * Covers the offline-sync endpoints' security and outcome behaviour: the
 * per-user / per-device IDOR ownership guard (an inspector may only record
 * outcomes / resolve conflicts on operations in THEIR OWN queue), the
 * server-side conflict classification (409 → conflict record, 403 →
 * permission_lost terminal), and the last-write / merge resolution policy
 * (client_wins + manual_merge re-queue, server_wins discards).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\SyncController;
use OCA\Procest\Service\ConflictDetectionService;
use OCA\Procest\Service\DailySyncService;
use OCA\Procest\Service\SyncQueueReplayService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the SyncController class.
 *
 * @covers \OCA\Procest\Controller\SyncController
 */
class SyncControllerTest extends TestCase
{
    /**
     * Build a SyncController with a request param map + service mocks.
     *
     * The ConflictDetectionService is a REAL instance so the classify/diff
     * policy runs for real; the replay + daily services are mocked because
     * they need OpenRegister.
     *
     * @param array<string,mixed>          $params  Request param map.
     * @param SyncQueueReplayService|null  $replay  Optional replay override.
     * @param string|null                  $uid     Authenticated UID, or null.
     *
     * @return SyncController The controller under test.
     */
    private function makeController(
        array $params,
        ?SyncQueueReplayService $replay=null,
        ?string $uid='inspector-anja'
    ): SyncController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) use ($params) {
                return array_key_exists($key, $params) ? $params[$key] : ($default ?? '');
            }
        );

        $session = $this->createMock(IUserSession::class);
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        } else {
            $session->method('getUser')->willReturn(null);
        }

        return new SyncController(
            'procest',
            $request,
            $this->createMock(DailySyncService::class),
            $replay ?? $this->createMock(SyncQueueReplayService::class),
            new ConflictDetectionService(new NullLogger()),
            $session,
            new NullLogger(),
        );
    }//end makeController()

    /**
     * An unauthenticated request is rejected with 401.
     *
     * @return void
     */
    public function testRecordOutcomeRejectsUnauthenticated(): void
    {
        $controller = $this->makeController(['deviceId' => 'd1', 'statusCode' => 200], null, null);
        $response   = $controller->recordOutcome('op-1');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testRecordOutcomeRejectsUnauthenticated()

    /**
     * A missing deviceId is a 400 bad request.
     *
     * @return void
     */
    public function testRecordOutcomeRequiresDeviceId(): void
    {
        $controller = $this->makeController(['statusCode' => 200]);
        $response   = $controller->recordOutcome('op-1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testRecordOutcomeRequiresDeviceId()

    /**
     * IDOR guard: an operation not in the inspector's own queue is 404, and
     * recordOutcome is NEVER called for it.
     *
     * @return void
     */
    public function testRecordOutcomeIdorGuardOnForeignOperation(): void
    {
        $replay = $this->createMock(SyncQueueReplayService::class);
        // The inspector's own queue contains only op-own; the foreign op-other
        // is invisible to this user.
        $replay->method('listPending')->willReturn([
            ['id' => 'op-own', 'payload' => [], 'status' => 'pending'],
        ]);
        $replay->expects($this->never())->method('recordOutcome');

        $controller = $this->makeController(['deviceId' => 'd1', 'statusCode' => 200], $replay);
        $response   = $controller->recordOutcome('op-other');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testRecordOutcomeIdorGuardOnForeignOperation()

    /**
     * A 2xx outcome on an owned operation is recorded as success.
     *
     * @return void
     */
    public function testRecordOutcomeSuccessOnOwnedOperation(): void
    {
        $replay = $this->createMock(SyncQueueReplayService::class);
        $replay->method('listPending')->willReturn([
            ['id' => 'op-own', 'payload' => ['answer' => 'ja'], 'status' => 'pending'],
        ]);
        $replay->expects($this->once())
            ->method('recordOutcome')
            ->with($this->anything(), 'success', $this->isNull())
            ->willReturn(['id' => 'op-own', 'status' => 'synced']);

        $controller = $this->makeController(['deviceId' => 'd1', 'statusCode' => 201], $replay);
        $response   = $controller->recordOutcome('op-own');
        $data       = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('synced', $data['operation']['status']);
        $this->assertArrayNotHasKey('conflict', $data);
    }//end testRecordOutcomeSuccessOnOwnedOperation()

    /**
     * A 409 outcome classifies as a concurrent_edit conflict and a
     * ConflictRecord is returned with both versions.
     *
     * @return void
     */
    public function testRecordOutcomeDetectsConcurrentEditConflict(): void
    {
        $serverObject = ['status' => 'afgekeurd'];
        $clientPayload = ['status' => 'goedgekeurd'];

        $replay = $this->createMock(SyncQueueReplayService::class);
        $replay->method('listPending')->willReturn([
            ['id' => 'op-own', 'payload' => $clientPayload, 'status' => 'pending'],
        ]);
        $replay->method('recordOutcome')->willReturn(['id' => 'op-own', 'status' => 'conflict']);

        $controller = $this->makeController(
            ['deviceId' => 'd1', 'statusCode' => 409, 'serverObject' => $serverObject],
            $replay
        );
        $response = $controller->recordOutcome('op-own');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertArrayHasKey('conflict', $data);
        $this->assertSame('concurrent_edit', $data['conflict']['conflictType']);
        $this->assertSame($clientPayload, $data['conflict']['clientVersion']);
        $this->assertSame($serverObject, $data['conflict']['serverVersion']);
    }//end testRecordOutcomeDetectsConcurrentEditConflict()

    /**
     * A 403 outcome classifies as permission_lost and the operation is moved
     * to a terminal (permission_lost) outcome, never retried.
     *
     * @return void
     */
    public function testRecordOutcomePermissionLostIsTerminal(): void
    {
        $replay = $this->createMock(SyncQueueReplayService::class);
        $replay->method('listPending')->willReturn([
            ['id' => 'op-own', 'payload' => [], 'status' => 'pending'],
        ]);
        $replay->expects($this->once())
            ->method('recordOutcome')
            ->with($this->anything(), 'permission_lost', $this->anything())
            ->willReturn(['id' => 'op-own', 'status' => 'failed']);

        $controller = $this->makeController(['deviceId' => 'd1', 'statusCode' => 403], $replay);
        $response   = $controller->recordOutcome('op-own');
        $data       = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('permission_lost', $data['conflict']['conflictType']);
    }//end testRecordOutcomePermissionLostIsTerminal()

    /**
     * client_wins resolution re-queues the operation for a forced retry.
     *
     * @return void
     */
    public function testResolveConflictClientWinsRequeues(): void
    {
        $replay = $this->createMock(SyncQueueReplayService::class);
        $replay->method('listPending')->willReturn([
            ['id' => 'op-own', 'payload' => ['x' => 1], 'status' => 'conflict'],
        ]);
        $replay->expects($this->once())
            ->method('recordOutcome')
            ->with($this->anything(), 'transient_error', $this->anything())
            ->willReturn(['id' => 'op-own', 'status' => 'pending']);

        $controller = $this->makeController(
            ['deviceId' => 'd1', 'resolution' => 'client_wins'],
            $replay
        );
        $response = $controller->resolveConflict('op-own');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('pending', $response->getData()['operation']['status']);
    }//end testResolveConflictClientWinsRequeues()

    /**
     * server_wins resolution discards the local change and marks it synced.
     *
     * @return void
     */
    public function testResolveConflictServerWinsDiscards(): void
    {
        $replay = $this->createMock(SyncQueueReplayService::class);
        $replay->method('listPending')->willReturn([
            ['id' => 'op-own', 'payload' => ['x' => 1], 'status' => 'conflict'],
        ]);
        $replay->expects($this->once())
            ->method('recordOutcome')
            ->with($this->anything(), 'success', $this->isNull())
            ->willReturn(['id' => 'op-own', 'status' => 'synced']);

        $controller = $this->makeController(
            ['deviceId' => 'd1', 'resolution' => 'server_wins'],
            $replay
        );
        $response = $controller->resolveConflict('op-own');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('synced', $response->getData()['operation']['status']);
    }//end testResolveConflictServerWinsDiscards()

    /**
     * An invalid resolution choice is rejected with 400.
     *
     * @return void
     */
    public function testResolveConflictRejectsInvalidChoice(): void
    {
        $replay = $this->createMock(SyncQueueReplayService::class);
        $replay->method('listPending')->willReturn([
            ['id' => 'op-own', 'payload' => [], 'status' => 'conflict'],
        ]);

        $controller = $this->makeController(
            ['deviceId' => 'd1', 'resolution' => 'bogus'],
            $replay
        );
        $response = $controller->resolveConflict('op-own');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testResolveConflictRejectsInvalidChoice()

    /**
     * IDOR guard on conflict resolution: a foreign operation is 404.
     *
     * @return void
     */
    public function testResolveConflictIdorGuard(): void
    {
        $replay = $this->createMock(SyncQueueReplayService::class);
        $replay->method('listPending')->willReturn([
            ['id' => 'op-own', 'payload' => [], 'status' => 'conflict'],
        ]);
        $replay->expects($this->never())->method('recordOutcome');

        $controller = $this->makeController(
            ['deviceId' => 'd1', 'resolution' => 'client_wins'],
            $replay
        );
        $response = $controller->resolveConflict('op-foreign');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testResolveConflictIdorGuard()
}//end class
