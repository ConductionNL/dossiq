<?php

/**
 * Procest Case Relation (peer / relevanteAndereZaken) Controller
 *
 * Thin REST surface in front of {@see CaseRelationService}. Used by the case
 * detail view's "Gerelateerde zaken" section to list, create and remove typed
 * peer relations.
 *
 * Every endpoint is `#[NoAdminRequired]` and authenticated; per-object
 * authorization is enforced because {@see CaseRelationService} resolves both
 * cases through OpenRegister's ObjectService, which applies OR RBAC for the
 * session user — an unreadable case fails closed with `access_denied`. There
 * is therefore no IDOR: a user can only act on cases they can already read.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\CaseRelationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * REST controller for typed peer case relations.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class CaseRelationController extends Controller
{

    /**
     * Map service guard reasons to HTTP status codes.
     *
     * @var array<string, int>
     */
    private const REASON_STATUS = [
        'invalid_aard_relatie' => Http::STATUS_BAD_REQUEST,
        'missing_case_id'      => Http::STATUS_BAD_REQUEST,
        'self_relation'        => Http::STATUS_BAD_REQUEST,
        'duplicate'            => Http::STATUS_CONFLICT,
        'hierarchy_overlap'    => Http::STATUS_CONFLICT,
        'access_denied'        => Http::STATUS_FORBIDDEN,
    ];

    /**
     * Constructor.
     *
     * @param IRequest            $request             Inbound request.
     * @param CaseRelationService $caseRelationService Backend service.
     * @param IUserSession        $userSession         Current user session.
     * @param CaseAccessGuard     $caseAccessGuard     Per-case authorization (fails closed).
     */
    public function __construct(
        IRequest $request,
        private readonly CaseRelationService $caseRelationService,
        private readonly IUserSession $userSession,
        private readonly CaseAccessGuard $caseAccessGuard,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the typed peer relations of a case.
     *
     * Per-object guard: `CaseAccessGuard::hasCaseReadAccess()`.
     *
     * This docblock used to read *"the service resolves the case through OR
     * RBAC; an unreadable case yields an empty list"*. That was false. The
     * refusal in `CaseRelationService` is correctly shaped but INERT: it keys
     * off `find()` returning null, and OpenRegister's `PermissionHandler`
     * returns `true` for a schema with no `authorization` block
     * (`enforce_default_closed` defaults false) — and none of procest's 85
     * schemas declares one. `find()` therefore never returned null for an
     * existing case, so `access_denied` was unreachable. See
     * ConductionNL/.github#372.
     *
     * @param string $caseId Case UUID.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function list(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
            return new JSONResponse(
                ['ok' => false, 'reason' => 'access_denied'],
                Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(
                [
                    'results' => $this->caseRelationService->listRelations(caseId: $caseId),
                ]
                );
    }//end list()

    /**
     * Create a typed peer relation between two cases.
     *
     * Expects JSON body `{ targetId, aardRelatie, toelichting? }`.
     *
     * Per-object guard: `CaseAccessGuard::hasCaseReadAccess()` on BOTH the
     * origin case (`$caseId`) and the target case, which is what this docblock
     * always claimed the service did. It did not — see `list()` above for why
     * the service-level refusal was inert.
     *
     * @param string $caseId Origin case UUID.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function create(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $targetId    = (string) $this->request->getParam('targetId', '');
        $aardRelatie = (string) $this->request->getParam('aardRelatie', '');
        $toelichting = $this->request->getParam('toelichting', null);
        if ($toelichting !== null) {
            $toelichting = (string) $toelichting;
        }

        if ($targetId === '' || $aardRelatie === '') {
            return new JSONResponse(
                ['ok' => false, 'reason' => 'missing_case_id', 'message' => 'targetId and aardRelatie are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($this->bothCasesReadable(caseId: $caseId, targetId: $targetId, user: $user) === false) {
            return new JSONResponse(
                ['ok' => false, 'reason' => 'access_denied'],
                Http::STATUS_FORBIDDEN
            );
        }

        $result = $this->caseRelationService->addRelation(
            caseId: $caseId,
            targetId: $targetId,
            aardRelatie: $aardRelatie,
            toelichting: $toelichting,
        );

        if ($result['ok'] === false) {
            $status = self::REASON_STATUS[$result['reason'] ?? ''] ?? Http::STATUS_BAD_REQUEST;
            return new JSONResponse($result, $status);
        }

        return new JSONResponse($result, Http::STATUS_CREATED);
    }//end create()

    /**
     * Remove a typed peer relation between two cases (two-sided).
     *
     * Per-object guard: `CaseAccessGuard::hasCaseReadAccess()` on both cases.
     *
     * @param string $caseId      Origin case UUID.
     * @param string $targetId    Target case UUID.
     * @param string $aardRelatie Relation type to remove.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function destroy(string $caseId, string $targetId, string $aardRelatie): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->bothCasesReadable(caseId: $caseId, targetId: $targetId, user: $user) === false) {
            return new JSONResponse(
                ['ok' => false, 'reason' => 'access_denied'],
                Http::STATUS_FORBIDDEN
            );
        }

        $result = $this->caseRelationService->removeRelation(
            caseId: $caseId,
            targetId: $targetId,
            aardRelatie: $aardRelatie,
        );

        if ($result['ok'] === false) {
            $status = self::REASON_STATUS[$result['reason'] ?? ''] ?? Http::STATUS_BAD_REQUEST;
            return new JSONResponse($result, $status);
        }

        return new JSONResponse($result);
    }//end destroy()

    /**
     * Whether the caller may read both ends of a relation.
     *
     * A relation is two-sided: writing or removing it touches both cases, so
     * holding access to only one end is not enough. Both checks are evaluated
     * against the same fail-closed guard.
     *
     * @param string $caseId   Origin case UUID.
     * @param string $targetId Target case UUID.
     * @param IUser  $user     The authenticated user.
     *
     * @return bool True when both cases are readable by this user.
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    private function bothCasesReadable(string $caseId, string $targetId, IUser $user): bool
    {
        if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
            return false;
        }

        return $this->caseAccessGuard->hasCaseReadAccess(caseId: $targetId, user: $user);
    }//end bothCasesReadable()
}//end class
