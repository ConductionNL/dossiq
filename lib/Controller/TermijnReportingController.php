<?php

/**
 * Procest TermijnReportingController.
 *
 * REST surface for the termijnbewaking reporting endpoints (dashboard
 * KPI, kwartaalrapport, jaarrekening dwangsommen). Defers all logic to
 * {@see TermijnReportingService} (ADR-022).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\TermijnReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reporting REST surface.
 *
 * @psalm-suppress UnusedClass
 */
class TermijnReportingController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                  $appName     App id.
     * @param IRequest                $request     Request.
     * @param TermijnReportingService $service     Reporting service.
     * @param IUserSession            $userSession User session.
     * @param LoggerInterface         $logger      Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TermijnReportingService $service,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
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
     * Dashboard KPI snapshot.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
     */
    public function dashboard(): JSONResponse
    {
        $denied = $this->ensureAuthenticated();
        if ($denied !== null) {
            return $denied;
        }

        try {
            $row = $this->service->getTermijnKpi();
            return new JSONResponse($row);
        } catch (Throwable $e) {
            $this->logger->error('Termijn dashboard failed', ['error' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end dashboard()

    /**
     * Quarterly KPI report.
     *
     * @param string      $periode  Period (YYYY-Qn).
     * @param string|null $afdeling Optional department filter.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
     */
    public function kwartaalrapport(string $periode='', ?string $afdeling=null): JSONResponse
    {
        $denied = $this->ensureAuthenticated();
        if ($denied !== null) {
            return $denied;
        }

        if ($periode === '') {
            $periode = (string) $this->request->getParam('periode', '');
        }

        if ($periode === '') {
            return new JSONResponse(['message' => 'periode is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $row = $this->service->generateQuarterlyReport($periode, $afdeling);
            return new JSONResponse($row);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end kwartaalrapport()

    /**
     * Annual dwangsom audit report.
     *
     * @param int $jaar Year.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
     */
    public function jaarrekening(int $jaar=0): JSONResponse
    {
        $denied = $this->ensureAuthenticated();
        if ($denied !== null) {
            return $denied;
        }

        if ($jaar === 0) {
            $jaar = (int) $this->request->getParam('jaar', '0');
        }

        if ($jaar < 2020 || $jaar > 2100) {
            return new JSONResponse(['message' => 'jaar is required and must be between 2020 and 2100'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $row = $this->service->generateDwangsomAuditReport($jaar);
            return new JSONResponse($row);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end jaarrekening()
}//end class
