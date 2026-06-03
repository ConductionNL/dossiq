<?php

/**
 * Procest Specialist Beschikbaarheid Controller.
 *
 * Read-only real-time availability endpoint consumed by the KCC-werkplek UI.
 * Availability records are written by the background refresh job, never by this
 * controller.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T13
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\BelplanRoutingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Real-time read-only specialist availability API.
 */
class SpecialistBeschikbaarheidController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                $appName        The app name.
     * @param IRequest              $request        The request.
     * @param BelplanRoutingService $routingService The routing service.
     * @param IUserSession          $userSession    The user session.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly BelplanRoutingService $routingService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get specialist availability, optionally filtered by vaardigheid.
     *
     * @param string $vaardigheid The vaardigheid filter, empty for all.
     *
     * @return JSONResponse The availability records.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T13
     */
    public function index(string $vaardigheid=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $records = $this->routingService->getSpecialistBeschikbaarheid($vaardigheid);
        return new JSONResponse(['specialisten' => $records]);
    }//end index()
}//end class
