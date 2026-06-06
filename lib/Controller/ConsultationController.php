<?php

/**
 * Procest Consultation Controller
 *
 * REST API for inter-departmental consultation management. Provides CRUD,
 * lifecycle transitions, advisory body search, deadline extension, and a
 * token-based public endpoint for external body responses per Awb 3:5-3:9.
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
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AdvisoryBodyService;
use OCA\Procest\Service\ConsultationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for consultation (adviesaanvraag) management.
 *
 * Mutation endpoints carry the NoAdminRequired annotation and apply an
 * authorizeConsultationAccess() guard (OWASP A01:2021, ADR-005 Rule 3).
 * The publicResponse endpoints carry PublicPage + NoCSRFRequired — access
 * is logged for BIO compliance via LoggerInterface.
 */
class ConsultationController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName             The app name
     * @param IRequest            $request             The request
     * @param ConsultationService $consultationService The consultation service
     * @param AdvisoryBodyService $advisoryBodyService The advisory body service
     * @param IUserSession        $userSession         The user session
     * @param IGroupManager       $groupManager        The group manager
     * @param LoggerInterface     $logger              The logger for BIO audit events
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ConsultationService $consultationService,
        private readonly AdvisoryBodyService $advisoryBodyService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List consultations for a case.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse List of consultations
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function index(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $consultations = $this->consultationService->getConsultationsForCase(caseId: $caseId);
        return new JSONResponse(['results' => $consultations]);
    }//end index()

    /**
     * Get a single consultation by ID.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse The consultation data or 404
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function show(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $consultation = $this->consultationService->getConsultation(consultationId: $id);
        if ($consultation === null) {
            return new JSONResponse(['error' => 'Consultation not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($consultation);
    }//end show()

    /**
     * Create a new consultation.
     *
     * @return JSONResponse Created consultation with HTTP 201
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $data = $this->getRequestBody();
            $data['aanvrager'] = $user->getUID();

            $dependsOn = $data['dependsOn'] ?? [];
            if (is_array($dependsOn) === true && empty($dependsOn) === false) {
                if ($this->consultationService->validateDependencyCycle(
                    consultationId: '',
                    dependsOn: $dependsOn,
                ) === true
                ) {
                    return new JSONResponse(
                        ['error' => 'Dependency cycle detected in dependsOn list'],
                        Http::STATUS_BAD_REQUEST,
                    );
                }
            }

            $result = $this->consultationService->createConsultation(data: $data);
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }//end try
    }//end create()

    /**
     * Update consultation status.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Updated consultation or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function updateStatus(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $consultation = $this->consultationService->getConsultation(consultationId: $id);
        if ($consultation === null) {
            return new JSONResponse(['error' => 'Consultation not found'], Http::STATUS_NOT_FOUND);
        }

        $authError = $this->authorizeConsultationAccess(consultation: $consultation, uid: $user->getUID());
        if ($authError !== null) {
            return $authError;
        }

        try {
            $data   = $this->getRequestBody();
            $status = $data['status'] ?? '';
            $result = $this->consultationService->updateStatus(
                consultationId: $id,
                newStatus: $status,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end updateStatus()

    /**
     * Submit advice response.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Updated consultation or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function submitResponse(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $consultation = $this->consultationService->getConsultation(consultationId: $id);
        if ($consultation === null) {
            return new JSONResponse(['error' => 'Consultation not found'], Http::STATUS_NOT_FOUND);
        }

        $authError = $this->authorizeConsultationAccess(consultation: $consultation, uid: $user->getUID());
        if ($authError !== null) {
            return $authError;
        }

        try {
            $data   = $this->getRequestBody();
            $result = $this->consultationService->submitResponse(
                consultationId: $id,
                response: $data,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end submitResponse()

    /**
     * Delete a consultation.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Empty 204 on success or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function delete(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $consultation = $this->consultationService->getConsultation(consultationId: $id);
        if ($consultation === null) {
            return new JSONResponse(['error' => 'Consultation not found'], Http::STATUS_NOT_FOUND);
        }

        $authError = $this->authorizeConsultationAccess(consultation: $consultation, uid: $user->getUID());
        if ($authError !== null) {
            return $authError;
        }

        try {
            $this->consultationService->deleteConsultation(consultationId: $id);
            return new JSONResponse([], Http::STATUS_NO_CONTENT);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end delete()

    /**
     * Get overdue consultations.
     *
     * @return JSONResponse List of overdue consultations
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function overdue(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $overdue = $this->consultationService->getOverdueConsultations();
        return new JSONResponse(['results' => $overdue]);
    }//end overdue()

    /**
     * Request a deadline extension for a consultation.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Updated consultation summary or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function requestExtension(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $consultation = $this->consultationService->getConsultation(consultationId: $id);
        if ($consultation === null) {
            return new JSONResponse(['error' => 'Consultation not found'], Http::STATUS_NOT_FOUND);
        }

        $authError = $this->authorizeConsultationAccess(consultation: $consultation, uid: $user->getUID());
        if ($authError !== null) {
            return $authError;
        }

        try {
            $data          = $this->getRequestBody();
            $justification = $data['justification'] ?? '';
            $result        = $this->consultationService->requestExtension(
                consultationId: $id,
                justification: $justification,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end requestExtension()

    /**
     * Approve a deadline extension for a consultation.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Updated consultation summary or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function approveExtension(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $consultation = $this->consultationService->getConsultation(consultationId: $id);
        if ($consultation === null) {
            return new JSONResponse(['error' => 'Consultation not found'], Http::STATUS_NOT_FOUND);
        }

        $authError = $this->authorizeConsultationAccess(consultation: $consultation, uid: $user->getUID());
        if ($authError !== null) {
            return $authError;
        }

        try {
            $data        = $this->getRequestBody();
            $newDeadline = $data['newDeadline'] ?? '';
            $result      = $this->consultationService->approveExtension(
                consultationId: $id,
                newDeadline: $newDeadline,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end approveExtension()

    /**
     * List all advisory bodies.
     *
     * @return JSONResponse List of advisory bodies
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function listAdvisoryBodies(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $bodies = $this->advisoryBodyService->findAll();
        return new JSONResponse(['results' => $bodies]);
    }//end listAdvisoryBodies()

    /**
     * Search advisory bodies by specialization tag.
     *
     * @return JSONResponse Ranked list of advisory bodies
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function searchAdvisoryBodies(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $query  = (string) ($this->request->getParam('q') ?? '');
        $bodies = $this->advisoryBodyService->searchBySpecialization(query: $query);
        return new JSONResponse(['results' => $bodies]);
    }//end searchAdvisoryBodies()

    /**
     * GET endpoint for external body access via secure token.
     *
     * Returns consultation details for the external advisory body.
     * Access is logged for BIO compliance (Baseline Informatiebeveiliging Overheid).
     *
     * @param string $token The secure access token
     *
     * @return JSONResponse Consultation data or error
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function publicResponseGet(string $token): JSONResponse
    {
        if (empty($token) === true) {
            return new JSONResponse(['error' => 'Token is required'], Http::STATUS_BAD_REQUEST);
        }

        $consultation = $this->consultationService->findBySecureToken(token: $token);
        if ($consultation === null) {
            return new JSONResponse(['error' => 'Invalid or expired token'], Http::STATUS_NOT_FOUND);
        }

        $this->logger->info(
            'Procest BIO: external consultation access via token (GET)',
            [
                'app'            => Application::APP_ID,
                'consultationId' => $consultation['id'] ?? '',
                'tokenPrefix'    => substr($token, 0, 8).'...',
            ],
        );

        return new JSONResponse($consultation);
    }//end publicResponseGet()

    /**
     * POST endpoint for external body to submit advice response via secure token.
     *
     * Access is logged for BIO compliance.
     *
     * @param string $token The secure access token
     *
     * @return JSONResponse Updated consultation or error
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
     */
    public function publicResponsePost(string $token): JSONResponse
    {
        if (empty($token) === true) {
            return new JSONResponse(['error' => 'Token is required'], Http::STATUS_BAD_REQUEST);
        }

        $consultation = $this->consultationService->findBySecureToken(token: $token);
        if ($consultation === null) {
            return new JSONResponse(['error' => 'Invalid or expired token'], Http::STATUS_NOT_FOUND);
        }

        $consultationId = $consultation['id'] ?? '';

        $this->logger->info(
            'Procest BIO: external consultation response submitted via token (POST)',
            [
                'app'            => Application::APP_ID,
                'consultationId' => $consultationId,
                'tokenPrefix'    => substr($token, 0, 8).'...',
            ],
        );

        try {
            $data   = $this->getRequestBody();
            $result = $this->consultationService->submitResponse(
                consultationId: $consultationId,
                response: $data,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end publicResponsePost()

    /**
     * Check whether the user is authorized to access or mutate a consultation.
     *
     * A user is authorized when they are the aanvrager (original requestor),
     * the assignee (individual handler), or an administrator.
     * Returns null when authorized, or a 403 JSONResponse when not.
     *
     * @param array<string, mixed> $consultation The consultation data
     * @param string               $uid          The user's UID
     *
     * @return JSONResponse|null Null when authorized, 403 response when denied
     */
    private function authorizeConsultationAccess(array $consultation, string $uid): ?JSONResponse
    {
        if ($this->groupManager->isAdmin($uid) === true) {
            return null;
        }

        $aanvrager = $consultation['aanvrager'] ?? '';
        $assignee  = $consultation['assignee'] ?? '';

        if ($uid === $aanvrager || ($assignee !== '' && $uid === $assignee)) {
            return null;
        }

        return new JSONResponse(
            ['error' => 'Access to this consultation is not permitted'],
            Http::STATUS_FORBIDDEN,
        );
    }//end authorizeConsultationAccess()

    /**
     * Parse the request body as JSON and return as array.
     *
     * @return array<string, mixed>
     */
    private function getRequestBody(): array
    {
        $content = $this->request->getContent();
        if ($content === '' || $content === false) {
            $content = '{}';
        }

        $decoded = json_decode((string) $content, true);
        return is_array($decoded) === true ? $decoded : [];
    }//end getRequestBody()
}//end class
