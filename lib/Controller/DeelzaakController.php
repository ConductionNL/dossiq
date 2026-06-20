<?php

/**
 * Procest Deelzaak (sub-case) Controller
 *
 * Thin REST surface in front of {@see DeelzaakService}. Used by the case
 * detail view (parent + sub-case list) and the case list page (badge counts).
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
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\DeelzaakService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for sub-case operations.
 */
class DeelzaakController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         Inbound request.
     * @param DeelzaakService $deelzaakService Backend service.
     * @param IUserSession    $userSession     Current user session.
     */
    public function __construct(
        IRequest $request,
        private readonly DeelzaakService $deelzaakService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List sub-cases of a parent.
     *
     * @param string $caseId Parent case UUID.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    public function list(string $caseId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
                [
                    'results' => $this->deelzaakService->listSubCases(parentCaseUuid: $caseId),
                ]
                );
    }//end list()

    /**
     * Return the parent case object.
     *
     * @param string $caseId Parent case UUID.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T02
     */
    public function parent(string $caseId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $parent = $this->deelzaakService->getParentCase(childCaseUuid: $caseId);
        if ($parent === null) {
            return new JSONResponse(['message' => 'not_found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($parent);
    }//end parent()

    /**
     * Batch sub-case counts for a list page.
     *
     * Accepts `ids` as a comma-separated query parameter or POST body.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Map keyed by parent UUID.
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T03
     */
    public function counts(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $raw = $this->request->getParam('ids', '');
        if (is_array($raw) === true) {
            $ids = $raw;
        } else {
            $ids = $raw === '' ? [] : explode(',', (string) $raw);
        }

        $ids = array_values(array_filter(array_map('trim', $ids), static fn ($value): bool => $value !== ''));
        if ($ids === []) {
            return new JSONResponse(['message' => 'ids parameter is required'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(['counts' => $this->deelzaakService->getSubCaseCounts(parentUuids: $ids)]);
    }//end counts()

    /**
     * Pre-flight validate a sub-case creation request.
     *
     * Expects JSON body `{ parentCaseUuid, childCaseTypeId }`.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T08
     */
    public function validate(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $parent = (string) $this->request->getParam('parentCaseUuid', '');
        $child  = (string) $this->request->getParam('childCaseTypeId', '');
        if ($parent === '' || $child === '') {
            return new JSONResponse(
                    [
                        'message' => 'parentCaseUuid and childCaseTypeId are required',
                    ],
                    Http::STATUS_BAD_REQUEST
                    );
        }

        $result = $this->deelzaakService->validateCreate(
            parentCaseUuid: $parent,
            childCaseTypeId: $child,
        );

        if ($result['ok'] === false) {
            return new JSONResponse($result, Http::STATUS_CONFLICT);
        }

        return new JSONResponse($result);
    }//end validate()

    /**
     * Unlink every sub-case from the given parent.
     *
     * Used by the "delete parent with children" confirmation flow so the
     * sub-cases survive deletion as orphans.
     *
     * @param string $caseId Parent case UUID.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T11
     */
    public function unlink(string $caseId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
                [
                    'unlinked' => $this->deelzaakService->unlinkSubCases(parentCaseUuid: $caseId),
                ]
                );
    }//end unlink()
}//end class
