<?php

/**
 * PDOK Locatieserver request shapes: the URL each read asks for, how long its
 * answer is kept, and that two reads never answer each other from the cache.
 *
 * The four reads differ only in their parameters and their cache lifetime, and
 * both are what PDOK's rate limit and a stale street name hang on, so each is
 * pinned per method rather than left to whichever read a behaviour test used.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pdok;

use OCA\Dossiq\Service\Pdok\PdokLocatieserverService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Pdok\PdokLocatieserverService
 * @uses   \OCA\Dossiq\Service\Pdok\LocatieserverCall
 */
final class PdokRequestShapesTest extends TestCase {

	private const ENDPOINT = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1';

	/**
	 * Cached values by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * The ttl each key was written with, by key.
	 *
	 * @var array<string, int>
	 */
	private array $ttls = [];

	/**
	 * Every URL asked of PDOK, in order.
	 *
	 * @var array<int, string>
	 */
	private array $fetched = [];

	/**
	 * Admin settings this test answers.
	 *
	 * @var array<string, string>
	 */
	private array $settings = [];

	protected function setUp(): void {
		$this->cached = [];
		$this->ttls = [];
		$this->fetched = [];
		$this->settings = [];
	}

	/**
	 * The service over a client that answers one document.
	 *
	 * @return PdokLocatieserverService
	 */
	private function service(): PdokLocatieserverService {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cached[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->cached[$key] = $value;
				$this->ttls[$key] = $ttl;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cached[$key]);
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->settings[$key] ?? $default)
		);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url): IResponse {
				$this->fetched[] = $url;
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn('{"response":{"docs":[{"id":"a"}]}}');
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): object {
				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return new PdokLocatieserverService(
			cacheFactory: $factory,
			appConfig: $config,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			clientService: $clients,
		);
	}

	public function testASuggestAsksForTenRowsUnlessToldOtherwise(): void {
		$service = $this->service();

		$service->suggest('Dorpsstr');
		$service->suggest('Dorpsstr', [], 25);

		$this->assertSame(self::ENDPOINT . '/suggest?q=Dorpsstr&rows=10', $this->fetched[0]);
		$this->assertSame(self::ENDPOINT . '/suggest?q=Dorpsstr&rows=25', $this->fetched[1]);
	}

	public function testEveryFilterQueryIsSentAsItsOwnFq(): void {
		$service = $this->service();

		$service->suggest('Dorpsstr', ['type:adres', 'provincienaam:Utrecht'], 5);

		$this->assertSame(
			self::ENDPOINT . '/suggest?q=Dorpsstr&rows=5&fq%5B0%5D=type%3Aadres&fq%5B1%5D=provincienaam%3AUtrecht',
			$this->fetched[0]
		);
	}

	public function testALookupAsksForTheIdAndAReverseForTheCoordinates(): void {
		$service = $this->service();

		$service->lookup('adr-1234');
		$service->reverse(52.0907, 5.1214);

		$this->assertSame(self::ENDPOINT . '/lookup?id=adr-1234', $this->fetched[0]);
		$this->assertSame(self::ENDPOINT . '/reverse?lat=52.0907&lon=5.1214', $this->fetched[1]);
	}

	/**
	 * A suggest is kept for five minutes and the other reads for a day: a
	 * typed-ahead suggestion going stale costs a keystroke, a resolved address
	 * going stale costs a request to PDOK on every page view.
	 *
	 * @return void
	 */
	public function testASuggestIsKeptBrieflyAndAResolvedAddressForADay(): void {
		$service = $this->service();

		$service->suggest('Dorpsstr');
		$service->free('Dorpsstraat 1');
		$service->lookup('adr-1');
		$service->reverse(52.0, 5.0);

		$this->assertSame(
			[300, 86400, 86400, 86400],
			array_values($this->ttls)
		);
	}

	public function testAnAdminMayShortenOrLengthenEitherLifetime(): void {
		$this->settings = [
			'pdok_cache_suggest_ttl_seconds' => '60',
			'pdok_cache_lookup_ttl_seconds' => '3600',
		];
		$service = $this->service();

		$service->suggest('Dorpsstr');
		$service->lookup('adr-1');

		$this->assertSame([60, 3600], array_values($this->ttls));
	}

	public function testAnAdminMayPointTheReadsAtAnotherEndpoint(): void {
		$this->settings = ['pdok_locatieserver_endpoint' => 'https://pdok.gemeente.example/locatieserver/v3_1/'];

		$this->service()->lookup('adr-1');

		$this->assertSame('https://pdok.gemeente.example/locatieserver/v3_1/lookup?id=adr-1', $this->fetched[0]);
	}

	/**
	 * Two reads never answer each other: the cache key carries the method and
	 * the whole query, so a lookup is not served a suggest's documents.
	 *
	 * @return void
	 */
	public function testEachReadHasItsOwnCacheEntry(): void {
		$service = $this->service();

		$service->suggest('Dorpsstraat');
		$service->free('Dorpsstraat');
		$service->lookup('Dorpsstraat');
		$service->suggest('Dorpsstraat', ['type:adres']);
		$service->suggest('Dorpsstraat');

		$this->assertCount(4, $this->fetched, 'only the repeated suggest is answered from the cache');
		$this->assertSame(
			[
				'suggest_' . md5('q=Dorpsstraat&rows=10'),
				'free_' . md5('q=Dorpsstraat'),
				'lookup_' . md5('id=Dorpsstraat'),
				'suggest_' . md5('q=Dorpsstraat&rows=10&fq%5B0%5D=type%3Aadres'),
			],
			array_keys($this->ttls)
		);
	}

	public function testAHealthyServiceReportsOk(): void {
		$this->assertSame('ok', $this->service()->health());
		$this->assertSame([], $this->fetched, 'health asks PDOK nothing');
	}
}
