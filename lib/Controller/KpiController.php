<?php

/**
 * Procest KPI Controller
 *
 * Exposes pre-aggregated dashboard KPI data for the authenticated user.
 * Responses are cached per-user using a version-bump invalidation strategy
 * to ensure fresh data after any OpenRegister object mutation.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use DateTime;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\KpiAggregationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the dashboard KPI aggregation endpoint.
 *
 * Cache strategy: per-user data key versioned via a separate version counter.
 * The `KpiCacheInvalidationListener` increments the version counter on any
 * OpenRegister object event, causing the next GET to compute fresh data.
 *
 * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T09
 */
class KpiController extends Controller
{

    /**
     * Cache TTL for computed KPI data in seconds.
     */
    private const CACHE_TTL = 60;

    /**
     * Cache prefix for KPI data keys.
     */
    private const CACHE_PREFIX = 'procest_kpis_';

    /**
     * Cache key suffix for the version counter.
     */
    private const VERSION_SUFFIX = '_ver';

    /**
     * The local cache instance.
     *
     * @var ICache The local APCu cache
     */
    private ICache $cache;

    /**
     * Constructor.
     *
     * @param IRequest              $request        The HTTP request
     * @param IUserSession          $userSession    The user session
     * @param KpiAggregationService $kpiAggregation The KPI aggregation service
     * @param ICacheFactory         $cacheFactory   The cache factory
     * @param LoggerInterface       $logger         Logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private KpiAggregationService $kpiAggregation,
        ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
        $this->cache = $cacheFactory->createLocal(Application::APP_ID);
    }//end __construct()

    /**
     * Return aggregated KPI data for the authenticated user.
     *
     * Uses a per-user version-keyed cache with 60s TTL. Returns cacheHit: true
     * when served from cache, false when DB queries were executed.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response with KPI data
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T09
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $userId = $user->getUID();

        $versionKey = self::CACHE_PREFIX.$userId.self::VERSION_SUFFIX;
        $version    = $this->cache->get($versionKey) ?? 1;
        $dataKey    = self::CACHE_PREFIX.$userId.'_v'.$version;

        $cached = $this->cache->get($dataKey);
        if ($cached !== null && is_array($cached) === true) {
            $cached['cacheHit'] = true;
            return new JSONResponse($cached);
        }

        $kpis = $this->kpiAggregation->computeKpis($userId);
        $kpis['computedAt'] = (new DateTime())->format(DateTime::ATOM);
        $kpis['cacheHit']   = false;

        try {
            $this->cache->set($dataKey, $kpis, self::CACHE_TTL);
        } catch (\Exception $e) {
            $this->logger->debug('[KpiController] Cache store failed', ['key' => $dataKey, 'error' => $e->getMessage()]);
        }

        return new JSONResponse($kpis);
    }//end index()
}//end class
