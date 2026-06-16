<?php

/**
 * Procest MandaatMatrixController.
 *
 * REST surface for the mandaat-matrix backend: authorization checks,
 * import, escalation approve/reject/list, audit-trail retrieval.
 * Defers all business logic to the services in lib/Service/Mandaat*
 * (ADR-022). Per-object IDOR guards live in the services themselves
 * (e.g. {@see MandaatEscalatieService::approveEscalatie} re-checks
 * that the caller is the resolved mandate holder).
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
 * @spec openspec/changes/mandaat-matrix-09-tests-and-docs/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\MandaatCheckService;
use OCA\Procest\Service\MandaatEscalatieService;
use OCA\Procest\Service\MandaatGebruikService;
use OCA\Procest\Service\MandaatImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for the mandaat-matrix backend.
 *
 * @psalm-suppress UnusedClass
 */
class MandaatMatrixController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                  $appName       App id.
     * @param IRequest                $request       Request.
     * @param IUserSession            $userSession   User session (for current user id).
     * @param MandaatCheckService     $check         Check service.
     * @param MandaatEscalatieService $escalatie     Escalation service.
     * @param MandaatGebruikService   $gebruik       Audit log service.
     * @param MandaatImportService    $import        Import service.
     * @param LoggerInterface         $logger        Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly MandaatCheckService $check,
        private readonly MandaatEscalatieService $escalatie,
        private readonly MandaatGebruikService $gebruik,
        private readonly MandaatImportService $import,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Per-object authorization guard.
     *
     * @return JSONResponse|null
     */
    private function ensureAuthenticated(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }//end ensureAuthenticated()

    /**
     * Authorization probe — UI can call this before submitting a decision.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
     */
    public function probe(): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) { return $denied; }
        $body         = $this->jsonBody();
        $decisionType = (string) ($body['decisionType'] ?? '');
        $caseId       = (string) ($body['caseId'] ?? '');
        $caseProps    = (array)  ($body['caseProperties'] ?? []);
        if ($decisionType === '' || $caseId === '') {
            return $this->badRequest('decisionType and caseId are required');
        }

        $userId = $this->currentUserId();
        $r = $this->check->isAuthorized($userId, $decisionType, $caseId, $caseProps);
        return new JSONResponse($r);
    }//end probe()

    /**
     * Import a CSV of mandaten under a new MandateringsBesluit.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
     */
    public function importPreview(): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) { return $denied; }
        $body          = $this->jsonBody();
        $besluitNummer = (string) ($body['besluitNummer'] ?? '');
        $besluitNaam   = (string) ($body['besluitNaam'] ?? '');
        $decideskUuid  = (string) ($body['decideskUuid'] ?? '');
        $csv           = (string) ($body['csv'] ?? '');
        if ($besluitNummer === '' || $besluitNaam === '' || $csv === '') {
            return $this->badRequest('besluitNummer, besluitNaam and csv are required');
        }

        try {
            $r = $this->import->importFromCsv($besluitNummer, $besluitNaam, $decideskUuid, $csv);
            return new JSONResponse($r, Http::STATUS_CREATED);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end importPreview()

    /**
     * Approve a previously-imported (concept) besluit.
     *
     * @NoAdminRequired
     *
     * @param string $importId Besluit id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
     */
    public function importApprove(string $importId): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) { return $denied; }
        try {
            $r = $this->import->approveImport($importId);
            return new JSONResponse($r);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end importApprove()

    /**
     * Approve an open escalation.
     *
     * @NoAdminRequired
     *
     * @param string $id Escalation id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
     */
    public function escalateApprove(string $id): JSONResponse
    {
        $userId = $this->currentUserId();
        try {
            $r = $this->escalatie->approveEscalatie($id, $userId);
            return new JSONResponse($r);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }//end escalateApprove()

    /**
     * Reject an open escalation.
     *
     * @NoAdminRequired
     *
     * @param string $id Escalation id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
     */
    public function escalateReject(string $id): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) { return $denied; }
        $body   = $this->jsonBody();
        $reason = (string) ($body['reason'] ?? '');
        if ($reason === '') {
            return $this->badRequest('reason is required');
        }
        try {
            $r = $this->escalatie->rejectEscalatie($id, $reason);
            return new JSONResponse($r);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end escalateReject()

    /**
     * Get the decision audit trail for a case.
     *
     * @NoAdminRequired
     *
     * @param string $caseId Case id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
     */
    public function auditTrail(string $caseId): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) { return $denied; }
        return new JSONResponse($this->gebruik->getDecisionAuditTrail($caseId));
    }//end auditTrail()

    /**
     * Applicable mandates for the case, filtered to the current user's roles.
     *
     * @NoAdminRequired
     *
     * @param string $caseId Case id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
     */
    public function applicable(string $caseId): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }
        $userId   = $this->currentUserId();
        $caseType = (string) $this->request->getParam('caseType', '');
        $decisionType = (string) $this->request->getParam('decisionType', '');
        try {
            $rows = $this->check->getApplicableForUser($userId, $caseType, $decisionType);
        } catch (Throwable $e) {
            $this->logger->warning(
                'MandaatMatrixController.applicable failed',
                ['caseId' => $caseId, 'error' => $e->getMessage()],
            );
            $rows = [];
        }
        return new JSONResponse($rows);
    }//end applicable()

    /**
     * @return string
     */
    private function currentUserId(): string
    {
        $user = $this->userSession->getUser();
        return $user !== null ? (string) $user->getUID() : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        // OCP\IRequest::getContent() is protected on the concrete OC
        // request; read raw payload from php://input instead.
        $raw = (string) file_get_contents('php://input');
        $body = json_decode($raw, true);
        return is_array($body) === true ? $body : [];
    }

    /**
     * @param string $msg Message.
     * @return JSONResponse
     */
    private function badRequest(string $msg): JSONResponse
    {
        return new JSONResponse(['message' => $msg], Http::STATUS_BAD_REQUEST);
    }
}//end class
