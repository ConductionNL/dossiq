<?php

/**
 * Procest Case Sharing Controller
 *
 * Controller for case share creation/revocation and case transfer workflow.
 *
 * Pure CRUD over the caseShare / partnerOrganization / casetransfer schemas
 * is handled by the OpenRegister manifest renderer; this controller only
 * owns the domain-specific endpoints (token generation, audit logging,
 * transfer accept/reject).
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\CaseCollaborationService;
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\CaseTransferService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for case share token actions and transfer workflow.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseSharingController extends Controller
{
    /**
     * Constructor for the CaseSharingController.
     *
     * @param IRequest                 $request                  The request object
     * @param CaseSharingService       $caseSharingService       The sharing service
     * @param CaseTransferService      $caseTransferService      The transfer service
     * @param CaseCollaborationService $caseCollaborationService The federated activity service
     * @param IUserSession             $userSession              The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private CaseSharingService $caseSharingService,
        private CaseTransferService $caseTransferService,
        private CaseCollaborationService $caseCollaborationService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Create a new case share (token or partner).
     *
     * @NoAdminRequired
     *
     * @return JSONResponse

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function createShare(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $caseId          = $this->request->getParam('caseId');
        $shareType       = $this->request->getParam('shareType', 'token');
        $permissionLevel = $this->request->getParam('permissionLevel', 'bekijken');
        $label           = $this->request->getParam('label', '');

        // C2: Verify the caller has access to this case before allowing share creation.
        if (empty($caseId) === false) {
            if ($this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
                return new JSONResponse(
                    ['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
                    Http::STATUS_FORBIDDEN
                );
            }
        }

        if (empty($caseId) === true) {
            return new JSONResponse(['success' => false, 'error' => 'caseId is required'], 400);
        }

        if ($shareType === 'partner') {
            $partnerId = $this->request->getParam('partnerId');
            if (empty($partnerId) === true) {
                return new JSONResponse(
                    ['success' => false, 'error' => 'partnerId is required for partner shares'],
                    400
                );
            }

            $share = $this->caseSharingService->createPartnerShare(
                $caseId,
                $partnerId,
                $permissionLevel,
                $user->getUID(),
            );
        } else {
            // Public "track your case" token link — minted through the OR
            // shares integration leaf (ADR-022). The leaf owns token
            // generation, expiry and the RBAC-respecting public resolve
            // path; procest no longer stores a token, password or
            // field-exclusion list. The C2 owner/handler guard above is the
            // authz scope for minting a public surface (ADR-005).
            $expiresAt = $this->request->getParam('expiresAt');

            $share = $this->caseSharingService->createTokenShare(
                $caseId,
                $label,
                $user->getUID(),
                $expiresAt,
            );
        }//end if

        if (isset($share['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $share['error']], Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse(['success' => true, 'share' => $share]);
    }//end createShare()

    /**
     * Revoke a case share.
     *
     * @param string $shareId The UUID of the share to revoke
     *
     * @return JSONResponse
     *
     * @NoAdminRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function revokeShare(string $shareId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Public "track your case" token revoke — delegated to the OR shares
        // leaf (ADR-022). A `caseId` param signals the {shareId} addresses a
        // leaf-minted token. IDOR guard (ADR-005, Rule 3): the caller must be
        // an owner/handler of the case AND the token must actually belong to
        // that case (so a handler of case A cannot revoke case B's token by id).
        $tokenCaseId = $this->request->getParam('caseId');
        if (empty($tokenCaseId) === false) {
            if ($this->caseSharingService->canUserAccessCase($tokenCaseId, $user->getUID()) === false
                || $this->caseSharingService->tokenBelongsToCase($shareId, $tokenCaseId) === false
            ) {
                return new JSONResponse(
                    ['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
                    Http::STATUS_FORBIDDEN
                );
            }

            $revoked = $this->caseSharingService->revokeTokenShare($shareId);
            if ($revoked === false) {
                return new JSONResponse(['success' => false, 'error' => 'Could not revoke share link'], Http::STATUS_BAD_GATEWAY);
            }

            return new JSONResponse(['success' => true]);
        }

        // Partner-organisation handover revoke (zaak-domain, in-app object).
        // C2: Resolve the share's caseId, then verify the caller has access to that case.
        $caseId = $this->caseSharingService->getCaseIdForShare($shareId);
        if ($caseId !== null
            && $this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false
        ) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
                Http::STATUS_FORBIDDEN
            );
        }

        $share = $this->caseSharingService->revokeShare($shareId, $user->getUID());
        return new JSONResponse(['success' => true, 'share' => $share]);
    }//end revokeShare()

    /**
     * Initiate a case transfer to another organization.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function initiateTransfer(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $caseId = $this->request->getParam('caseId');
        $sourceOrganization = $this->request->getParam('sourceOrganization', '');
        $targetOrganization = $this->request->getParam('targetOrganization');
        $reason        = $this->request->getParam('reason', '');
        $requestedDate = $this->request->getParam('requestedDate', date('Y-m-d'));

        if (empty($caseId) === true || empty($targetOrganization) === true) {
            return new JSONResponse(
                ['success' => false, 'error' => 'caseId and targetOrganization are required'],
                400
            );
        }

        // C2: Verify the caller has access to this case before allowing a transfer.
        if ($this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
                Http::STATUS_FORBIDDEN
            );
        }

        // Federated (cross-instance) transfer when a remote cloud id is
        // supplied; local-only transfer otherwise.
        $remoteCloudId = $this->request->getParam('remoteCloudId');
        if (empty($remoteCloudId) === true) {
            $remoteCloudId = null;
        }

        $transfer = $this->caseTransferService->initiateTransfer(
            $caseId,
            $sourceOrganization,
            $targetOrganization,
            $reason,
            $requestedDate,
            $user->getUID(),
            $remoteCloudId,
        );

        if (isset($transfer['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $transfer['error']], Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse(['success' => true, 'transfer' => $transfer]);
    }//end initiateTransfer()

    /**
     * Handle a transfer request (accept or reject) — local session path.
     *
     * @param string $transferId The UUID of the transfer request
     *
     * @return JSONResponse
     *
     * @NoAdminRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function handleTransfer(string $transferId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // C2 (pre-existing gap, fixed alongside the federation extension):
        // the caller must have access to the transfer's case before they
        // may accept/reject it — mirrors the guard initiateTransfer()
        // already had. Previously this endpoint had NO authorization check
        // at all: any authenticated user could accept/reject any transfer
        // by UUID.
        $caseId = $this->caseTransferService->getCaseIdForTransfer($transferId);
        if ($caseId !== null && $this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
                Http::STATUS_FORBIDDEN
            );
        }

        $action = $this->request->getParam('action');

        if ($action === 'accept') {
            $result = $this->caseTransferService->acceptTransfer($transferId);
        } else if ($action === 'reject') {
            $reason = $this->request->getParam('reason', '');
            $result = $this->caseTransferService->rejectTransfer($transferId, $reason);
        } else {
            return new JSONResponse(
                ['success' => false, 'error' => 'Action must be accept or reject'],
                400
            );
        }

        if (isset($result['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_CONFLICT);
        }

        return new JSONResponse(['success' => true, 'transfer' => $result]);
    }//end handleTransfer()

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

        if ($action === 'accept') {
            $result = $this->caseTransferService->acceptTransfer($transferId, $verified['sharedWith']);
        } else if ($action === 'reject') {
            $reason = $this->request->getParam('reason', '');
            $result = $this->caseTransferService->rejectTransfer($transferId, $reason, $verified['sharedWith']);
        } else {
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

        $result = $this->caseCollaborationService->postLocalActivity($federatedShareId, $user->getUID(), $message);
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

        $entries = $this->caseCollaborationService->listActivity($federatedShareId);
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

        $result = $this->caseCollaborationService->postRemoteActivity($shareToken, $federatedShareId, $message);
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
        $result = $this->caseCollaborationService->listRemoteActivity($shareToken, $federatedShareId);
        if (isset($result['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(['success' => true, 'entries' => ($result['entries'] ?? [])]);
    }//end listRemoteActivity()
}//end class
