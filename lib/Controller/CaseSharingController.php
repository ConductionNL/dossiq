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
 * Scope is the single-instance surface: every caller here is a local
 * session. The cross-instance surface — federated shares, the shared
 * activity stream and remote transfer accept/reject — lives on
 * {@see CaseFederationController}.
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
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\CaseTransferService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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

            $partnerShare = $this->caseSharingService->createPartnerShare(
                $caseId,
                $partnerId,
                $permissionLevel,
                $user->getUID(),
            );

            if (isset($partnerShare['error']) === true) {
                return new JSONResponse(
                    ['success' => false, 'error' => $partnerShare['error']],
                    Http::STATUS_BAD_GATEWAY
                );
            }

            return new JSONResponse(['success' => true, 'share' => $partnerShare]);
        }//end if

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

        $result = match ($action) {
            'accept' => $this->caseTransferService->acceptTransfer($transferId),
            'reject' => $this->caseTransferService->rejectTransfer(
                $transferId,
                $this->request->getParam('reason', '')
            ),
            default => null,
        };

        if ($result === null) {
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
}//end class
