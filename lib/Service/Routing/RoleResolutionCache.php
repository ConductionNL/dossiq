<?php

/**
 * Dossiq role resolution cache.
 *
 * The 60 second APCu layer in front of resolving a routing rule against a
 * case, keyed by the rule and the case together. A rule resolves by reading
 * every role record bound to a case and running a strategy over them, and the
 * same rule is asked on every render of a step, a transition and a board
 * column.
 *
 * A case with no id is not cached at all: the key would collide across every
 * unsaved case, and a routing answer that belongs to a different case is worse
 * than no answer.
 *
 * Split out of {@see \OCA\Dossiq\Service\RoleResolverService}, which was over
 * its coupling ceiling. Resolving who a rule routes to and remembering the
 * answer for a minute are two jobs.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing
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
 * @spec openspec/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing;

use OCA\Dossiq\AppInfo\Application;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * What a routing rule last resolved to, for a minute.
 *
 * @spec openspec/specs/role-based-step-routing/spec.md
 */
class RoleResolutionCache {

	/**
	 * APCu cache TTL (seconds) for resolver results.
	 */
	private const CACHE_TTL = 60;

	/**
	 * The local cache instance (APCu when available).
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Cache factory.
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $cacheFactory->createLocal(Application::APP_ID . '_routing');
	}//end __construct()

	/**
	 * What this rule last resolved to against this case.
	 *
	 * @param array<string, mixed> $rule   The rule.
	 * @param string               $caseId The case id, '' when the case has none.
	 *
	 * @return array<int, string>|null The participants, or null on a miss.
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function get(array $rule, string $caseId): ?array {
		if ($caseId === '') {
			return null;
		}

		$hit = $this->cache->get($this->key(rule: $rule, caseId: $caseId));
		if (is_array($hit) === false) {
			return null;
		}

		return array_values(
			array_map(
				static fn ($value): string => (string)$value,
				$hit,
			)
		);
	}//end get()

	/**
	 * Remember what this rule resolved to against this case.
	 *
	 * @param array<string, mixed> $rule    The rule.
	 * @param string               $caseId  The case id, '' when the case has none.
	 * @param array<int, string>   $members The resolved participants.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function remember(array $rule, string $caseId, array $members): void {
		if ($caseId === '') {
			return;
		}

		$this->cache->set($this->key(rule: $rule, caseId: $caseId), $members, self::CACHE_TTL);
	}//end remember()

	/**
	 * Forget every rule's answer about a case.
	 *
	 * Called by the role-mutation listener.
	 *
	 * @param string $caseId The case UUID/id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function forget(string $caseId): void {
		if ($caseId === '') {
			return;
		}

		// We cannot enumerate keys on ICache, so clear the whole local segment.
		// Acceptable: the cache is per-app, the segment is namespaced and
		// resolver hits are rebuilt within 60s anyway.
		$this->cache->clear();
	}//end forget()

	/**
	 * Build a cache key from rule + caseId.
	 *
	 * @param array<string, mixed> $rule   The rule.
	 * @param string               $caseId The case id.
	 *
	 * @return string The key.
	 */
	private function key(array $rule, string $caseId): string {
		$hash = md5(serialize($rule));
		return sprintf('rrs.%s.%s', $hash, $caseId);
	}//end key()

}//end class
