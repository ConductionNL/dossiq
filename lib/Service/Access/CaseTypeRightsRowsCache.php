<?php

/**
 * Dossiq case type rights rows cache.
 *
 * The rows the current case types' rights matrices compile to, kept in the
 * distributed cache for a minute. Every OpenRegister read of a case-like
 * object asks for them, and compiling them reads every case type and every
 * organisation role. The rows do not depend on who asks, so one entry serves
 * every user. A change to a case type or an organisation role drops the entry
 * (see {@see \OCA\Dossiq\Listener\CaseTypeRightsSourcesListener}); the minute
 * bounds a change that reaches the tables without an object event.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
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
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Kernel\AppIdentity;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Remembers the compiled case type rights rows for a minute.
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */
class CaseTypeRightsRowsCache {

	/**
	 * How long the rows are kept, in seconds.
	 */
	public const TTL = 60;

	/**
	 * The cache key of the rows.
	 */
	private const KEY = 'case-type-rows';

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
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $cacheFactory->createDistributed(AppIdentity::APP_ID . '_rights');
	}//end __construct()

	/**
	 * The remembered rows, or null when none are kept.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function get(): ?array {
		$rows = $this->cache->get(self::KEY);
		if (is_array($rows) === false) {
			return null;
		}

		return $rows;
	}//end get()

	/**
	 * Keep the rows for {@see self::TTL} seconds.
	 *
	 * @param array<int, array<string, mixed>> $rows The compiled rows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function remember(array $rows): void {
		$this->cache->set(self::KEY, $rows, self::TTL);
	}//end remember()

	/**
	 * Drop the rows, so the next read compiles them again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function forget(): void {
		$this->cache->remove(self::KEY);
	}//end forget()
}//end class
