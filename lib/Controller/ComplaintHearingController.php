<?php

/**
 * Procest Complaint Hearing Controller
 *
 * REST API for the hoorgesprek (hearing) step of complaint handling per Awb
 * chapter 9: listing a complaint's hearings, scheduling one, and recording its
 * outcome.
 *
 * Split out of ComplaintController along the sub-domain seam — hearings are a
 * distinct workflow step with their own service (HearingService) and their own
 * `/api/complaints/{id}/hearings` URL group. Scheduling and outcome recording
 * also drive the parent complaint's status, so ComplaintService stays injected.
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Complaint\ComplaintAccessGuard;
use OCA\Procest\Service\ComplaintService;
use OCA\Procest\Service\HearingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for complaint hearings (hoorgesprekken).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */
class ComplaintHearingController extends Controller
{
    /**
     * Constructor.
     *
     * @param string               $appName          App name
     * @param IRequest             $request          Request
     * @param ComplaintService     $complaintService Complaint service
     * @param HearingService       $hearingService   Hearing service
     * @param ComplaintAccessGuard $accessGuard      Shared complaint authorization guard
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ComplaintService $complaintService,
        private readonly HearingService $hearingService,
        private readonly ComplaintAccessGuard $accessGuard,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List hearings for a complaint.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse List of hearings
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function hearings(string $id): JSONResponse
    {
        if ($this->accessGuard->currentUid() === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $hearings = $this->hearingService->getHearingsForComplaint($id);
        return new JSONResponse(['results' => $hearings]);
    }//end hearings()

    /**
     * Schedule a new hearing for a complaint.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Created hearing
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function scheduleHearing(string $id): JSONResponse
    {
        $userId = $this->accessGuard->currentUid();
        if ($userId === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->accessGuard->authorizeMutation(complaint: $complaint, userId: $userId);

        try {
            $data   = $this->accessGuard->parseBody();
            $result = $this->hearingService->scheduleHearing($id, $data);
            // Transition complaint to hoorgesprek_gepland.
            $this->complaintService->transitionStatus($id, 'hoorgesprek_gepland');
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end scheduleHearing()

    /**
     * Record the outcome of a completed hearing.
     *
     * @param string $id        Complaint UUID
     * @param string $hearingId Hearing UUID
     *
     * @return JSONResponse Updated hearing
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function recordHearingOutcome(string $id, string $hearingId): JSONResponse
    {
        $userId = $this->accessGuard->currentUid();
        if ($userId === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->accessGuard->authorizeMutation(complaint: $complaint, userId: $userId);

        try {
            $data   = $this->accessGuard->parseBody();
            $result = $this->hearingService->recordOutcome($hearingId, $data);
            // Transition complaint to hoorgesprek_afgerond.
            $this->complaintService->transitionStatus($id, 'hoorgesprek_afgerond');
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end recordHearingOutcome()
}//end class
