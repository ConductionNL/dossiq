<?php

/**
 * Procest Complaint Controller
 *
 * REST API for complaint (klacht) management per Awb chapter 9.
 * Exposes the core complaint lifecycle: listing, creation, retrieval, update,
 * status transition, verdaging and escalation, plus deadline alerts.
 *
 * The surrounding sub-domains live on their own controllers:
 * {@see ComplaintHearingController} (hoorgesprekken),
 * {@see ComplaintDispositionController} (afdoeningen),
 * {@see ComplaintAnalyticsController} (reporting) and
 * {@see ComplaintCategoryController} (category reference list). All five share
 * {@see ComplaintAccessGuard} for authorization (ADR-022).
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Complaint\ComplaintAccessGuard;
use OCA\Procest\Service\ComplaintService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for the core complaint (klacht) lifecycle.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */
class ComplaintController extends Controller
{
    /**
     * Constructor.
     *
     * @param string               $appName          App name
     * @param IRequest             $request          Request
     * @param ComplaintService     $complaintService Complaint service
     * @param ComplaintAccessGuard $accessGuard      Shared complaint authorization guard
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ComplaintService $complaintService,
        private readonly ComplaintAccessGuard $accessGuard,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List complaints with optional filters.
     *
     * @return JSONResponse List of complaints
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function index(): JSONResponse
    {
        if ($this->accessGuard->currentUid() === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $filters = [];
        foreach (['status', 'behandelaar', 'categorie'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) {
                $filters[$key] = $value;
            }
        }

        $complaints = $this->complaintService->listComplaints($filters);
        return new JSONResponse(['results' => $complaints, 'count' => count($complaints)]);
    }//end index()

    /**
     * Create a new complaint.
     *
     * @return JSONResponse Created complaint
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function create(): JSONResponse
    {
        if ($this->accessGuard->currentUid() === '') {
            return $this->accessGuard->notAuthenticated();
        }

        try {
            $data = $this->accessGuard->parseBody();

            // Authorize: any authenticated user can create a complaint.
            $complaint = $this->complaintService->createComplaint($data);
            return new JSONResponse($complaint, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end create()

    /**
     * Get a single complaint by ID.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Complaint or 404
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function show(string $id): JSONResponse
    {
        $userId = $this->accessGuard->currentUid();
        if ($userId === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->accessGuard->authorizeAccess(complaint: $complaint, userId: $userId);

        return new JSONResponse($complaint);
    }//end show()

    /**
     * Update a complaint.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Updated complaint
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function update(string $id): JSONResponse
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
            $result = $this->complaintService->updateComplaint($id, $data);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end update()

    /**
     * Transition a complaint to a new status.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Updated complaint
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function transition(string $id): JSONResponse
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
            $data      = $this->accessGuard->parseBody();
            $newStatus = $data['status'] ?? '';
            $result    = $this->complaintService->transitionStatus($id, $newStatus);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end transition()

    /**
     * Request a deadline extension (verdaging) for a complaint.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Updated complaint
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function verdaging(string $id): JSONResponse
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
            $data         = $this->accessGuard->parseBody();
            $justificatie = $data['justificatie'] ?? '';
            $result       = $this->complaintService->requestVerdaging($id, $justificatie);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end verdaging()

    /**
     * Escalate a complaint to a formal case.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Updated complaint with linked case
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function escalate(string $id): JSONResponse
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
            $caseId = $data['caseId'] ?? '';

            if (empty($caseId) === true) {
                return new JSONResponse(['error' => 'caseId is required for escalation'], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->complaintService->linkEscalatedCase($id, $caseId);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end escalate()

    /**
     * Get complaints approaching or past their deadlines.
     *
     * @return JSONResponse Overdue and warning complaints
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function deadlineAlerts(): JSONResponse
    {
        if ($this->accessGuard->currentUid() === '') {
            return $this->accessGuard->notAuthenticated();
        }

        $warningDays = (int) ($this->request->getParam('warningDays') ?? 3);
        $alerts      = $this->complaintService->getDeadlineAlerts($warningDays);
        return new JSONResponse($alerts);
    }//end deadlineAlerts()
}//end class
