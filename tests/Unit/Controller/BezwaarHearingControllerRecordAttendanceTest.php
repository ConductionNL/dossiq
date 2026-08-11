<?php

/**
 * BezwaarHearingController::recordAttendance() Unit Tests
 *
 * Covers the endpoint that exposes
 * Bezwaar\HearingService::recordAttendance() — the authentication gate, the
 * entries passthrough, the empty/malformed body path, and RuntimeException
 * mapping to 400 (a late correction without a correctionReason).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\BezwaarHearingController;
use OCA\Procest\Service\Bezwaar\HearingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for BezwaarHearingController::recordAttendance().
 *
 * @covers \OCA\Procest\Controller\BezwaarHearingController
 */
final class BezwaarHearingControllerRecordAttendanceTest extends TestCase
{

    /**
     * Inbound request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * Bezwaar hearing domain service.
     *
     * @var HearingService|\PHPUnit\Framework\MockObject\MockObject
     */
    private HearingService $hearingService;

    /**
     * Current user session.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * The controller under test.
     *
     * @var BezwaarHearingController
     */
    private BezwaarHearingController $controller;


    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request        = $this->createMock(IRequest::class);
        $this->hearingService = $this->createMock(HearingService::class);
        $this->userSession    = $this->createMock(IUserSession::class);

        $this->controller = new BezwaarHearingController(
            appName: 'procest',
            request: $this->request,
            hearingService: $this->hearingService,
            userSession: $this->userSession,
        );
    }//end setUp()


    /**
     * Mark the session as authenticated.
     *
     * @return void
     */
    private function authenticate(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('behandelaar');
        $this->userSession->method('getUser')->willReturn($user);
    }//end authenticate()


    /**
     * An anonymous caller is rejected before the service is consulted.
     *
     * @return void
     */
    public function testAnonymousCallerIsRejectedAndServiceNeverRuns(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->hearingService->expects($this->never())->method('recordAttendance');

        $response = $this->controller->recordAttendance(sessionId: 'sess-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['error' => 'Not authenticated'], $response->getData());
    }//end testAnonymousCallerIsRejectedAndServiceNeverRuns()


    /**
     * The entries reach the service and the updated session is returned.
     *
     * @return void
     */
    public function testAppendsAttendanceEntriesAndReturnsUpdatedSession(): void
    {
        $this->authenticate();

        $entries = [
            ['invitee' => 'bezwaarmaker', 'present' => true],
            ['invitee' => 'gemachtigde', 'present' => false],
        ];
        $this->request->method('getParam')->with('entries')->willReturn($entries);

        $this->hearingService->expects($this->once())
            ->method('recordAttendance')
            ->with('sess-1', $entries)
            ->willReturn(['id' => 'sess-1', 'attendance' => $entries]);

        $response = $this->controller->recordAttendance(sessionId: 'sess-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['id' => 'sess-1', 'attendance' => $entries], $response->getData());
    }//end testAppendsAttendanceEntriesAndReturnsUpdatedSession()


    /**
     * A missing or non-array body reaches the service as an empty list, which
     * the service rejects — the endpoint never invents an entry.
     *
     * @return void
     */
    public function testMalformedBodyBecomesAnEmptyEntryList(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->with('entries')->willReturn('not-an-array');

        $this->hearingService->expects($this->once())
            ->method('recordAttendance')
            ->with('sess-1', [])
            ->willThrowException(new RuntimeException('At least one attendance entry is required'));

        $response = $this->controller->recordAttendance(sessionId: 'sess-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(
            ['error' => 'At least one attendance entry is required'],
            $response->getData()
        );
    }//end testMalformedBodyBecomesAnEmptyEntryList()


    /**
     * A rejected late correction becomes a 400, not a 500.
     *
     * @return void
     */
    public function testRuntimeExceptionBecomesBadRequest(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->with('entries')
            ->willReturn([['invitee' => 'bezwaarmaker', 'present' => true]]);

        $this->hearingService->method('recordAttendance')
            ->willThrowException(new RuntimeException('Hearing session not found'));

        $response = $this->controller->recordAttendance(sessionId: 'nope');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'Hearing session not found'], $response->getData());
    }//end testRuntimeExceptionBecomesBadRequest()
}//end class
