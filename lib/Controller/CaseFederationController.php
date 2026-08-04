<?php

/**
 * Procest Case Federation Controller
 *
 * Controller for cross-instance (federated) case collaboration: field-scoped
 * federated shares, their revocation, the shared activity stream, and remote
 * transfer accept/reject.
 *
 * Split out of CaseSharingController along the federation seam. That
 * controller keeps the single-instance surface — local token/partner shares
 * and local transfers — where the caller is always a local session. Every
 * endpoint here instead involves a remote Nextcloud instance, either as the
 * subject of the share or, on the `#[PublicPage]` paths, as the caller
 * authenticated by an OpenRegister federated-share bearer token rather than a
 * session.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\CaseCollaborationService;
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\CaseTransferService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for federated case shares and the shared activity stream.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseFederationController extends Controller
{
    /**
     * Constructor for the CaseFederationController.
     *
     * @param IRequest                 $request             The request object
     * @param CaseSharingService       $caseSharingService  The sharing service
     * @param CaseTransferService      $caseTransferService The transfer service
     * @param CaseCollaborationService $collabService       The federated activity service
     * @param IUserSession             $userSession         The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private CaseSharingService $caseSharingService,
        private CaseTransferService $caseTransferService,
        private CaseCollaborationService $collabService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Create a federated case share: a field-scoped snapshot shared with a
     * remote org over OpenRegister's OCM federation leaf.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse

     * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
     */
    public function createFederatedShare(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $caseId          = $this->request->getParam('caseId');
        $remoteCloudId   = $this->request->getParam('remoteCloudId');
        $sharedFields    = (array) $this->request->getParam('sharedFields', []);
        $sharedDocuments = (array) $this->request->getParam('sharedDocuments', []);
        $permissionLevel = $this->request->getParam('permissionLevel', 'bekijken');

        if (empty($caseId) === true || empty($remoteCloudId) === true) {
            return new JSONResponse(['success' => false, 'error' => 'caseId and remoteCloudId are required'], 400);
        }

        // C2: same case-access guard as the partner/token share paths.
        if ($this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
                Http::STATUS_FORBIDDEN
            );
        }

        $share = $this->caseSharingService->createFederatedShare(
            $caseId,
            $remoteCloudId,
            $sharedFields,
            $sharedDocuments,
            $permissionLevel,
            $user->getUID(),
        );

        if (isset($share['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $share['error']], Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse(['success' => true, 'share' => $share]);
    }//end createFederatedShare()

    /**
     * Revoke a federated case share.
     *
     * @param string $shareId The UUID of the federated share to revoke
     *
     * @return JSONResponse
     *
     * @NoAdminRequired

     * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
     */
    public function revokeFederatedShare(string $shareId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $caseId = $this->caseSharingService->getCaseIdForFederatedShare($shareId);
        if ($caseId !== null && $this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
                Http::STATUS_FORBIDDEN
            );
        }

        $result = $this->caseSharingService->revokeFederatedShare($shareId, $user->getUID());
        if (isset($result['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse(['success' => true, 'share' => $result]);
    }//end revokeFederatedShare()

    /**
     * Remote (cross-instance) transfer accept/reject, authenticated via the
     * transfer-scoped OR federated share bearer token — NOT a local
     * session. Public by design: the caller is another Nextcloud instance.
     *
     * @param string $shareToken The transfer-scoped bearer token
     * @param string $transferId The transfer UUID
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md#a-remote-org-accepts-a-transfer-addressed-to-it-via-its-scoped-token
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function handleFederatedTransfer(string $shareToken, string $transferId): JSONResponse
    {
        $verified = $this->caseTransferService->resolveFederatedTransferShare($shareToken, $transferId);
        if ($verified === null) {
            return new JSONResponse(['success' => false, 'error' => 'Invalid or unauthorized transfer token'], Http::STATUS_FORBIDDEN);
        }

        $action = $this->request->getParam('action');

        $result = match ($action) {
            'accept' => $this->caseTransferService->acceptTransfer($transferId, $verified['sharedWith']),
            'reject' => $this->caseTransferService->rejectTransfer(
                $transferId,
                $this->request->getParam('reason', ''),
                $verified['sharedWith']
            ),
            default => null,
        };

        if ($result === null) {
            return new JSONResponse(['success' => false, 'error' => 'Action must be accept or reject'], 400);
        }

        if (isset($result['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_CONFLICT);
        }

        return new JSONResponse(['success' => true, 'transfer' => $result]);
    }//end handleFederatedTransfer()

    /**
     * Post a local activity entry on a federated case share's collaboration
     * stream.
     *
     * @param string $federatedShareId The caseFederatedShare UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired

     * @spec openspec/specs/federated-case-collaboration/spec.md#a-local-handler-posts-an-activity-entry
     */
    public function postActivity(string $federatedShareId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $caseId = $this->caseSharingService->getCaseIdForFederatedShare($federatedShareId);
        if ($caseId !== null && $this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
                Http::STATUS_FORBIDDEN
            );
        }

        $message = (string) $this->request->getParam('message', '');
        if ($message === '') {
            return new JSONResponse(['success' => false, 'error' => 'message is required'], 400);
        }

        $result = $this->collabService->postLocalActivity($federatedShareId, $user->getUID(), $message);
        if (isset($result['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse(['success' => true, 'activity' => $result]);
    }//end postActivity()

    /**
     * List the local view of a federated case share's activity stream.
     *
     * @param string $federatedShareId The caseFederatedShare UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
     */
    public function listActivity(string $federatedShareId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $caseId = $this->caseSharingService->getCaseIdForFederatedShare($federatedShareId);
        if ($caseId !== null && $this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
                Http::STATUS_FORBIDDEN
            );
        }

        $entries = $this->collabService->listActivity($federatedShareId);
        return new JSONResponse(['success' => true, 'entries' => $entries]);
    }//end listActivity()

    /**
     * Post a remote activity entry, authenticated via the federated share's
     * scoped bearer token. Public by design: the caller is another
     * Nextcloud instance.
     *
     * @param string $shareToken       The scoped bearer token
     * @param string $federatedShareId The caseFederatedShare UUID
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md#a-remote-org-posts-an-activity-entry-via-its-scoped-token
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function postRemoteActivity(string $shareToken, string $federatedShareId): JSONResponse
    {
        $message = (string) $this->request->getParam('message', '');
        if ($message === '') {
            return new JSONResponse(['success' => false, 'error' => 'message is required'], 400);
        }

        $result = $this->collabService->postRemoteActivity($shareToken, $federatedShareId, $message);
        if (isset($result['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(['success' => true, 'activity' => $result]);
    }//end postRemoteActivity()

    /**
     * List a federated case share's activity stream via a remote bearer
     * token. Public by design: the caller is another Nextcloud instance.
     *
     * @param string $shareToken       The scoped bearer token
     * @param string $federatedShareId The caseFederatedShare UUID
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function listRemoteActivity(string $shareToken, string $federatedShareId): JSONResponse
    {
        $result = $this->collabService->listRemoteActivity($shareToken, $federatedShareId);
        if (isset($result['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(['success' => true, 'entries' => ($result['entries'] ?? [])]);
    }//end listRemoteActivity()
}//end class
