<?php

/**
 * Procest Zaakportaal Controller
 *
 * REST endpoints for the citizen-facing "Mijn gemeente" portal. Every endpoint
 * requires an authenticated portal user (DigiD/eHerkenning established at the
 * instance edge by OpenConnector and surfaced as the Nextcloud user). All reads
 * and writes are scoped to the caller's pseudonymous subject reference by the
 * underlying services, so a citizen can only ever see and act on their own
 * cases, documents, messages, requests and preferences (IDOR-safe). The raw BSN
 * / KvK number is never accepted from, nor returned to, the client.
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
 * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Zaakportaal\PortalAuditLogger;
use OCA\Procest\Service\Zaakportaal\PortalCaseService;
use OCA\Procest\Service\Zaakportaal\PortalIdentityService;
use OCA\Procest\Service\Zaakportaal\PortalMessageService;
use OCA\Procest\Service\Zaakportaal\PortalNotificationPreferenceService;
use OCA\Procest\Service\Zaakportaal\PortalRequestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;

/**
 * Controller exposing the citizen portal endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — thin facade delegating to
 * the bounded portal services; coupling is to those collaborators only.
 */
class ZaakportaalController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                            $request           The request.
     * @param PortalIdentityService               $identityService   The identity/scoping service.
     * @param PortalCaseService                   $caseService       The case read service.
     * @param PortalMessageService                $messageService    The messaging service.
     * @param PortalRequestService                $requestService    The request (bezwaar/klacht) service.
     * @param PortalNotificationPreferenceService $preferenceService The preference service.
     * @param PortalAuditLogger                   $auditLogger       The audit logger.
     */
    public function __construct(
        IRequest $request,
        private PortalIdentityService $identityService,
        private PortalCaseService $caseService,
        private PortalMessageService $messageService,
        private PortalRequestService $requestService,
        private PortalNotificationPreferenceService $preferenceService,
        private PortalAuditLogger $auditLogger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the authenticated citizen's cases.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function cases(): JSONResponse
    {
        try {
            $subjectRef = $this->identityService->currentSubjectRef();
            $cases      = $this->caseService->listForSubject($subjectRef, $this->zaaktypeScope());
            $this->auditLogger->record('case-list', $subjectRef);
        } catch (OCSBadRequestException $e) {
            return $this->error(exception: $e);
        }

        return new JSONResponse(['results' => $cases]);
    }//end cases()

    /**
     * Show one of the citizen's cases.
     *
     * @param string $id The case id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function caseDetail(string $id): JSONResponse
    {
        try {
            $subjectRef = $this->identityService->currentSubjectRef();
            $case       = $this->caseService->detailForSubject($id, $subjectRef, $this->zaaktypeScope());
            $this->auditLogger->record('case-view', $subjectRef, 'success', ['caseId' => $id]);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($case);
    }//end caseDetail()

    /**
     * List the message thread for one of the citizen's cases.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function messages(): JSONResponse
    {
        $caseId = (string) $this->request->getParam('caseId', '');

        try {
            $subjectRef = $this->identityService->currentSubjectRef();
            $thread     = $this->messageService->threadForSubject($caseId, $subjectRef);
        } catch (OCSBadRequestException $e) {
            return $this->error(exception: $e);
        }

        return new JSONResponse(['results' => $thread]);
    }//end messages()

    /**
     * Send a message to the case handler.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function sendMessage(): JSONResponse
    {
        try {
            $subjectRef = $this->identityService->currentSubjectRef();
            $message    = $this->messageService->send($this->bodyParams(), $subjectRef);
            $this->auditLogger->record('message-send', $subjectRef, 'success', ['caseId' => (string) ($message['caseId'] ?? '')]);
        } catch (OCSBadRequestException $e) {
            return $this->error(exception: $e);
        }

        return new JSONResponse($message, Http::STATUS_CREATED);
    }//end sendMessage()

    /**
     * Validate a bezwaar deadline without submitting.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function objectionDeadline(): JSONResponse
    {
        try {
            // Scoping check: caller must be an authenticated portal subject.
            $this->identityService->currentSubjectRef();
            $result = $this->requestService->validateBezwaarDeadline((string) $this->request->getParam('decisionDate', ''));
        } catch (OCSBadRequestException $e) {
            return $this->error(exception: $e);
        }

        return new JSONResponse($result);
    }//end objectionDeadline()

    /**
     * Submit a bezwaarschrift.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function submitObjection(): JSONResponse
    {
        try {
            $subjectRef = $this->identityService->currentSubjectRef();
            $verzoek    = $this->requestService->submitBezwaar($this->bodyParams(), $subjectRef);
            $this->auditLogger->record('objection-submit', $subjectRef, 'success', ['caseId' => (string) ($verzoek['tegenZaakId'] ?? '')]);
        } catch (OCSBadRequestException $e) {
            return $this->error(exception: $e);
        }

        return new JSONResponse($verzoek, Http::STATUS_CREATED);
    }//end submitObjection()

    /**
     * Submit a klacht.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function submitComplaint(): JSONResponse
    {
        try {
            $subjectRef = $this->identityService->currentSubjectRef();
            $verzoek    = $this->requestService->submitKlacht($this->bodyParams(), $subjectRef);
            $this->auditLogger->record('complaint-submit', $subjectRef);
        } catch (OCSBadRequestException $e) {
            return $this->error(exception: $e);
        }

        return new JSONResponse($verzoek, Http::STATUS_CREATED);
    }//end submitComplaint()

    /**
     * List the citizen's submitted requests (bezwaar/klacht).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function requests(): JSONResponse
    {
        try {
            $subjectRef = $this->identityService->currentSubjectRef();
            $results    = $this->requestService->listForSubject($subjectRef, (string) $this->request->getParam('soort', ''));
        } catch (OCSBadRequestException $e) {
            return $this->error(exception: $e);
        }

        return new JSONResponse(['results' => $results]);
    }//end requests()

    /**
     * Retrieve the citizen's notification preferences.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function getPreferences(): JSONResponse
    {
        try {
            $subjectRef  = $this->identityService->currentSubjectRef();
            $preferences = $this->preferenceService->getForSubject($subjectRef);
        } catch (OCSBadRequestException $e) {
            return $this->error(exception: $e);
        }

        return new JSONResponse($preferences);
    }//end getPreferences()

    /**
     * Update the citizen's notification preferences.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod

     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-11     */
    public function updatePreferences(): JSONResponse
    {
        try {
            $subjectRef  = $this->identityService->currentSubjectRef();
            $preferences = $this->preferenceService->updateForSubject($subjectRef, $this->bodyParams());
            $this->auditLogger->record('preference-update', $subjectRef);
        } catch (OCSBadRequestException $e) {
            return $this->error(exception: $e);
        }

        return new JSONResponse($preferences);
    }//end updatePreferences()

    /**
     * Read the optional machtiging zaaktype scope from the request.
     *
     * The scope is a server-trusted restriction surfaced by OpenConnector for a
     * machtiging; it can only ever narrow visibility, never widen it.
     *
     * @return array<int, string> The zaaktype scope (empty when unrestricted).
     */
    private function zaaktypeScope(): array
    {
        $scope = $this->request->getParam('zaaktypeScope', '');
        if (is_string($scope) === true && $scope !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $scope))));
        }

        return [];
    }//end zaaktypeScope()

    /**
     * Read the JSON / form body parameters, excluding routing params.
     *
     * @return array<string, mixed> The body parameters.
     */
    private function bodyParams(): array
    {
        $params = $this->request->getParams();
        unset($params['id'], $params['_route']);
        return $params;
    }//end bodyParams()

    /**
     * Build a 400 error response from an exception.
     *
     * @param OCSBadRequestException $exception The exception.
     *
     * @return JSONResponse
     */
    private function error(OCSBadRequestException $exception): JSONResponse
    {
        return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
    }//end error()
}//end class
