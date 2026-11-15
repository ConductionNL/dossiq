<?php

/**
 * The PDOK Locatieserver outage breaker: when failures degrade the service, what a degraded service still does, and how it recovers.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Pdok
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/pdok-integration/spec.md
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

/**
 * @covers \OCA\Dossiq\Service\Pdok\PdokLocatieserverService
 * @uses   \OCA\Dossiq\Service\Pdok\LocatieserverCall
 */
final class PdokOutageBreakerTest extends TestCase {

	/**
	 * The distributed cache, in memory.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * URLs fetched.
	 *
	 * @var array<int, string>
	 */
	private array $fetched = [];

	/**
	 * The HTTP status the next call answers.
	 *
	 * @var int
	 */
	private int $status = 200;

	/**
	 * A service over the in-memory cache and a client answering $this->status.
	 *
	 * @return PdokLocatieserverService
	 */
	private function service(): PdokLocatieserverService {
		$cache = $this->createMock(ICache::class);
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
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->with('dossiq_pdok_locatieserver')->willReturn($cache);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url): IResponse {
				$this->fetched[] = $url;
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($this->status);
				$response->method('getBody')->willReturn('{"response":{"docs":[{"id":"adr-1"}]}}');
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new PdokLocatieserverService(
			cacheFactory: $factory,
			appConfig: $config,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			clientService: $clients,
		);
	}//end service()

	public function testAHealthyServiceSuggestsThroughTheDefaultEndpoint(): void {
		$service = $this->service();

		self::assertSame('ok', $service->health());
		self::assertSame(['response' => ['docs' => [['id' => 'adr-1']]]], $service->suggest(query: 'Damrak 1', filterQueries: ['type:adres'], rows: 5));
		self::assertSame(
			['https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest?' . http_build_query(['q' => 'Damrak 1', 'rows' => 5, 'fq' => ['type:adres']])],
			$this->fetched
		);
	}//end testAHealthyServiceSuggestsThroughTheDefaultEndpoint()

	public function testThreeServerErrorsInAMinuteDegradeTheService(): void {
		$service = $this->service();
		$this->status = 503;

		$service->lookup(id: 'a');
		$service->lookup(id: 'b');
		self::assertSame('ok', $service->health());
		self::assertCount(2, $this->cached['failures']);

		$service->lookup(id: 'c');

		self::assertSame('degraded', $service->health());
		self::assertArrayNotHasKey('failures', $this->cached);
		self::assertGreaterThan(time(), $this->cached['degraded_until']);
	}//end testThreeServerErrorsInAMinuteDegradeTheService()

	public function testClientErrorsDoNotCountTowardsAnOutage(): void {
		$service = $this->service();
		$this->status = 404;

		foreach (['a', 'b', 'c', 'd'] as $id) {
			self::assertSame(['response' => ['docs' => []]], $service->lookup(id: $id));
		}

		self::assertSame('ok', $service->health());
		self::assertArrayNotHasKey('failures', $this->cached);
	}//end testClientErrorsDoNotCountTowardsAnOutage()

	public function testFailuresOlderThanTheWindowAreForgotten(): void {
		$this->cached['failures'] = [time() - 120, time() - 90];
		$this->status = 500;

		$this->service()->lookup(id: 'a');

		self::assertSame('ok', $this->service()->health());
		self::assertCount(1, $this->cached['failures']);
	}//end testFailuresOlderThanTheWindowAreForgotten()

	public function testADegradedServiceSuggestsNothingWithoutCalling(): void {
		$this->cached['degraded_until'] = time() + 100;

		self::assertSame([], $this->service()->suggest(query: 'Damrak'));
		self::assertSame([], $this->fetched);
	}//end testADegradedServiceSuggestsNothingWithoutCalling()

	public function testAnExpiredDegradationIsClearedAndTheServiceRecovers(): void {
		$this->cached['degraded_until'] = time() - 1;

		self::assertSame('ok', $this->service()->health());
		self::assertArrayNotHasKey('degraded_until', $this->cached);
	}//end testAnExpiredDegradationIsClearedAndTheServiceRecovers()

	public function testASuccessfulCallClearsRecordedFailures(): void {
		$this->cached['failures'] = [time()];

		$this->service()->free(query: 'Amsterdam');

		self::assertArrayNotHasKey('failures', $this->cached);
	}//end testASuccessfulCallClearsRecordedFailures()
}//end class
