<?php

/**
 * Dossiq tenant membership cache.
 *
 * The tenant ids a user is a member of, kept in the distributed cache for a
 * minute per user. Every dossiq request asks for them (the mandate middleware
 * checks the active tenant), and reading them is an OpenRegister search. A
 * create, update or delete of a tenantUser row drops the entries of the users
 * it names (see {@see \OCA\Dossiq\Listener\TenantMembershipChangedListener});
 * the minute bounds a change that reaches the table without an object event.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Tenant
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Tenant;

use OCA\Dossiq\Kernel\AppIdentity;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Remembers a user's tenant ids for a minute.
 *
 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
 */
class TenantMembershipCache {

	/**
	 * How long a user's tenant ids are kept, in seconds.
	 */
	public const TTL = 60;

	/**
	 * The distributed cache segment.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory The cache factory.
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $cacheFactory->createDistributed(AppIdentity::APP_ID . '_tenant_members');
	}//end __construct()

	/**
	 * The kept tenant ids of a user, or null when none are kept.
	 *
	 * @param string $userId The Nextcloud uid.
	 *
	 * @return array<int, string>|null
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
	 */
	public function get(string $userId): ?array {
		$ids = $this->cache->get(self::key(userId: $userId));
		if (is_array($ids) === false) {
			return null;
		}

		return array_values(array_map('strval', $ids));
	}//end get()

	/**
	 * Keep a user's tenant ids for {@see self::TTL} seconds.
	 *
	 * @param string             $userId    The Nextcloud uid.
	 * @param array<int, string> $tenantIds The tenant ids.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
	 */
	public function remember(string $userId, array $tenantIds): void {
		$this->cache->set(self::key(userId: $userId), array_values($tenantIds), self::TTL);
	}//end remember()

	/**
	 * Drop a user's kept tenant ids.
	 *
	 * @param string $userId The Nextcloud uid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
	 */
	public function forget(string $userId): void {
		$this->cache->remove(self::key(userId: $userId));
	}//end forget()

	/**
	 * The cache key of a user.
	 *
	 * @param string $userId The Nextcloud uid.
	 *
	 * @return string
	 */
	private static function key(string $userId): string {
		return 'u.' . md5($userId);
	}//end key()
}//end class
