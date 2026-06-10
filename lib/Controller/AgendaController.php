<?php

/**
 * Procest AgendaController
 *
 * REST API controller for besluitvorming agenda management. Adds and updates
 * agenda items on cases (besluitvorming workflow). Authenticated-user only;
 * mandates and per-case authorization are enforced via the underlying
 * OpenRegister object permissions (ADR-022).
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AgendaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller exposing besluitvorming agenda endpoints.
 *
 * @psalm-suppress UnusedClass
 */
class AgendaController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request       The request.
     * @param AgendaService   $agendaService Agenda-item service.
     * @param IUserSession    $userSession   User session for guard.
     * @param LoggerInterface $logger        Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly AgendaService $agendaService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Add a new agenda item to a case.
     *
     * @param string $id The case id.
     *
     * @return JSONResponse The updated agenda items list.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[NoAdminRequired]
    public function addToAgenda(string $id): JSONResponse
    {
        $unauthorized = $this->requireAuthenticated();
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->bodyParams();

        try {
            $result = $this->agendaService->addToAgenda(caseId: $id, item: $payload);
        } catch (Throwable $e) {
            $this->logger->error(
                'AgendaController::addToAgenda failed: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'caseId' => $id]
            );
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse($result, Http::STATUS_CREATED);
    }//end addToAgenda()

    /**
     * Update an existing agenda item on a case.
     *
     * @param string $id The case id.
     *
     * @return JSONResponse The updated agenda items list.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[NoAdminRequired]
    public function updateAgendaItem(string $id): JSONResponse
    {
        $unauthorized = $this->requireAuthenticated();
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->bodyParams();

        try {
            $result = $this->agendaService->updateAgendaItem(caseId: $id, patch: $payload);
        } catch (Throwable $e) {
            $this->logger->error(
                'AgendaController::updateAgendaItem failed: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'caseId' => $id]
            );
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse($result, Http::STATUS_OK);
    }//end updateAgendaItem()

    /**
     * Read JSON / form body params, excluding routing params.
     *
     * @return array<string, mixed> The body params.
     */
    private function bodyParams(): array
    {
        $params = $this->request->getParams();
        unset($params['id'], $params['_route']);
        return $params;
    }//end bodyParams()

    /**
     * Require an authenticated user; return a response otherwise.
     *
     * @return JSONResponse|null Null when authorised, a response when blocked.
     */
    private function requireAuthenticated(): ?JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                ['error' => 'Authenticatie vereist'],
                Http::STATUS_BAD_REQUEST
            );
        }
        return null;
    }//end requireAuthenticated()
}//end class
