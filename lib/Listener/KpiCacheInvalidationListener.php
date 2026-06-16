<?php

/**
 * Procest KPI Cache Invalidation Listener
 *
 * Listens to OpenRegister object lifecycle events (created, updated, deleted)
 * and increments the per-user KPI cache version counter, ensuring the next
 * GET /api/dashboard/kpis request computes fresh data.
 *
 * No eager recomputation is performed — the version bump guarantees that
 * the existing cached payload will not be served after a write, while
 * avoiding thundering-herd on busy workflows.
 *
 * @category Listener
 * @package  OCA\Procest\Listener
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

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Procest\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Invalidates per-user KPI caches on any OpenRegister object mutation.
 *
 * Increments the version counter key `procest_kpis_{userId}_ver` stored in
 * the local APCu cache. The KpiController reads this version to build its
 * data cache key; a version change causes a cache miss on the next request.
 *
 * @implements IEventListener<Event>
 *
 * @see role-routing-via-or-rbac — confirmed: no access decisions made here.
 *      Listens only on OCA\OpenRegister\Event\* object events; uses
 *      IUserSession::getUser() solely to key the per-user KPI cache version,
 *      never to gate access; writes no parallel audit or permission store.
 *
 * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T12
 */
class KpiCacheInvalidationListener implements IEventListener
{

    /**
     * Cache prefix for KPI version keys.
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
     * @param ICacheFactory   $cacheFactory The cache factory
     * @param IUserSession    $userSession  The user session
     * @param LoggerInterface $logger       Logger
     *
     * @return void
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createLocal(Application::APP_ID);
    }//end __construct()

    /**
     * Handle an OpenRegister object lifecycle event.
     *
     * Increments the version counter for the current user's KPI cache,
     * causing the next KpiController request to recompute from the DB.
     *
     * @param Event $event The dispatched event
     *
     * @return void
     *
     * @spec openspec/changes/add-server-side-kpi-aggregation/tasks.md#T12
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false
            && ($event instanceof ObjectUpdatedEvent) === false
            && ($event instanceof ObjectDeletedEvent) === false
        ) {
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        $userId     = $user->getUID();
        $versionKey = self::CACHE_PREFIX.$userId.self::VERSION_SUFFIX;

        try {
            $current    = $this->cache->get($versionKey);
            $newVersion = 2;
            if ($current !== null) {
                $newVersion = ((int) $current) + 1;
            }

            $this->cache->set($versionKey, $newVersion);
        } catch (\Exception $e) {
            $this->logger->debug(
                '[KpiCacheInvalidationListener] Failed to increment version key',
                [
                    'key'   => $versionKey,
                    'error' => $e->getMessage(),
                ]
            );
        }//end try
    }//end handle()
}//end class
