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
use OCA\Procest\Service\CaseAccessGuard;
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
     * Per-case authorization guard.
     *
     * @var CaseAccessGuard|\PHPUnit\Framework\MockObject\MockObject
     */
    private CaseAccessGuard $caseAccessGuard;


    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->hearingService  = $this->createMock(HearingService::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

        // Default: the session resolves to a case the caller handles.
        // The authorization tests below override both halves.
        $this->hearingService->method('getCaseIdForSession')->willReturn('case-1');
        $this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);

        $this->controller = new BezwaarHearingController(
            appName: 'procest',
            request: $this->request,
            hearingService: $this->hearingService,
            userSession: $this->userSession,
            caseAccessGuard: $this->caseAccessGuard,
        );
    }//end setUp()


    /**
     * Build a controller with an explicit guard answer and session resolution.
     *
     * @param string|null $resolvedCaseId What the session resolves to.
     * @param bool        $mayMutate      Whether the caller handles that case.
     *
     * @return BezwaarHearingController The controller under test.
     */
    private function controllerWith(?string $resolvedCaseId, bool $mayMutate): BezwaarHearingController
    {
        $hearingService = $this->createMock(HearingService::class);
        $hearingService->method('getCaseIdForSession')->willReturn($resolvedCaseId);
        $hearingService->expects($this->never())->method('recordAttendance');

        $guard = $this->createMock(CaseAccessGuard::class);
        $guard->method('hasCaseMutationAccess')->willReturn($mayMutate);

        return new BezwaarHearingController(
            appName: 'procest',
            request: $this->request,
            hearingService: $hearingService,
            userSession: $this->userSession,
            caseAccessGuard: $guard,
        );
    }//end controllerWith()


    /**
     * An authenticated caller who does not handle the parent bezwaar case
     * cannot append attendance, and nothing is written.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testCallerWithoutCaseAccessIsRejected(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('mallory');
        $this->userSession->method('getUser')->willReturn($user);

        $response = $this->controllerWith(resolvedCaseId: 'case-1', mayMutate: false)
            ->recordAttendance(sessionId: 'session-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testCallerWithoutCaseAccessIsRejected()


    /**
     * A session that cannot be resolved to a case denies, so the endpoint is
     * not an existence oracle for hearing-session UUIDs.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testUnresolvableSessionIsRejected(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('behandelaar');
        $this->userSession->method('getUser')->willReturn($user);

        $response = $this->controllerWith(resolvedCaseId: null, mayMutate: true)
            ->recordAttendance(sessionId: 'does-not-exist');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testUnresolvableSessionIsRejected()


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
