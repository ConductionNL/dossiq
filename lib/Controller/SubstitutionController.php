<?php

/**
 * Procest SubstitutionController.
 *
 * REST API for vervanging/waarneming (handler substitution) and coordinator
 * bulk reassignment. All endpoints are #[NoAdminRequired] with explicit
 * per-method guards: a handler may only set up/revoke substitution for
 * themselves; a coordinator (NC admin) may act for anyone and is the only role
 * allowed to bulk-reassign. Guards fail closed — an unauthenticated or
 * unauthorised caller is denied before any data work runs (ADR-005 Rule 3).
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

use OCA\Procest\Service\CaseReassignmentService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\SubstitutionAuditService;
use OCA\Procest\Service\SubstitutionService;
use OCA\Procest\Service\Support\SearchesObjects;
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
 * Controller for substitution + bulk reassignment endpoints.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutionController extends Controller
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param string                   $appName                  The app name.
     * @param IRequest                 $request                  The request.
     * @param SubstitutionService      $substitutionService      Substitution domain logic.
     * @param SubstitutionAuditService $auditService             Capacity audit.
     * @param CaseReassignmentService  $reassignmentService      Bulk reassignment.
     * @param SettingsService          $settingsService          Settings/config bridge.
     * @param IUserSession             $userSession              The user session.
     * @param IGroupManager            $groupManager             Group manager (admin checks).
     * @param LoggerInterface          $logger                   The logger.
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SubstitutionService $substitutionService,
        private readonly SubstitutionAuditService $auditService,
        private readonly CaseReassignmentService $reassignmentService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
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
        try {
            $user = $this->requireUser();
        } catch (OCSForbiddenException $e) {
            return $this->forbidden($e->getMessage());
        }

        $userId = $user->getUID();
        $rows   = $this->allSubstitutions();
        if ($this->isCoordinator($userId) === false) {
            $rows = array_values(
                array_filter(
                    $rows,
                    static function (array $row) use ($userId): bool {
                        return (string) ($row['absentee'] ?? '') === $userId
                            || (string) ($row['substitute'] ?? '') === $userId;
                    }
                )
            );
        }

        return new JSONResponse(['results' => $rows]);
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
        try {
            $actorId  = $this->requireUser()->getUID();
            $absentee = (string) $this->request->getParam('absentee', $actorId);

            // Per-object guard: own absence, or coordinator acting for another.
            if ($absentee !== $actorId && $this->isCoordinator($actorId) === false) {
                throw new OCSForbiddenException('You may only register a substitution for yourself');
            }
        } catch (OCSForbiddenException $e) {
            return $this->forbidden($e->getMessage());
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
        try {
            $actorId = $this->requireUser()->getUID();
        } catch (OCSForbiddenException $e) {
            return $this->forbidden($e->getMessage());
        }

        $row = $this->findSubstitution($id);
        if ($row === null) {
            return new JSONResponse(['error' => 'Substitution not found'], Http::STATUS_NOT_FOUND);
        }

        $isOwner = (string) ($row['absentee'] ?? '') === $actorId
            || (string) ($row['createdBy'] ?? '') === $actorId;
        if ($isOwner === false && $this->isCoordinator($actorId) === false) {
            return $this->forbidden('You may only revoke your own substitution');
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
        try {
            $user = $this->requireUser();
        } catch (OCSForbiddenException $e) {
            return $this->forbidden($e->getMessage());
        }

        // Resolution runs in the calling user's RBAC context, so items the
        // substitute cannot read are already excluded.
        $work = $this->substitutionService->getSubstitutedWorkFor(userId: $user->getUID());
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
        try {
            $actorId = $this->requireUser()->getUID();
        } catch (OCSForbiddenException $e) {
            return $this->forbidden($e->getMessage());
        }

        $row = $this->findSubstitution($id);
        if ($row === null) {
            return new JSONResponse(['error' => 'Substitution not found'], Http::STATUS_NOT_FOUND);
        }

        $involved = in_array(
            $actorId,
            [
                (string) ($row['absentee'] ?? ''),
                (string) ($row['substitute'] ?? ''),
                (string) ($row['createdBy'] ?? ''),
            ],
            true
        );
        if ($involved === false && $this->isCoordinator($actorId) === false) {
            return $this->forbidden();
        }

        return new JSONResponse(['results' => $this->auditService->getActionsForSubstitution($id)]);
    }//end actions()

    /**
     * Preview a bulk reassignment. Coordinator-only.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    #[NoAdminRequired]
    public function reassignPreview(): JSONResponse
    {
        $guard = $this->requireCoordinator();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $preview = $this->reassignmentService->preview(
                fromUser: (string) $this->request->getParam('fromUser', ''),
                filter: $this->reassignmentFilter()
            );
            return new JSONResponse($preview);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Reassignment preview failed', ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end reassignPreview()

    /**
     * Execute a bulk reassignment. Coordinator-only.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    #[NoAdminRequired]
    public function reassignExecute(): JSONResponse
    {
        $guard = $this->requireCoordinator();
        if ($guard !== null) {
            return $guard;
        }

        $user = $this->userSession->getUser();

        try {
            $result = $this->reassignmentService->execute(
                fromUser: (string) $this->request->getParam('fromUser', ''),
                toUser: (string) $this->request->getParam('toUser', ''),
                filter: $this->reassignmentFilter(),
                actorId: ($user !== null ? $user->getUID() : '')
            );
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Reassignment execute failed', ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end reassignExecute()

    /**
     * Build the optional reassignment filter from request params.
     *
     * @return array<string, mixed>|null
     */
    private function reassignmentFilter(): ?array
    {
        $caseType = (string) $this->request->getParam('caseType', '');
        if ($caseType === '') {
            return null;
        }

        return ['caseType' => $caseType];
    }//end reassignmentFilter()

    /**
     * Whether a user holds the procest coordinator role (NC admin).
     *
     * Coordinator authority is delegated to Nextcloud admin membership, the
     * same model used elsewhere in procest (e.g. ComplaintController).
     *
     * @param string $userId The user id.
     *
     * @return bool
     */
    private function isCoordinator(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        return $this->groupManager->isAdmin($userId);
    }//end isCoordinator()

    /**
     * Require an authenticated user; throw OCSForbiddenException otherwise.
     *
     * Per ADR-005 Rule 3 — unauthenticated callers are denied before any data
     * work runs (fail closed).
     *
     * @return IUser The authenticated user.
     *
     * @throws OCSForbiddenException When no user is authenticated.
     */
    private function requireUser(): IUser
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Not authenticated');
        }

        return $user;
    }//end requireUser()

    /**
     * Require a coordinator; returns a JSONResponse to short-circuit on failure.
     *
     * @return JSONResponse|null Null when the caller is a coordinator.
     */
    private function requireCoordinator(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->forbidden();
        }

        if ($this->isCoordinator($user->getUID()) === false) {
            return $this->forbidden('This action requires the coordinator role');
        }

        return null;
    }//end requireCoordinator()

    /**
     * Find a single substitution by id (system-context read for guard checks).
     *
     * @param string $id The substitution UUID.
     *
     * @return array<string, mixed>|null
     */
    private function findSubstitution(string $id): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('substitution_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
    }//end findSubstitution()

    /**
     * Fetch all substitutions (used by index, then filtered per role).
     *
     * @return array<int, array<string, mixed>>
     */
    private function allSubstitutions(): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('substitution_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            return [];
        }
    }//end allSubstitutions()

    /**
     * Build a 403 response (fail closed).
     *
     * @param string $message Optional message.
     *
     * @return JSONResponse
     */
    private function forbidden(string $message='Not authorised'): JSONResponse
    {
        return new JSONResponse(['error' => $message], Http::STATUS_FORBIDDEN);
    }//end forbidden()
}//end class
