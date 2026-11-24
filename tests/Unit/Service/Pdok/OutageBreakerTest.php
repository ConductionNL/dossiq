<?php

/**
 * Unit tests for the PDOK outage breaker.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pdok;

use OCA\Dossiq\Service\Pdok\OutageBreaker;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Pdok\OutageBreaker
 */
class OutageBreakerTest extends TestCase {

	/**
	 * What the cache holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * A breaker over an in-memory cache.
	 *
	 * @return OutageBreaker The breaker.
	 */
	private function breaker(): OutageBreaker {
		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cached[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->cached[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cached[$key]);
				return true;
			}
		);

		return new OutageBreaker(cache: $cache, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end breaker()

	/**
	 * Three server errors degrade; client errors never count; a success clears the state.
	 *
	 * @return void
	 */
	public function testThreeServerErrorsDegradeAndASuccessClears(): void {
		$breaker = $this->breaker();

		$breaker->recordFailure(statusCode: 404);
		$breaker->recordFailure(statusCode: 404);
		$breaker->recordFailure(statusCode: 404);
		self::assertFalse(condition: $breaker->isDegraded());

		$breaker->recordFailure(statusCode: 503);
		$breaker->recordFailure(statusCode: 0);
		self::assertFalse(condition: $breaker->isDegraded());
		$breaker->recordFailure(statusCode: 500);
		self::assertTrue(condition: $breaker->isDegraded());

		$breaker->recordSuccess();
		self::assertFalse(condition: $breaker->isDegraded());
	}//end testThreeServerErrorsDegradeAndASuccessClears()

	/**
	 * A degraded state whose cool-down has passed lifts on the next ask.
	 *
	 * @return void
	 */
	public function testAnExpiredDegradedStateLifts(): void {
		$this->cached['degraded_until'] = (time() - 1);

		self::assertFalse(condition: $this->breaker()->isDegraded());
		self::assertArrayNotHasKey(key: 'degraded_until', array: $this->cached);
	}//end testAnExpiredDegradedStateLifts()
}//end class
