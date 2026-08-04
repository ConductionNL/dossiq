<?php

/**
 * Procest SubstitutionController.
 *
 * REST API for vervanging/waarneming (handler substitution). All endpoints are
 * #[NoAdminRequired] with explicit per-method guards: a handler may only set
 * up/revoke substitution for themselves; a coordinator (NC admin) may act for
 * anyone. Guards fail closed — an unauthenticated or unauthorised caller is
 * denied before any data work runs (ADR-005 Rule 3).
 *
 * The authorization rules and the system-context reads they need are delegated
 * to {@see SubstitutionAccessGuard} (ADR-022); coordinator bulk reassignment
 * lives on {@see CaseReassignmentController}.
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
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Substitution\SubstitutionAccessGuard;
use OCA\Procest\Service\SubstitutionAuditService;
use OCA\Procest\Service\SubstitutionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for substitution endpoints.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutionController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                   $appName             The app name.
     * @param IRequest                 $request             The request.
     * @param SubstitutionService      $substitutionService Substitution domain logic.
     * @param SubstitutionAuditService $auditService        Capacity audit.
     * @param SubstitutionAccessGuard  $accessGuard         Authorization + lookups.
     * @param LoggerInterface          $logger              The logger.
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SubstitutionService $substitutionService,
        private readonly SubstitutionAuditService $auditService,
        private readonly SubstitutionAccessGuard $accessGuard,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List substitutions visible to the current user.
     *
     * Coordinators see all; a regular user sees only substitutions where they
     * are the absentee or the substitute.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $userId = $this->accessGuard->currentUid();
        if ($userId === '') {
            return $this->accessGuard->forbidden(message: 'Not authenticated');
        }

        return new JSONResponse(['results' => $this->accessGuard->listVisibleTo(userId: $userId)]);
    }//end index()

    /**
     * Create a substitution.
     *
     * A regular user may only register a substitution where they are the
     * absentee. A coordinator may register on behalf of anyone.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $actorId = $this->accessGuard->currentUid();
        if ($actorId === '') {
            return $this->accessGuard->forbidden(message: 'Not authenticated');
        }

        $absentee = (string) $this->request->getParam('absentee', $actorId);

        // Per-object guard: own absence, or coordinator acting for another.
        if ($absentee !== $actorId && $this->accessGuard->isCoordinator(userId: $actorId) === false) {
            return $this->accessGuard->forbidden(message: 'You may only register a substitution for yourself');
        }

        try {
            $created = $this->substitutionService->create(
                absentee: $absentee,
                substitute: (string) $this->request->getParam('substitute', ''),
                startDate: (string) $this->request->getParam('startDate', ''),
                endDate: (string) $this->request->getParam('endDate', ''),
                scope: (string) $this->request->getParam('scope', 'all'),
                scopeRefs: (array) $this->request->getParam('scopeRefs', []),
                reason: (string) $this->request->getParam('reason', 'verlof'),
                createdBy: $actorId,
                comment: (string) $this->request->getParam('comment', '')
            );
            return new JSONResponse($created, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Substitution create failed', ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end create()

    /**
     * Revoke a substitution.
     *
     * Allowed for the absentee, the original creator, or a coordinator.
     *
     * @param string $id The substitution UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    #[NoAdminRequired]
    public function revoke(string $id): JSONResponse
    {
        $actorId = $this->accessGuard->currentUid();
        if ($actorId === '') {
            return $this->accessGuard->forbidden(message: 'Not authenticated');
        }

        $row = $this->accessGuard->find(id: $id);
        if ($row === null) {
            return new JSONResponse(['error' => 'Substitution not found'], Http::STATUS_NOT_FOUND);
        }

        if ($this->accessGuard->mayManage(row: $row, userId: $actorId) === false) {
            return $this->accessGuard->forbidden(message: 'You may only revoke your own substitution');
        }

        $updated = $this->substitutionService->revoke($id);
        return new JSONResponse($updated ?? ['status' => 'revoked']);
    }//end revoke()

    /**
     * The substituted work routed to the current user (My Work integration).
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    #[NoAdminRequired]
    public function substitutedWork(): JSONResponse
    {
        $userId = $this->accessGuard->currentUid();
        if ($userId === '') {
            return $this->accessGuard->forbidden(message: 'Not authenticated');
        }

        // Resolution runs in the calling user's RBAC context, so items the
        // substitute cannot read are already excluded.
        $work = $this->substitutionService->getSubstitutedWorkFor(userId: $userId);
        return new JSONResponse($work);
    }//end substitutedWork()

    /**
     * Capacity-stamped action list for a substitution.
     *
     * Visible to the absentee, substitute, creator, or a coordinator.
     *
     * @param string $id The substitution UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    #[NoAdminRequired]
    public function actions(string $id): JSONResponse
    {
        $actorId = $this->accessGuard->currentUid();
        if ($actorId === '') {
            return $this->accessGuard->forbidden(message: 'Not authenticated');
        }

        $row = $this->accessGuard->find(id: $id);
        if ($row === null) {
            return new JSONResponse(['error' => 'Substitution not found'], Http::STATUS_NOT_FOUND);
        }

        if ($this->accessGuard->mayView(row: $row, userId: $actorId) === false) {
            return $this->accessGuard->forbidden();
        }

        return new JSONResponse(['results' => $this->auditService->getActionsForSubstitution($id)]);
    }//end actions()
}//end class
