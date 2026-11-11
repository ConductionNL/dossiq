<?php

/**
 * The Locatieserver call: cache, direct HTTP, the gateway, and failures
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Pdok
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
 * What a Locatieserver read answers, caches and asks.
 *
 * @covers \OCA\Dossiq\Service\Pdok\PdokLocatieserverService
 * @uses \OCA\Dossiq\Support\FleetAppId
 *
 * @spec openspec/specs/pdok-integration/spec.md
 */
final class PdokLocatieserverServiceTest extends TestCase {
	/**
	 * The cache, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * The URLs fetched directly.
	 *
	 * @var array<int, string>
	 */
	private array $fetched = [];

	/**
	 * The service over an array cache, a client answering as given, and an optional gateway.
	 *
	 * @param \Closure    $answer  What a GET answers: a body string, or a Throwable to throw.
	 * @param string      $source  The configured gateway source, or ''.
	 * @param object|null $gateway The gateway's CallService, or null when absent.
	 *
	 * @return PdokLocatieserverService
	 */
	private function service(\Closure $answer, string $source = '', ?object $gateway = null): PdokLocatieserverService {
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
		$factory->method('createDistributed')->willReturn($cache);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'pdok_locatieserver_source' ? $source : $default)
		);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($answer): IResponse {
				$this->fetched[] = $url;
				$body = $answer();
				if ($body instanceof \Throwable) {
					throw $body;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn($body);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($gateway): object {
				if ($gateway === null) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $gateway;
			}
		);

		return new PdokLocatieserverService(
			cacheFactory: $factory,
			appConfig: $config,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			clientService: $clients,
		);
	}//end service()

	/**
	 * A miss fetches, decodes and caches; the same read again is served from the cache.
	 *
	 * @return void
	 */
	public function testAMissIsFetchedAndCachedAndAHitIsNot(): void {
		$service = $this->service(answer: static fn (): string => '{"response":{"docs":[{"id":"a"}]}}');

		$this->assertSame(['response' => ['docs' => [['id' => 'a']]]], $service->free('Dorpsstraat 1'));
		$this->assertSame(['response' => ['docs' => [['id' => 'a']]]], $service->free('Dorpsstraat 1'));
		$this->assertCount(1, $this->fetched);
		$this->assertStringEndsWith('/free?q=Dorpsstraat+1', $this->fetched[0]);
	}//end testAMissIsFetchedAndCachedAndAHitIsNot()

	/**
	 * A body that is no JSON object is cached as no documents; a failed call answers no documents uncached.
	 *
	 * @return void
	 */
	public function testABadBodyAndAFailedCallAnswerNoDocuments(): void {
		$this->assertSame(['response' => ['docs' => []]], $this->service(answer: static fn (): string => 'not json')->free('x'));
		$this->assertCount(1, $this->cached);

		$this->cached = [];
		$failing = $this->service(answer: static fn (): \Throwable => new RuntimeException('connection refused'));
		$this->assertSame(['response' => ['docs' => []]], $failing->free('y'));
		$this->assertArrayNotHasKey('free_' . md5('q=y'), $this->cached);
		$this->assertArrayHasKey('failures', $this->cached);
	}//end testABadBodyAndAFailedCallAnswerNoDocuments()

	/**
	 * A configured source goes through the gateway; without one the call falls back to direct HTTP.
	 *
	 * @return void
	 */
	public function testTheGatewayAndItsFallback(): void {
		$gateway = new class {
			/**
			 * The calls the gateway received.
			 *
			 * @var array<int, array>
			 */
			public array $calls = [];

			/**
			 * Answer the call.
			 *
			 * @return array
			 */
			public function call(string $source, string $path, string $method, array $config): array {
				$this->calls[] = [$source, $path, $method, $config];
				return ['response' => ['body' => ['response' => ['docs' => [['id' => 'gw']]]]]];
			}
		};

		$viaGateway = $this->service(answer: static fn (): string => '{}', source: 'pdok', gateway: $gateway);
		$this->assertSame(['response' => ['docs' => [['id' => 'gw']]]], $viaGateway->free('z'));
		$this->assertSame([['pdok', 'free', 'GET', ['query' => ['q' => 'z'], 'headers' => ['Accept' => 'application/json']]]], $gateway->calls);
		$this->assertSame([], $this->fetched);

		$fallback = $this->service(answer: static fn (): string => '{"response":{"docs":[]}}', source: 'pdok');
		$this->assertSame(['response' => ['docs' => []]], $fallback->free('w'));
		$this->assertCount(1, $this->fetched);
	}//end testTheGatewayAndItsFallback()
}//end class
