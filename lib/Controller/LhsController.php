<?php

/**
 * Procest LHS Controller
 *
 * Action endpoint for the LHS recommendation engine. CRUD over the
 * `lhsMatrix` and `lhsRecommendation` schemas is served by the OpenRegister
 * manifest renderer — this controller only owns the engine actions:
 *   - POST /api/lhs/recommend (matrix lookup + persistence)
 *   - POST /api/lhs/recommendations/{id}/override (apply inspector override)
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
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\LhsLookupService;
use OCA\Procest\Service\Vth\LhsRecommendationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Controller for LHS engine actions.
 *
 * @spec openspec/changes/enforcement-lhs/tasks.md#T03
 *
 * @psalm-suppress UnusedClass
 */
class LhsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                   $appName          App name
     * @param IRequest                 $request          Request
     * @param LhsRecommendationService $lhsService       LHS engine
     * @param LhsLookupService         $lhsLookupService LHS simple lookup service
     * @param IUserSession             $userSession      User session
     * @param IGroupManager            $groupManager     Group manager
     * @param LoggerInterface          $logger           Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly LhsRecommendationService $lhsService,
        private readonly LhsLookupService $lhsLookupService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Recommend an intervention for a (case, ernst, gedrag, actorType) triple.
     *
     * Body parameters:
     *   - caseId (string, required)
     *   - ernst (string, required)
     *   - gedrag (string, required)
     *   - actorType (string, required)
     *   - lhsVersion (int, optional)
     *   - inspection (string, optional)
     *
     * @return JSONResponse The persisted lhsRecommendation row
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/enforcement-lhs/tasks.md#T03
     */
    public function recommend(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authenticatie vereist'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $caseId    = (string) $this->request->getParam('caseId', '');
        $ernst     = (string) $this->request->getParam('ernst', '');
        $gedrag    = (string) $this->request->getParam('gedrag', '');
        $actorType = (string) $this->request->getParam('actorType', '');
        if (in_array('', [$caseId, $ernst, $gedrag, $actorType], true) === true) {
            return new JSONResponse(
                ['error' => 'caseId, ernst, gedrag en actorType zijn verplicht'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $versionParam = $this->request->getParam('lhsVersion');
        $version      = null;
        if ($versionParam !== null) {
            $version = (int) $versionParam;
        }

        $inspection   = $this->request->getParam('inspection');
        $inspectionId = null;
        if (is_string($inspection) === true && $inspection !== '') {
            $inspectionId = $inspection;
        }

        try {
            $recommendation = $this->lhsService->recommend(
                caseId: $caseId,
                ernst: $ernst,
                gedrag: $gedrag,
                actorType: $actorType,
                lhsVersion: $version,
                inspection: $inspectionId,
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY,
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest LHS recommend failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'LHS-aanbeveling mislukt'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try

        return new JSONResponse($recommendation);
    }//end recommend()

    /**
     * Override an existing LHS recommendation.
     *
     * Body parameters:
     *   - recommendation (array, required) — the original row including id
     *   - intervention   (string, required)
     *   - justification  (string, required, >= 20 chars)
     *
     * Manager role is auto-detected from the Nextcloud admin group; UI may
     * additionally pass `userRole` for explicit selection.
     *
     * @return JSONResponse The updated lhsRecommendation row
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/enforcement-lhs/tasks.md#T03
     */
    public function override(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authenticatie vereist'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $recommendation = $this->request->getParam('recommendation');
        $intervention   = (string) $this->request->getParam('intervention', '');
        $justification  = (string) $this->request->getParam('justification', '');
        $hasBlank       = in_array('', [$intervention, $justification], true);
        if (is_array($recommendation) === false || $hasBlank === true) {
            return new JSONResponse(
                ['error' => 'recommendation, intervention en justification zijn verplicht'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $declaredRole = (string) $this->request->getParam('userRole', '');
        $isManager    = $this->groupManager->isAdmin($user->getUID());
        $userRole     = 'inspector';
        if ($declaredRole === 'manager' && $isManager === true) {
            $userRole = 'manager';
        }

        try {
            $updated = $this->lhsService->override(
                recommendation: $recommendation,
                intervention: $intervention,
                justification: $justification,
                userRole: $userRole,
            );
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status  = Http::STATUS_UNPROCESSABLE_ENTITY;
            if ($message === 'Verzwaring vereist managerrol') {
                $status = Http::STATUS_FORBIDDEN;
            }

            return new JSONResponse(['error' => $message], $status);
        } catch (Throwable $e) {
            $this->logger->error('Procest LHS override failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'LHS-override mislukt'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try

        return new JSONResponse($updated);
    }//end override()

    /**
     * Look up an LHS interventieladder step for a gedrag × gevolg combination.
     *
     * Query parameters:
     *   - gedrag (string, required): A | B | C | D
     *   - gevolg (string, required): 1 | 2 | 3 | 4
     *
     * @return JSONResponse The matching lhsMatrixCell {gedragRow, gevolgColumn, interventieStep, description}
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/vth-module/tasks.md#task-8
     */
    public function lookup(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authenticatie vereist'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $gedrag = (string) $this->request->getParam('gedrag', '');
        $gevolg = (string) $this->request->getParam('gevolg', '');

        if ($gedrag === '' || $gevolg === '') {
            return new JSONResponse(
                ['error' => 'gedrag en gevolg zijn verplicht'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $cell = $this->lhsLookupService->lookup(gedrag: $gedrag, gevolg: $gevolg);
        } catch (RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest LHS lookup failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'LHS opzoeken mislukt'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }

        return new JSONResponse($cell);
    }//end lookup()
}//end class
