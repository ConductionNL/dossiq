<?php

/**
 * Procest Iv3ReportController
 *
 * REST surface for the IV3 (Informatie voor Derden) quarterly cost report:
 * `GET /api/reports/iv3` (JSON or CSV, gated to controllers/beheerders/
 * admin) and `GET /api/reports/iv3/taakvelden` (the taakveld reference
 * list, open to any authenticated user — it backs both the report filter
 * and the case-type settings picker). Defers all logic to
 * {@see Iv3ReportService} / {@see Iv3TaakveldList} (ADR-022).
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Iv3ReportService;
use OCA\Procest\Service\Iv3TaakveldList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * IV3 quarterly cost-report REST surface.
 *
 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#4.1
 *
 * @psalm-suppress UnusedClass
 */
class Iv3ReportController extends Controller
{
    /**
     * Groups that may read the IV3 cost report figures (same gate shape as
     * the AI/parafering audit exports — "controllers" is the literal role
     * these figures are for).
     */
    private const ALLOWED_GROUPS = ['controllers', 'beheerders', 'admin'];

    /**
     * Constructor.
     *
     * @param string           $appName       Nextcloud app id.
     * @param IRequest         $request       Incoming request.
     * @param IUserSession     $userSession   Current user session.
     * @param IGroupManager    $groupManager  Group manager (for RBAC check).
     * @param Iv3ReportService $reportService IV3 aggregation service.
     * @param Iv3TaakveldList  $taakveldList  Taakveld reference list.
     * @param LoggerInterface  $logger        PSR-3 logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly Iv3ReportService $reportService,
        private readonly Iv3TaakveldList $taakveldList,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Quarterly IV3 cost report, as JSON (default) or CSV download.
     *
     * @param int    $year    Calendar year.
     * @param int    $quarter Quarter number 1-4.
     * @param string $format  'json' (default) or 'csv'.
     *
     * @return JSONResponse|DataDownloadResponse
     *
     * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
     */
    #[NoAdminRequired]
    public function report(int $year=0, int $quarter=0, string $format='json'): JSONResponse|DataDownloadResponse
    {
        $denied = $this->ensureAllowed();
        if ($denied !== null) {
            return $denied;
        }

        [$year, $quarter] = $this->resolvePeriod(year: $year, quarter: $quarter);

        $invalid = $this->validatePeriod(year: $year, quarter: $quarter);
        if ($invalid !== null) {
            return $invalid;
        }

        try {
            $report = $this->reportService->generateQuarterlyReport(year: $year, quarter: $quarter);
        } catch (Throwable $e) {
            $this->logger->error('Procest: IV3 report generation failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Report generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if (strtolower($format) === 'csv') {
            return new DataDownloadResponse(
                data: $this->reportService->asCsv(report: $report),
                filename: sprintf('iv3-report-%d-Q%d.csv', $year, $quarter),
                contentType: 'text/csv',
            );
        }

        return new JSONResponse($report);
    }//end report()

    /**
     * Authentication + RBAC guard for {@see self::report()}.
     *
     * @return JSONResponse|null Null when the caller is allowed.
     */
    private function ensureAllowed(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->isAllowed(uid: $user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'IV3 report access requires the controller/beheerder role'],
                Http::STATUS_FORBIDDEN,
            );
        }

        return null;
    }//end ensureAllowed()

    /**
     * Fall back to request query params for year/quarter when the routed
     * arguments are absent (both default to 0).
     *
     * @param int $year    Routed year argument.
     * @param int $quarter Routed quarter argument.
     *
     * @return array{0: int, 1: int}
     */
    private function resolvePeriod(int $year, int $quarter): array
    {
        if ($year === 0) {
            $year = (int) $this->request->getParam('year', '0');
        }

        if ($quarter === 0) {
            $quarter = (int) $this->request->getParam('quarter', '0');
        }

        return [$year, $quarter];
    }//end resolvePeriod()

    /**
     * Validate the resolved year/quarter.
     *
     * @param int $year    Calendar year.
     * @param int $quarter Quarter number.
     *
     * @return JSONResponse|null Null when valid.
     */
    private function validatePeriod(int $year, int $quarter): ?JSONResponse
    {
        if ($year < 2000 || $year > 2100) {
            return new JSONResponse(['message' => 'year is required and must be between 2000 and 2100'], Http::STATUS_BAD_REQUEST);
        }

        if ($quarter < 1 || $quarter > 4) {
            return new JSONResponse(['message' => 'quarter is required and must be between 1 and 4'], Http::STATUS_BAD_REQUEST);
        }

        return null;
    }//end validatePeriod()

    /**
     * The IV3 taakveld reference list — open to any authenticated user (it
     * is a public CBS classification, not report data).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
     */
    #[NoAdminRequired]
    public function taakvelden(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            [
                'version'    => $this->taakveldList->version(),
                'taakvelden' => $this->taakveldList->allTaakvelden(),
            ]
        );
    }//end taakvelden()

    /**
     * Check whether the given user id belongs to an allowed group (or is an
     * NC admin, defensive default) — same shape as
     * {@see \OCA\Procest\Controller\AiAuditExportController::isAllowed()}.
     *
     * @param string $uid The Nextcloud user id.
     *
     * @return bool
     */
    private function isAllowed(string $uid): bool
    {
        foreach (self::ALLOWED_GROUPS as $group) {
            if ($this->groupManager->isInGroup($uid, $group) === true) {
                return true;
            }
        }

        return $this->groupManager->isAdmin($uid) === true;
    }//end isAllowed()
}//end class
