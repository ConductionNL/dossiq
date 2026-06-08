<?php

/**
 * Procest Leges Case Controller
 *
 * Per-case leges endpoints: read the current calculation, trigger a manual
 * (re)calculation, read the audit trail, and submit a refund. All endpoints
 * are for normal authenticated users (#[NoAdminRequired]); each verifies the
 * caller can access the referenced case before acting (IDOR-safe, ADR-005).
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
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-006
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-008
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\LegesBerekeningService;
use OCA\Procest\Service\LegesCaseCalculationService;
use OCA\Procest\Service\LegesRestitutieService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Per-case leges endpoints.
 *
 * @psalm-suppress                                 UnusedClass
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LegesCaseController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                    $request            The request.
     * @param LegesCaseCalculationService $calculationService Case calculation orchestrator.
     * @param LegesBerekeningService      $berekeningService  Calculation read/access service.
     * @param LegesRestitutieService      $restitutieService  Refund service.
     * @param IUserSession                $userSession        The user session.
     * @param LoggerInterface             $logger             The logger.
     */
    public function __construct(
        IRequest $request,
        private readonly LegesCaseCalculationService $calculationService,
        private readonly LegesBerekeningService $berekeningService,
        private readonly LegesRestitutieService $restitutieService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get the current calculation for a case.
     *
     * @param string $caseId The case UUID.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function show(string $caseId): JSONResponse
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            if ($this->berekeningService->userCanAccessCase(caseId: $caseId, userId: $userId) === false) {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $berekening = $this->berekeningService->getForCase(caseId: $caseId);
            if ($berekening === null) {
                return new JSONResponse(['error' => 'No calculation for case'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($berekening);
        } catch (\Throwable $e) {
            $this->logger->error('Procest leges show failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not load calculation'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end show()

    /**
     * Trigger a (re)calculation for a case.
     *
     * @param string $caseId The case UUID.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function calculate(string $caseId): JSONResponse
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            if ($this->berekeningService->userCanAccessCase(caseId: $caseId, userId: $userId) === false) {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $result = $this->calculationService->calculateForCase(caseId: $caseId, calculatedBy: $userId);
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error('Procest leges calculate failed: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end calculate()

    /**
     * Get the audit trail for a case calculation.
     *
     * @param string $caseId The case UUID.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function auditTrail(string $caseId): JSONResponse
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            if ($this->berekeningService->userCanAccessCase(caseId: $caseId, userId: $userId) === false) {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            return new JSONResponse($this->berekeningService->getAuditTrail(caseId: $caseId));
        } catch (\Throwable $e) {
            $this->logger->error('Procest leges audit-trail failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not load audit trail'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end auditTrail()

    /**
     * Submit a refund for a case calculation.
     *
     * @param string $caseId The case UUID.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function refund(string $caseId): JSONResponse
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $reason = (string) $this->request->getParam('reason', '');
        $fase   = (string) $this->request->getParam('fase', '');
        if ($reason === '' || $fase === '') {
            return new JSONResponse(['error' => 'reason and fase are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            if ($this->berekeningService->userCanAccessCase(caseId: $caseId, userId: $userId) === false) {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $berekening = $this->berekeningService->getForCase(caseId: $caseId);
            if ($berekening === null) {
                return new JSONResponse(['error' => 'No calculation for case'], Http::STATUS_NOT_FOUND);
            }

            $result = $this->restitutieService->createRestitutie(
                berekeningId: (string) ($berekening['id'] ?? ''),
                reason: $reason,
                fase: $fase,
                besluitNemerId: $userId
            );
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error('Procest leges refund failed: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }//end try
    }//end refund()

    /**
     * Resolve the current user id, or null when unauthenticated.
     *
     * @return string|null
     */
    private function requireUser(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end requireUser()
}//end class
