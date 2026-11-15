<?php

/**
 * One Locatieserver read
 *
 * The endpoint method and its query, the cache key and lifetime, the
 * gateway source when one is configured, and when the read started: the
 * state PdokLocatieserverService builds per read and its steps share.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/pdok-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pdok;

/**
 * The state of one read; it never leaves the service that built it.
 *
 * @spec openspec/specs/pdok-integration/spec.md
 */
final class LocatieserverCall {

	/**
	 * The endpoint suffix (`suggest`, `free`, `lookup`, `reverse`).
	 *
	 * @var string
	 */
	public string $method = '';

	/**
	 * The query string parameters.
	 *
	 * @var array<string, mixed>
	 */
	public array $params = [];

	/**
	 * The normalised query string.
	 *
	 * @var string
	 */
	public string $queryString = '';

	/**
	 * The cache key: the method and a hash of the query.
	 *
	 * @var string
	 */
	public string $cacheKey = '';

	/**
	 * The cache lifetime in seconds.
	 *
	 * @var integer
	 */
	public int $ttl = 0;

	/**
	 * The gateway source slug, or '' to call PDOK directly.
	 *
	 * @var string
	 */
	public string $source = '';

	/**
	 * When the read started (microtime).
	 *
	 * @var float
	 */
	public float $started = 0.0;
}//end class
