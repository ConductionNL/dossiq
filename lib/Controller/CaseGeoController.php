<?php

/**
 * Procest Case-Geo Controller
 *
 * Serves the cases-on-map dashboard data at `GET /api/cases/geo`: a clustered,
 * filtered GeoJSON FeatureCollection of case locations. Filtering is by
 * zaaktype, status and bounding box; clustering is applied server-side below a
 * zoom threshold (see {@see GeoService}).
 *
 * Authorisation: `#[NoAdminRequired]` (any authenticated user) PLUS a
 * per-object access guard — the controller resolves the set of cases the
 * current user may read via {@see CaseSharingService::canUserAccessCase()} and
 * passes that allow-list into the service, so a user never sees the location of
 * a case they cannot access (no IDOR).
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
 * @spec openspec/specs/gis-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\GeoService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller exposing clustered case locations for the cases-on-map view.
 *
 * @spec openspec/specs/gis-integration/spec.md
 *
 * @psalm-suppress UnusedClass
 */
class CaseGeoController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request            The request.
     * @param GeoService         $geoService         Clustered case-geo assembler.
     * @param CaseSharingService $caseSharingService Per-object access resolver.
     * @param IUserSession       $userSession        User session for the access guard.
     * @param LoggerInterface    $logger             Logger.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly GeoService $geoService,
        private readonly CaseSharingService $caseSharingService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return clustered case locations as a GeoJSON FeatureCollection.
     *
     * Query parameters:
     *   - zaaktype: filter by case type (optional)
     *   - status:   filter by case status (optional)
     *   - zoom:     map zoom level driving clustering (optional, default 0)
     *   - bounds:   "minLon,minLat,maxLon,maxLat" WGS84 bbox filter (optional)
     *
     * @return JSONResponse GeoJSON FeatureCollection with total/filtered metadata.
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/specs/gis-integration/spec.md
     */
    #[NoAdminRequired]
    public function geo(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $zaaktype = $this->stringParamOrNull(key: 'zaaktype');
        $status   = $this->stringParamOrNull(key: 'status');
        $zoom     = (int) $this->request->getParam('zoom', 0);

        $bbox      = null;
        $boundsRaw = $this->request->getParam('bounds', null);
        if ($boundsRaw !== null && $boundsRaw !== '') {
            $parts = array_map('floatval', explode(',', (string) $boundsRaw));
            if (count($parts) === 4) {
                $bbox = $parts;
            }
        }

        // Per-object access guard: resolve the case ids the user may read and
        // restrict the map output to that set (no IDOR).
        $readableCaseIds = $this->resolveReadableCaseIds(userId: $user->getUID());

        try {
            $collection = $this->geoService->buildCaseGeoCollection(
                [
                    'zaaktype'        => $zaaktype,
                    'status'          => $status,
                    'bbox'            => $bbox,
                    'zoom'            => $zoom,
                    'readableCaseIds' => $readableCaseIds,
                ]
            );
        } catch (Throwable $e) {
            // Degrade gracefully — the map should render even when data fails.
            $this->logger->error(
                'CaseGeoController::geo failed: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return new JSONResponse(
                ['type' => 'FeatureCollection', 'features' => [], 'total' => 0, 'filtered' => 0],
                Http::STATUS_OK
            );
        }//end try

        return new JSONResponse($collection, Http::STATUS_OK);
    }//end geo()

    /**
     * Resolve the distinct case ids the given user may read.
     *
     * @param string $userId The current user's id.
     *
     * @return array<int, string> Readable case ids (empty when none).
     */
    private function resolveReadableCaseIds(string $userId): array
    {
        $candidateIds = $this->geoService->listCaseIds();

        $readable = [];
        foreach ($candidateIds as $caseId) {
            if ($this->caseSharingService->canUserAccessCase($caseId, $userId) === true) {
                $readable[] = $caseId;
            }
        }

        return $readable;
    }//end resolveReadableCaseIds()

    /**
     * Read a request param as a non-empty string, or null.
     *
     * @param string $key The param name.
     *
     * @return string|null The value, or null when absent/empty.
     */
    private function stringParamOrNull(string $key): ?string
    {
        $raw = $this->request->getParam($key, null);
        if ($raw === null || $raw === '') {
            return null;
        }

        return (string) $raw;
    }//end stringParamOrNull()
}//end class
