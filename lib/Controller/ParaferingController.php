<?php

/**
 * Procest Parafering Controller
 *
 * Handles API endpoints for B&W parafering workflow: voorstel CRUD,
 * parafering actions (paraferen, terugsturen, adviseren), and audit trail.
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

use OCA\Procest\Service\ParaferingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for B&W parafering workflow operations.
 *
 * @psalm-suppress UnusedClass
 */
class ParaferingController extends Controller
{
    /**
     * Constructor.
     *
     * @param string            $appName           The app name.
     * @param IRequest          $request           The request object.
     * @param ParaferingService $paraferingService The parafering service.
     * @param IUserSession      $userSession       The user session.
     * @param LoggerInterface   $logger            The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ParaferingService $paraferingService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Create a new voorstel.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function createVoorstel(): JSONResponse
    {
        try {
            $data   = $this->getRequestBody();
            $userId = $this->userSession->getUser()?->getUID() ?? 'system';

            if (empty($data['caseId']) === true) {
                return new JSONResponse(
                    ['error' => 'Parameter caseId is required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $data['steller'] = $data['steller'] ?? $userId;
            $voorstel        = $this->paraferingService->createVoorstel($data);

            return new JSONResponse($voorstel, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create voorstel: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Failed to create voorstel: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end createVoorstel()

    /**
     * Start parafering on a voorstel.
     *
     * @param string $id The voorstel ID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function startParafering(string $id): JSONResponse
    {
        try {
            $data     = $this->getRequestBody();
            $voorstel = $data['voorstel'] ?? [];
            $route    = $data['route'] ?? [];

            if (empty($voorstel) === true || empty($route) === true) {
                return new JSONResponse(
                    ['error' => 'Parameters voorstel and route are required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $result = $this->paraferingService->startParafering($voorstel, $route);

            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to start parafering: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Failed to start parafering: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end startParafering()

    /**
     * Execute a parafering action (paraferen).
     *
     * @param string $id The voorstel ID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function paraferen(string $id): JSONResponse
    {
        return $this->handleAction(id: $id, action: ParaferingService::ACTION_PARAFEREN);
    }//end paraferen()

    /**
     * Execute a terugsturen action.
     *
     * @param string $id The voorstel ID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function terugsturen(string $id): JSONResponse
    {
        return $this->handleAction(id: $id, action: ParaferingService::ACTION_TERUGSTUREN);
    }//end terugsturen()

    /**
     * Execute an adviseren action.
     *
     * @param string $id The voorstel ID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function adviseren(string $id): JSONResponse
    {
        return $this->handleAction(id: $id, action: ParaferingService::ACTION_ADVISEREN);
    }//end adviseren()

    /**
     * Get the audit trail for a voorstel.
     *
     * @param string $id The voorstel ID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function auditTrail(string $id): JSONResponse
    {
        try {
            $data     = $this->getRequestBody();
            $voorstel = $data['voorstel'] ?? [];

            $trail = $this->paraferingService->getAuditTrail($voorstel);

            return new JSONResponse(['auditTrail' => $trail]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get audit trail: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Failed to get audit trail: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end auditTrail()

    /**
     * Handle a parafering action.
     *
     * @param string $id     The voorstel ID.
     * @param string $action The action type.
     *
     * @return JSONResponse
     */
    private function handleAction(string $id, string $action): JSONResponse
    {
        try {
            $data     = $this->getRequestBody();
            $voorstel = $data['voorstel'] ?? [];
            $comment  = $data['comment'] ?? '';
            $namens   = $data['namens'] ?? null;

            $userId = $this->userSession->getUser()?->getUID() ?? 'system';

            $result = $this->paraferingService->executeAction(
                $voorstel,
                $action,
                $userId,
                $comment,
                $namens
            );

            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error("Failed to execute {$action}: ".$e->getMessage());
            return new JSONResponse(
                ['error' => "Failed to execute {$action}: ".$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end handleAction()

    /**
     * Get the parsed request body.
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
