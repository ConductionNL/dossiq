<?php

/**
 * Procest ParafeerRoute Controller
 *
 * REST endpoints for voorstel-level parafering engine operations
 * (start, complete step, skip step, ad-hoc step). Generic CRUD on
 * parafeerroute objects is delegated to OpenRegister's auto-exposed
 * /api/objects/<register>/<schema> endpoints — this controller only
 * hosts the workflow engine that has business logic beyond storage.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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

use OCA\Procest\Service\ParafeerRouteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Controller for the voorstel parafering routing engine.
 *
 * @spec openspec/changes/parafeerroute-engine/tasks.md#T05
 *
 * @psalm-suppress UnusedClass
 */
class ParafeerRouteController extends Controller
{
    /**
     * Constructor.
     *
     * @param string               $appName      App name
     * @param IRequest             $request      Request object
     * @param ParafeerRouteService $routeService Route engine service
     * @param IUserSession         $userSession  User session
     * @param IGroupManager        $groupManager Group manager (admin check)
     * @param LoggerInterface      $logger       Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ParafeerRouteService $routeService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Start parafering on a voorstel.
     *
     * @param string $voorstelId The voorstel UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/parafeerroute-engine/tasks.md#T05
     */
    public function start(string $voorstelId): JSONResponse
    {
        if ($this->requireUser() === false) {
            return new JSONResponse(
                ['error' => 'Authenticatie vereist'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        try {
            return new JSONResponse($this->routeService->startParafering($voorstelId));
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to start parafering: '.$e->getMessage(),
            );
            return new JSONResponse(
                ['error' => 'Starten van parafering mislukt'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end start()

    /**
     * Complete the active step on a voorstel.
     *
     * @param string $voorstelId The voorstel UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/parafeerroute-engine/tasks.md#T05
     */
    public function completeStep(string $voorstelId): JSONResponse
    {
        if ($this->requireUser() === false) {
            return new JSONResponse(
                ['error' => 'Authenticatie vereist'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        try {
            $data = $this->getRequestBody();
            return new JSONResponse($this->routeService->completeStep($voorstelId, $data));
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to complete parafering step: '.$e->getMessage(),
            );
            return new JSONResponse(
                ['error' => 'Stap kon niet worden voltooid'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end completeStep()

    /**
     * Skip a step on a voorstel (manager only; mandatory reason).
     *
     * @param string $voorstelId The voorstel UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/parafeerroute-engine/tasks.md#T05
     */
    public function skipStep(string $voorstelId): JSONResponse
    {
        if ($this->requireAdmin() === false) {
            return new JSONResponse(
                ['error' => 'Manager-rechten vereist'],
                Http::STATUS_FORBIDDEN,
            );
        }

        try {
            $data   = $this->getRequestBody();
            $step   = (int) ($data['step'] ?? 0);
            $reason = trim((string) ($data['reason'] ?? ''));

            if ($step < 1) {
                return new JSONResponse(
                    ['error' => 'Geldig stapnummer is vereist'],
                    Http::STATUS_BAD_REQUEST,
                );
            }

            if ($reason === '') {
                return new JSONResponse(
                    ['error' => 'Reden is verplicht bij overslaan'],
                    Http::STATUS_BAD_REQUEST,
                );
            }

            return new JSONResponse($this->routeService->skipStep($voorstelId, $step, $reason));
        } catch (RuntimeException $e) {
            $this->logger->warning(
                'Procest: parafering skip blocked: '.$e->getMessage(),
            );
            return new JSONResponse(
                ['error' => 'Deze stap kan niet worden overgeslagen'],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to skip parafering step: '.$e->getMessage(),
            );
            return new JSONResponse(
                ['error' => 'Stap overslaan mislukt'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end skipStep()

    /**
     * Add an ad-hoc step to a voorstel route snapshot.
     *
     * @param string $voorstelId The voorstel UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/parafeerroute-engine/tasks.md#T05
     */
    public function addStep(string $voorstelId): JSONResponse
    {
        if ($this->requireUser() === false) {
            return new JSONResponse(
                ['error' => 'Authenticatie vereist'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        try {
            $data      = $this->getRequestBody();
            $afterStep = (int) ($data['afterStep'] ?? 0);
            $stepData  = $data['stepData'] ?? [];
            if (is_array($stepData) === false) {
                $stepData = [];
            }

            return new JSONResponse(
                $this->routeService->addAdhocStep($voorstelId, $afterStep, $stepData),
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to add ad-hoc parafering step: '.$e->getMessage(),
            );
            return new JSONResponse(
                ['error' => 'Stap toevoegen mislukt'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end addStep()

    /**
     * Check whether the current user is in the admin group.
     *
     * @return bool
     */
    private function requireAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end requireAdmin()

    /**
     * Check whether a session user is available.
     *
     * @return bool
     */
    private function requireUser(): bool
    {
        return $this->userSession->getUser() !== null;
    }//end requireUser()

    /**
     * Parse the request body as a JSON associative array.
     *
     * @return array<string, mixed>
     */
    private function getRequestBody(): array
    {
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getRequestBody()
}//end class
