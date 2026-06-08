<?php

/**
 * Procest Complaint Controller
 *
 * REST API for complaint (klacht) management per Awb chapter 9.
 * Exposes endpoints for complaints, hearings, dispositions, escalation,
 * and analytics.
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

use OCA\Procest\Service\ComplaintAnalyticsService;
use OCA\Procest\Service\ComplaintService;
use OCA\Procest\Service\DispositionService;
use OCA\Procest\Service\HearingService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for complaint (klacht) management.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */
class ComplaintController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                    $appName                   App name
     * @param IRequest                  $request                   Request
     * @param ComplaintService          $complaintService          Complaint service
     * @param HearingService            $hearingService            Hearing service
     * @param DispositionService        $dispositionService        Disposition service
     * @param ComplaintAnalyticsService $complaintAnalyticsService Analytics service
     * @param SettingsService           $settingsService           Settings service
     * @param IUserSession              $userSession               User session
     * @param IGroupManager             $groupManager              Group manager (admin checks)
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ComplaintService $complaintService,
        private readonly HearingService $hearingService,
        private readonly DispositionService $dispositionService,
        private readonly ComplaintAnalyticsService $complaintAnalyticsService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $filters = [];
        $status  = $this->request->getParam('status');
        if ($status !== null) {
            $filters['status'] = $status;
        }

        $handler = $this->request->getParam('behandelaar');
        if ($handler !== null) {
            $filters['behandelaar'] = $handler;
        }

        $categorie = $this->request->getParam('categorie');
        if ($categorie !== null) {
            $filters['categorie'] = $categorie;
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $data = $this->parseBody();

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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeComplaintAccess(complaint: $complaint, userId: $user->getUID());

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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeComplaintMutation(complaint: $complaint, userId: $user->getUID());

        try {
            $data   = $this->parseBody();
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeComplaintMutation(complaint: $complaint, userId: $user->getUID());

        try {
            $data      = $this->parseBody();
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeComplaintMutation(complaint: $complaint, userId: $user->getUID());

        try {
            $data         = $this->parseBody();
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeComplaintMutation(complaint: $complaint, userId: $user->getUID());

        try {
            $data   = $this->parseBody();
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
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $warningDays = (int) ($this->request->getParam('warningDays') ?? 3);
        $alerts      = $this->complaintService->getDeadlineAlerts($warningDays);
        return new JSONResponse($alerts);
    }//end deadlineAlerts()

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
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeComplaintMutation(complaint: $complaint, userId: $user->getUID());

        try {
            $data   = $this->parseBody();
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeComplaintMutation(complaint: $complaint, userId: $user->getUID());

        try {
            $data   = $this->parseBody();
            $result = $this->hearingService->recordOutcome($hearingId, $data);
            // Transition complaint to hoorgesprek_afgerond.
            $this->complaintService->transitionStatus($id, 'hoorgesprek_afgerond');
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end recordHearingOutcome()

    /**
     * Get the disposition for a complaint.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Disposition or 404
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function getDisposition(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $disposition = $this->dispositionService->getDispositionForComplaint($id);
        if ($disposition === null) {
            return new JSONResponse(['error' => 'No disposition found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($disposition);
    }//end getDisposition()

    /**
     * Submit a disposition for a complaint.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Created disposition
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function submitDisposition(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeComplaintMutation(complaint: $complaint, userId: $user->getUID());

        try {
            $data            = $this->parseBody();
            $approvalSetting = $this->settingsService->getConfigValue('complaint_require_approval');
            $requireApproval = in_array(strtolower($approvalSetting), ['1', 'true', 'yes'], true);
            $disposition     = $this->dispositionService->submitDisposition($id, $data, $requireApproval);

            if ($requireApproval === false) {
                $this->complaintService->transitionStatus($id, 'afgehandeld');
            }

            return new JSONResponse($disposition, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end submitDisposition()

    /**
     * Approve a disposition (coordinator endpoint).
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Updated disposition
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function approveDisposition(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Only coordinators (admins) may approve.
        $this->requireCoordinator(userId: $user->getUID());

        try {
            $disposition = $this->dispositionService->getDispositionForComplaint($id);
            if ($disposition === null) {
                return new JSONResponse(['error' => 'No disposition found for complaint'], Http::STATUS_NOT_FOUND);
            }

            $dispositionId = $disposition['id'] ?? $disposition['uuid'] ?? '';
            $result        = $this->dispositionService->approveDisposition($dispositionId, $user->getUID());
            $this->complaintService->transitionStatus($id, 'afgehandeld');
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end approveDisposition()

    /**
     * Generate a formal response letter for a complaint.
     *
     * @param string $id Complaint UUID
     *
     * @return JSONResponse Letter generation result
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function generateLetter(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $complaint = $this->complaintService->getComplaint($id);
        if ($complaint === null) {
            return new JSONResponse(['error' => 'Complaint not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeComplaintMutation(complaint: $complaint, userId: $user->getUID());

        $disposition = $this->dispositionService->getDispositionForComplaint($id);
        if ($disposition === null) {
            return new JSONResponse(['error' => 'Submit disposition before generating a letter'], Http::STATUS_BAD_REQUEST);
        }

        $dispositionId = $disposition['id'] ?? $disposition['uuid'] ?? '';
        $result        = $this->dispositionService->generateResponseLetter($id, $dispositionId);
        return new JSONResponse($result);
    }//end generateLetter()

    /**
     * Get complaint frequency analytics.
     *
     * @return JSONResponse Analytics data
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function analytics(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $dateFrom = $this->request->getParam('dateFrom') ?? date('Y-01-01');
        $dateTo   = $this->request->getParam('dateTo') ?? date('Y-m-d');

        $byCategorie    = $this->complaintAnalyticsService->getFrequencyByDimension(
            dimension: 'categorie',
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
        $byAfdeling     = $this->complaintAnalyticsService->getFrequencyByDimension(
            dimension: 'betrokkenAfdeling',
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
        $byKanaal       = $this->complaintAnalyticsService->getFrequencyByDimension(
            dimension: 'ontvangstkanaal',
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
        $monthlyTrend   = $this->complaintAnalyticsService->getMonthlyTrend(dateFrom: $dateFrom, dateTo: $dateTo);
        $avgResolution  = $this->complaintAnalyticsService->getAverageResolutionTime(dateFrom: $dateFrom, dateTo: $dateTo);
        $employeeAlerts = $this->complaintAnalyticsService->checkEmployeeThresholdAlerts();

        return new JSONResponse(
                [
                    'byCategorie'    => $byCategorie,
                    'byAfdeling'     => $byAfdeling,
                    'byKanaal'       => $byKanaal,
                    'monthlyTrend'   => $monthlyTrend,
                    'avgResolution'  => $avgResolution,
                    'employeeAlerts' => $employeeAlerts,
                ]
                );
    }//end analytics()

    /**
     * Get KPI cards for management dashboard.
     *
     * @return JSONResponse KPI data
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function kpi(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $dateFrom = $this->request->getParam('dateFrom') ?? date('Y-m-01');
        $dateTo   = $this->request->getParam('dateTo') ?? date('Y-m-d');

        $kpi = $this->complaintAnalyticsService->getKpiSummary($dateFrom, $dateTo);
        return new JSONResponse($kpi);
    }//end kpi()

    /**
     * List complaint categories.
     *
     * @return JSONResponse List of categories
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function categories(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['results' => []]);
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('complaint_category_schema');

        if (empty($register) === true || empty($schema) === true) {
            return new JSONResponse(['results' => []]);
        }

        $results = $objectService->findObjects($register, $schema, ['_limit' => 200]);
        $list    = [];
        if (is_array($results) === true) {
            $list = $results;
        }

        return new JSONResponse(['results' => $list]);
    }//end categories()

    /**
     * Create a complaint category (admin only).
     *
     * @return JSONResponse Created category
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function createCategory(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->requireCoordinator(userId: $user->getUID());

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $data     = $this->parseBody();
            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('complaint_category_schema');
            $category = $objectService->saveObject(object: $data, register: $register, schema: $schema);

            if (is_array($category) === true) {
                return new JSONResponse($category, Http::STATUS_CREATED);
            }

            return new JSONResponse(array_merge($data, ['id' => $category->getUuid()]), Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end createCategory()

    /**
     * Update a complaint category (admin only).
     *
     * @param string $id Category UUID
     *
     * @return JSONResponse Updated category
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    public function updateCategory(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->requireCoordinator(userId: $user->getUID());

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $data     = $this->parseBody();
            $register = $this->settingsService->getConfigValue('register');
            $schema   = $this->settingsService->getConfigValue('complaint_category_schema');
            $result   = $objectService->saveObject(object: $data, register: $register, schema: $schema, uuid: (string) $id);

            if (is_array($result) === true) {
                return new JSONResponse($result);
            }

            return new JSONResponse(array_merge($data, ['id' => $id]));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end updateCategory()

    /**
     * Parse JSON request body.
     *
     * @return array<string, mixed> Decoded request body
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    private function parseBody(): array
    {
        $params = $this->request->getParams();
        if (is_array($params) === true && empty($params) === false) {
            return $params;
        }

        return [];
    }//end parseBody()

    /**
     * Authorize access to a complaint for the given user.
     *
     * Read access is granted to the behandelaar or any authenticated user
     * (coordinators see all); write access is narrower (authorizeComplaintMutation).
     *
     * @param array<string, mixed> $complaint Complaint data
     * @param string               $userId    NC user ID
     *
     * @return void
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    private function authorizeComplaintAccess(array $complaint, string $userId): void
    {
        // Read access is broadly allowed for authenticated users: the behandelaar
        // and coordinators/admins can always read. The complaint and userId are
        // retained in the signature so this guard can be tightened later without
        // touching every call site.
        unset($complaint, $userId);
    }//end authorizeComplaintAccess()

    /**
     * Authorize mutation of a complaint for the given user.
     *
     * Only the assigned behandelaar or an admin/coordinator may mutate.
     *
     * @param array<string, mixed> $complaint Complaint data
     * @param string               $userId    NC user ID
     *
     * @return void
     *
     * @throws OCSForbiddenException If not authorized
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    private function authorizeComplaintMutation(array $complaint, string $userId): void
    {
        $behandelaar = $complaint['behandelaar'] ?? null;

        // The behandelaar or any admin may mutate.
        if ($behandelaar !== null && $behandelaar === $userId) {
            return;
        }

        // Admins can always mutate.
        $isAdmin = $this->groupManager->isAdmin($userId);
        if ($isAdmin === true) {
            return;
        }

        // If no behandelaar assigned yet, any authenticated case worker may mutate.
        if ($behandelaar === null || $behandelaar === '') {
            return;
        }

        throw new OCSForbiddenException('Not authorized to modify this complaint');
    }//end authorizeComplaintMutation()

    /**
     * Require the current user to be a coordinator (admin).
     *
     * @param string $userId NC user ID
     *
     * @return void
     *
     * @throws OCSForbiddenException If not a coordinator
     *
     * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
     */
    private function requireCoordinator(string $userId): void
    {
        $isAdmin = $this->groupManager->isAdmin($userId);
        if ($isAdmin === false) {
            throw new OCSForbiddenException('This action requires coordinator (admin) privileges');
        }
    }//end requireCoordinator()
}//end class
