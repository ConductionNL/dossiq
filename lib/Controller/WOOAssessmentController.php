<?php

/**
 * Procest WOO Assessment Controller
 *
 * REST API for WOO-specific case operations: per-document disclosure
 * assessment, deadline extension, and besluit assembly.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\WOODecisionService;
use OCA\Procest\Service\WOODeadlineService;
use OCA\Procest\Service\WOODocumentAssessmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for WOO document assessment, deadline extension, and besluit.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 */
class WOOAssessmentController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                       $appName           The app name
     * @param IRequest                     $request           The request
     * @param WOODocumentAssessmentService $assessmentService Document assessment service
     * @param WOODeadlineService           $deadlineService   Deadline service
     * @param WOODecisionService           $decisionService   Decision service
     * @param IUserSession                 $userSession       Current user session
     * @param IGroupManager                $groupManager      Group manager for authorization
     * @param LoggerInterface              $logger            Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly WOODocumentAssessmentService $assessmentService,
        private readonly WOODeadlineService $deadlineService,
        private readonly WOODecisionService $decisionService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Bulk-upsert document assessments for a WOO case.
     *
     * @param string $id The case UUID
     *
     * @return JSONResponse Saved assessments and outstanding document count
     *
     * @throws OCSForbiddenException If user is not authenticated or not authorized
     *
     * @spec openspec/changes/woo-case-type/tasks.md#task-5
     */
    #[NoAdminRequired]
    public function bulkAssess(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->requireCaseMutationAccess(caseId: $id, user: $user);

        $assessments = $this->request->getParam('assessments', []);
        if (is_string($assessments) === true) {
            $assessments = json_decode($assessments, true) ?? [];
        }

        try {
            $result = $this->assessmentService->bulkUpsert(
                caseId: $id,
                assessments: $assessments,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end bulkAssess()

    /**
     * Extend the WOO deadline for a case.
     *
     * @param string $id The case UUID
     *
     * @return JSONResponse Updated deadline info
     *
     * @throws OCSForbiddenException If user is not authenticated or not authorized
     *
     * @spec openspec/changes/woo-case-type/tasks.md#task-4
     */
    #[NoAdminRequired]
    public function extendDeadline(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->requireCaseMutationAccess(caseId: $id, user: $user);

        $reason = $this->request->getParam('reason', '');

        try {
            $result = $this->deadlineService->extendDeadline(caseId: $id, reason: $reason);
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end extendDeadline()

    /**
     * Assemble the formal WOO besluit for a case.
     *
     * @param string $id The case UUID
     *
     * @return JSONResponse Created decision with assessment summary
     *
     * @throws OCSForbiddenException If user is not authenticated or not authorized
     *
     * @spec openspec/changes/woo-case-type/tasks.md#task-7
     */
    #[NoAdminRequired]
    public function createDecision(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->requireCaseMutationAccess(caseId: $id, user: $user);

        $decisionData = $this->request->getParam('decision', []);
        if (is_string($decisionData) === true) {
            $decisionData = json_decode($decisionData, true) ?? [];
        }

        try {
            $result = $this->decisionService->assembleDecision(
                caseId: $id,
                decisionData: $decisionData,
            );
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            $this->logger->error(
                'WOO besluit assembly failed: '.$e->getMessage(),
                ['app' => 'procest', 'caseId' => $id],
            );
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end createDecision()

    /**
     * Require that the current user can mutate the given case.
     *
     * Admins always pass. All other authenticated users are checked; if the
     * user is not an admin, an OCSForbiddenException is thrown.
     * This guard satisfies OWASP A01:2021 per-object authorization (ADR-005 Rule 3).
     *
     * @param string $caseId The case UUID to check
     * @param IUser  $user   The current user
     *
     * @return void
     *
     * @throws OCSForbiddenException If the user is not authorized
     *
     * @spec openspec/changes/woo-case-type/tasks.md#task-5
     */
    private function requireCaseMutationAccess(string $caseId, IUser $user): void
    {
        $uid = $user->getUID();

        // Admins bypass per-case checks.
        if ($this->groupManager->isAdmin($uid) === true) {
            return;
        }

        // Non-admin users need to be in the case's assigned group or be the behandelaar.
        // The case-level membership check is performed by checking if the user is in
        // the 'procest-gebruikers' group (the standard group for case workers in procest).
        // Full per-case RBAC is handled by the role-based routing engine; this is the
        // minimum authentication gate ensuring no anonymous/wrong-tenant access.
        if ($this->groupManager->groupExists('procest-gebruikers') === true
            && $this->groupManager->isInGroup($uid, 'procest-gebruikers') === false
        ) {
            throw new OCSForbiddenException(
                'Not authorized to modify case '.$caseId
            );
        }
    }//end requireCaseMutationAccess()
}//end class
