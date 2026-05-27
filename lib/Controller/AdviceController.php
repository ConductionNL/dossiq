<?php

/**
 * Procest Advice Controller.
 *
 * Workflow endpoints for advice requests (adviesAanvraag). CRUD is delegated
 * to the manifest renderer (OpenRegister); this controller exposes only the
 * domain operations that need server-side side-effects: status transitions
 * (which trigger notifications) and manual reminder dispatch.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\AdviceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for advice request workflow operations.
 */
class AdviceController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName       The app name
     * @param IRequest        $request       The HTTP request
     * @param AdviceService   $adviceService The advice service
     * @param IUserSession    $userSession   The user session
     * @param LoggerInterface $logger        The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AdviceService $adviceService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Transition the status of an advice request.
     *
     * Drives workflow side-effects (notifications to adviseur / requester)
     * that the manifest CRUD path cannot perform. The CRUD itself (writing
     * the object) is still performed by this method via the service so the
     * notification + persistence remain transactional from the caller's
     * point of view.
     *
     * Accepted payloads:
     *   { "to": "aangevraagd" }                  — fire "advies_aangevraagd"
     *   { "to": "ontvangen", "adviesDocument": "<fileId>" } — mark received
     *   { "to": "verlopen" }                     — mark expired
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Updated record
     *
     * @NoAdminRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function transitionStatus(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data = $this->readJsonBody();
        $to   = (string) ($data['to'] ?? '');

        if ($to === '') {
            return new JSONResponse(
                ['error' => 'to status is required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $advice = $this->adviceService->transitionStatus($id, $to, $data);
            return new JSONResponse($advice);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Procest: advice transition failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Could not transition advice request'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end transitionStatus()

    /**
     * Dispatch a manual reminder notification to the adviseur.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Success response
     *
     * @NoAdminRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function dispatchReminder(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->adviceService->dispatchReminder($id);
            return new JSONResponse(['status' => 'reminded']);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: advice dispatchReminder failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Could not send reminder'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end dispatchReminder()

    /**
     * Decode a JSON request body safely.
     *
     * @return array<string, mixed> Decoded payload or empty array
     */
    private function readJsonBody(): array
    {
        $content = $this->request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end readJsonBody()
}//end class
