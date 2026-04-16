<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Procest Deelzaak Controller
 *
 * REST API endpoints for deelzaak (sub-case) creation, hierarchy retrieval,
 * closure validation, and vervolg-zaak creation.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\DeelzaakService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for deelzaak operations.
 *
 * Endpoints:
 * - POST   /api/procest/deelzaak/{caseId}               — create deelzaak
 * - GET    /api/procest/deelzaak/{caseId}/hierarchy      — get full hierarchy tree
 * - GET    /api/procest/deelzaak/{caseId}/closure-check  — validate closure
 * - POST   /api/procest/deelzaak/{caseId}/vervolgzaak    — create follow-up case
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T02
 */
class DeelzaakController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName         App name
     * @param IRequest        $request         Incoming request
     * @param DeelzaakService $deelzaakService Deelzaak business logic
     * @param IUserSession    $userSession     Current user session
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T02
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DeelzaakService $deelzaakService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Create a deelzaak under the given parent case.
     *
     * Request body (JSON):
     * - caseTypeId (string, required): UUID of the deelzaak caseType
     * - title      (string, optional): Override title for the deelzaak
     * - assignee   (string, optional): Nextcloud UID to assign the deelzaak to
     *
     * @param string $caseId UUID of the parent (hoofdzaak) case
     *
     * @return JSONResponse 201 with created deelzaak, or 400/500 on error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T02
     */
    public function create(string $caseId): JSONResponse
    {
        $body       = $this->request->getParams();
        $caseTypeId = trim((string) ($body['caseTypeId'] ?? ''));

        if ($caseTypeId === '') {
            return new JSONResponse(
                ['error' => 'caseTypeId is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $title    = null;
        $assignee = null;

        if (isset($body['title']) === true) {
            $title = trim((string) $body['title']);
        }

        if (isset($body['assignee']) === true) {
            $assignee = trim((string) $body['assignee']);
        }

        $user        = $this->userSession->getUser();
        $requestedBy = null;
        if ($user !== null) {
            $requestedBy = $user->getUID();
        }

        $titleParam    = null;
        $assigneeParam = null;
        if ($title !== null && $title !== '') {
            $titleParam = $title;
        }

        if ($assignee !== null && $assignee !== '') {
            $assigneeParam = $assignee;
        }

        try {
            $deelzaak = $this->deelzaakService->createDeelzaak(
                parentCaseId: $caseId,
                caseTypeId: $caseTypeId,
                title: $titleParam,
                assignee: $assigneeParam,
                requestedBy: $requestedBy,
            );
            return new JSONResponse($deelzaak, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['error' => 'Internal error: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end create()

    /**
     * Retrieve the full case hierarchy rooted at the given case.
     *
     * Returns a nested structure:
     * `{ "case": {...}, "children": [{ "case": {...}, "children": [...] }] }`
     *
     * @param string $caseId UUID of the root case
     *
     * @return JSONResponse 200 with hierarchy tree, or 404/500 on error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T02
     */
    public function hierarchy(string $caseId): JSONResponse
    {
        try {
            $tree = $this->deelzaakService->getHierarchy(caseId: $caseId);
            return new JSONResponse($tree);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'not found') === true) {
                return new JSONResponse(['error' => $msg], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['error' => $msg], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['error' => 'Internal error: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end hierarchy()

    /**
     * Check whether a case can be closed.
     *
     * Returns `{ "canClose": true/false, "openDeelzaken": [...] }`.
     *
     * @param string $caseId UUID of the case to check
     *
     * @return JSONResponse 200 with closure check result, or 500 on error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T02
     */
    public function closureCheck(string $caseId): JSONResponse
    {
        try {
            $result = $this->deelzaakService->validateClosureAllowed(caseId: $caseId);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['error' => 'Internal error: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end closureCheck()

    /**
     * Create a vervolg-zaak (follow-up case) from the given case.
     *
     * Request body (JSON):
     * - caseTypeId (string, required): UUID of the follow-up caseType
     * - title      (string, optional): Override title for the vervolg-zaak
     *
     * @param string $caseId UUID of the source (predecessor) case
     *
     * @return JSONResponse 201 with created vervolg-zaak, or 400/500 on error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T02
     */
    public function vervolgzaak(string $caseId): JSONResponse
    {
        $body       = $this->request->getParams();
        $caseTypeId = trim((string) ($body['caseTypeId'] ?? ''));

        if ($caseTypeId === '') {
            return new JSONResponse(
                ['error' => 'caseTypeId is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $title = null;
        if (isset($body['title']) === true) {
            $title = trim((string) $body['title']);
        }

        $user        = $this->userSession->getUser();
        $requestedBy = null;
        if ($user !== null) {
            $requestedBy = $user->getUID();
        }

        $titleParam = null;
        if ($title !== null && $title !== '') {
            $titleParam = $title;
        }

        try {
            $vervolgzaak = $this->deelzaakService->createVervolgzaak(
                sourceCaseId: $caseId,
                caseTypeId: $caseTypeId,
                title: $titleParam,
                requestedBy: $requestedBy,
            );
            return new JSONResponse($vervolgzaak, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['error' => 'Internal error: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end vervolgzaak()
}//end class
