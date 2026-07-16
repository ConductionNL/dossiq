<?php

/**
 * CaseSharingController Federation Unit Tests
 *
 * Covers the federated-case-collaboration controller surface: local
 * (session) endpoints enforce the case-access guard — including the
 * pre-existing `handleTransfer` gap fix (it previously had NO
 * authorization check at all) — and the `#[PublicPage]` remote endpoints
 * are gated exclusively by the resolved federated-share token, never a
 * local session.
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
 * @spec openspec/specs/federated-case-collaboration/spec.md#local-transfer-acceptreject-requires-case-access-pre-existing-gap-fix
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\CaseSharingController;
use OCA\Procest\Service\CaseCollaborationService;
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\CaseTransferService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Controller\CaseSharingController
 */
class CaseSharingControllerFederationTest extends TestCase
{
    private IRequest $request;

    private CaseSharingService $sharingService;

    private CaseTransferService $transferService;

    private CaseCollaborationService $collaborationService;

    private IUserSession $userSession;

    private CaseSharingController $controller;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->request              = $this->createMock(IRequest::class);
        $this->sharingService        = $this->createMock(CaseSharingService::class);
        $this->transferService       = $this->createMock(CaseTransferService::class);
        $this->collaborationService  = $this->createMock(CaseCollaborationService::class);
        $this->userSession           = $this->createMock(IUserSession::class);

        $this->controller = new CaseSharingController(
            request: $this->request,
            caseSharingService: $this->sharingService,
            caseTransferService: $this->transferService,
            caseCollaborationService: $this->collaborationService,
            userSession: $this->userSession,
        );
    }//end setUp()

    /**
     * @return void
     */
    private function authenticate(string $uid='alice'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end authenticate()

    /**
     * Pre-existing gap fix: handleTransfer previously had NO authorization
     * check at all — any authenticated user could accept/reject any
     * transfer by UUID. It now requires case access, mirroring
     * initiateTransfer()'s guard.
     *
     * @return void
     */
    public function testHandleTransferDeniesAUserWithoutCaseAccess(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnCallback(static fn (string $k, $d=null) => $k === 'action' ? 'accept' : $d);

        $this->transferService->method('getCaseIdForTransfer')->with('transfer-1')->willReturn('case-1');
        $this->sharingService->method('canUserAccessCase')->with('case-1', 'alice')->willReturn(false);

        $this->transferService->expects(self::never())->method('acceptTransfer');

        $response = $this->controller->handleTransfer('transfer-1');

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testHandleTransferDeniesAUserWithoutCaseAccess()

    /**
     * @return void
     */
    public function testHandleTransferAllowsAUserWithCaseAccess(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnCallback(static fn (string $k, $d=null) => $k === 'action' ? 'accept' : $d);

        $this->transferService->method('getCaseIdForTransfer')->willReturn('case-1');
        $this->sharingService->method('canUserAccessCase')->willReturn(true);
        $this->transferService->expects(self::once())->method('acceptTransfer')->with('transfer-1')->willReturn(['status' => 'accepted']);

        $response = $this->controller->handleTransfer('transfer-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testHandleTransferAllowsAUserWithCaseAccess()

    /**
     * @return void
     */
    public function testCreateFederatedShareDeniesAUserWithoutCaseAccess(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnCallback(
            static function (string $k, $d=null) {
                return match ($k) {
                    'caseId'       => 'case-1',
                    'remoteCloudId' => 'partner@remote.example',
                    'sharedFields'  => ['title'],
                    default        => $d,
                };
            }
        );

        $this->sharingService->method('canUserAccessCase')->willReturn(false);
        $this->sharingService->expects(self::never())->method('createFederatedShare');

        $response = $this->controller->createFederatedShare();

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testCreateFederatedShareDeniesAUserWithoutCaseAccess()

    /**
     * @return void
     */
    public function testCreateFederatedShareRequiresCaseIdAndRemoteCloudId(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnCallback(static fn (string $k, $d=null) => $d);

        $response = $this->controller->createFederatedShare();

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testCreateFederatedShareRequiresCaseIdAndRemoteCloudId()

    /**
     * The remote transfer-accept endpoint is authenticated exclusively via
     * the resolved federated-share token — an invalid/unresolvable token is
     * rejected before the transfer service is ever touched, regardless of
     * whether a local session exists.
     *
     * @return void
     */
    public function testHandleFederatedTransferRejectsAnInvalidToken(): void
    {
        $this->transferService->method('resolveFederatedTransferShare')->willReturn(null);
        $this->transferService->expects(self::never())->method('acceptTransfer');
        $this->transferService->expects(self::never())->method('rejectTransfer');

        $this->request->method('getParam')->willReturn('accept');

        $response = $this->controller->handleFederatedTransfer('bad-token', 'transfer-1');

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testHandleFederatedTransferRejectsAnInvalidToken()

    /**
     * @return void
     */
    public function testHandleFederatedTransferAcceptsWithAValidToken(): void
    {
        $this->transferService->method('resolveFederatedTransferShare')
            ->with('good-token', 'transfer-1')
            ->willReturn(['sharedWith' => 'partner@remote.example', 'organisation' => null]);

        $this->request->method('getParam')->willReturnCallback(static fn (string $k, $d=null) => $k === 'action' ? 'accept' : $d);

        $this->transferService->expects(self::once())
            ->method('acceptTransfer')
            ->with('transfer-1', 'partner@remote.example')
            ->willReturn(['status' => 'accepted']);

        $response = $this->controller->handleFederatedTransfer('good-token', 'transfer-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testHandleFederatedTransferAcceptsWithAValidToken()

    /**
     * A read-only case-share token resolves to null (permissions guard is
     * enforced inside resolveFederatedTransferShare — this test proves the
     * controller correctly refuses to act when that resolution fails).
     *
     * @return void
     */
    public function testHandleFederatedTransferRejectsWhenTokenCannotBeResolved(): void
    {
        $this->transferService->method('resolveFederatedTransferShare')->willReturn(null);

        $response = $this->controller->handleFederatedTransfer('read-only-case-share-token', 'transfer-1');

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testHandleFederatedTransferRejectsWhenTokenCannotBeResolved()

    /**
     * @return void
     */
    public function testPostActivityRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->postActivity('share-1');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testPostActivityRequiresAuthentication()

    /**
     * @return void
     */
    public function testPostRemoteActivityIsReachableWithoutALocalSession(): void
    {
        // No authenticate() call — this endpoint is #[PublicPage].
        $this->request->method('getParam')->willReturnCallback(static fn (string $k, $d=null) => $k === 'message' ? 'hello' : $d);
        $this->collaborationService->method('postRemoteActivity')->willReturn(['entries' => [['message' => 'hello']]]);

        $response = $this->controller->postRemoteActivity('tok', 'share-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testPostRemoteActivityIsReachableWithoutALocalSession()
}//end class
