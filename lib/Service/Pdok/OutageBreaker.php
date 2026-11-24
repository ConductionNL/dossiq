<?php

/**
 * Dossiq PDOK outage breaker.
 *
 * Counts the Locatieserver's server errors in a short window and, once they
 * pile up, holds the service in a degraded state for a cool-down so callers
 * fall back to free-text input instead of waiting on a failing upstream.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pdok;

use OCP\ICache;
use Psr\Log\LoggerInterface;

/**
 * The outage budget and the degraded state, kept in the service's shared cache.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
final class OutageBreaker {

	/**
	 * Outage window — number of consecutive 5xx responses before degrading.
	 */
	private const OUTAGE_THRESHOLD = 3;

	/**
	 * Outage window length in seconds.
	 */
	private const OUTAGE_WINDOW = 60;

	/**
	 * How long the service stays degraded once tripped (5 min).
	 */
	private const DEGRADED_TTL = 300;

	/**
	 * Constructor.
	 *
	 * @param ICache          $cache  The service's shared cache, which also holds its responses.
	 * @param LoggerInterface $logger Says when the degraded state engages.
	 */
	public function __construct(
		private readonly ICache $cache,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a successful call (clears the failure counter).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function recordSuccess(): void {
		$this->cache->remove('failures');
		// Self-clear degraded state on first success after cool-down.
		if ($this->cache->get('degraded_until') !== null) {
			$this->cache->remove('degraded_until');
		}
	}//end recordSuccess()

	/**
	 * Record a failure and flip into degraded state if the threshold is hit
	 * within the rolling window.
	 *
	 * @param int $statusCode HTTP status code from the failing call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function recordFailure(int $statusCode): void {
		// Only 5xx (and network errors, coded 0) count toward the outage
		// budget. 4xx responses indicate a client problem, not a PDOK outage.
		if ($statusCode !== 0 && ($statusCode < 500 || $statusCode >= 600)) {
			return;
		}

		$failures = $this->cachedFailures();

		$now = time();
		$failures = $this->withinWindow(failures: $failures, now: $now);

		$failures[] = $now;

		if (count(value: $failures) >= self::OUTAGE_THRESHOLD) {
			$this->engageDegraded(now: $now, failures: $failures);
			return;
		}

		$this->cache->set('failures', array_values(array: $failures), self::OUTAGE_WINDOW);
	}//end recordFailure()

	/**
	 * The failure timestamps in the cache, or none.
	 *
	 * @return array<int, int>
	 */
	private function cachedFailures(): array {
		$failures = $this->cache->get('failures');
		if (is_array(value: $failures) === false) {
			$failures = [];
		}

		return $failures;
	}//end cachedFailures()

	/**
	 * The failures inside the rolling window.
	 *
	 * @param array<int, int> $failures The failure timestamps.
	 * @param int             $now      The current time.
	 *
	 * @return array<int, int>
	 */
	private function withinWindow(array $failures, int $now): array {
		return array_filter(
			array: $failures,
			callback: static function (int $failedAt) use ($now): bool {
				return ($now - $failedAt) <= self::OUTAGE_WINDOW;
			}
		);
	}//end withinWindow()

	/**
	 * Flip into degraded state and clear the failure count.
	 *
	 * @param int             $now      The current time.
	 * @param array<int, int> $failures The failures that hit the threshold.
	 *
	 * @return void
	 */
	private function engageDegraded(int $now, array $failures): void {
		$this->cache->set('degraded_until', ($now + self::DEGRADED_TTL), self::DEGRADED_TTL);
		$this->cache->remove('failures');
		$this->logger->warning(
			'Dossiq PDOK Locatieserver: degraded state engaged',
			['failureCount' => count(value: $failures), 'cooldownSec' => self::DEGRADED_TTL]
		);
	}//end engageDegraded()

	/**
	 * Whether the service is currently in degraded state.
	 *
	 * @return bool True while degraded.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function isDegraded(): bool {
		$until = $this->cache->get('degraded_until');
		if ($until === null) {
			return false;
		}

		if ((int)$until <= time()) {
			$this->cache->remove('degraded_until');
			return false;
		}

		return true;
	}//end isDegraded()
}//end class
