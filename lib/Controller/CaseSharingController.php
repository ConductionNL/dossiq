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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\CaseTransferService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for case share token actions and transfer workflow.
 */
class CaseSharingController extends Controller
{
    /**
     * Constructor for the CaseSharingController.
     *
     * @param IRequest            $request             The request object
     * @param CaseSharingService  $caseSharingService  The sharing service
     * @param CaseTransferService $caseTransferService The transfer service
     * @param IUserSession        $userSession         The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private CaseSharingService $caseSharingService,
        private CaseTransferService $caseTransferService,
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

        $transfer = $this->caseTransferService->initiateTransfer(
            $caseId,
            $sourceOrganization,
            $targetOrganization,
            $reason,
            $requestedDate,
        );

        return new JSONResponse(['success' => true, 'transfer' => $transfer]);
    }//end initiateTransfer()

    /**
     * Handle a transfer request (accept or reject).
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
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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

        return new JSONResponse(['success' => true, 'transfer' => $result]);
    }//end handleTransfer()
}//end class
