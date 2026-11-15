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
 * @uses \OCA\Dossiq\Service\Pdok\LocatieserverCall
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
	/**
	 * A gateway answering with the given result, or throwing it when it is a Throwable.
	 *
	 * @param mixed $result What the gateway's call answers.
	 *
	 * @return object The gateway's CallService stand-in.
	 */
	private function gateway(mixed $result): object {
		return new class($result) {
			/**
			 * @param mixed $result What call() answers.
			 */
			public function __construct(private mixed $result) {
			}

			/**
			 * Answer the call.
			 *
			 * @return mixed
			 */
			public function call(string $source, string $path, string $method, array $config): mixed {
				if ($this->result instanceof \Throwable) {
					throw $this->result;
				}

				return $this->result;
			}
		};
	}//end gateway()

	/**
	 * A client exception that carries its HTTP status on its response, as Guzzle's do.
	 *
	 * @param int $status The status on the response.
	 *
	 * @return RuntimeException
	 */
	private function exceptionWithResponse(int $status): RuntimeException {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);

		return new class('upstream refused', $response) extends RuntimeException {
			/**
			 * @param string    $message  The message.
			 * @param IResponse $response The response carrying the status.
			 */
			public function __construct(string $message, private IResponse $response) {
				parent::__construct($message);
			}

			/**
			 * The response the client received.
			 *
			 * @return IResponse
			 */
			public function getResponse(): IResponse {
				return $this->response;
			}
		};
	}//end exceptionWithResponse()

	/**
	 * Filter queries travel as an `fq` list on a free-text read.
	 *
	 * @return void
	 */
	public function testFreeTextFilterQueriesAreSent(): void {
		$service = $this->service(answer: static fn (): string => '{"response":{"docs":[]}}');

		$service->free('Dorpsstraat', ['type:adres', 'gemeentenaam:Utrecht']);

		$this->assertCount(1, $this->fetched);
		$this->assertStringEndsWith(
			'/free?' . http_build_query(['q' => 'Dorpsstraat', 'fq' => ['type:adres', 'gemeentenaam:Utrecht']]),
			$this->fetched[0]
		);
	}//end testFreeTextFilterQueriesAreSent()

	/**
	 * A reverse read asks for the coordinates as lat and lon, and caches the answer.
	 *
	 * @return void
	 */
	public function testReverseAsksForLatAndLon(): void {
		$service = $this->service(answer: static fn (): string => '{"response":{"docs":[{"id":"near"}]}}');

		$this->assertSame(['response' => ['docs' => [['id' => 'near']]]], $service->reverse(52.09, 5.12));
		$this->assertStringEndsWith('/reverse?lat=52.09&lon=5.12', $this->fetched[0]);
		$this->assertArrayHasKey('reverse_' . md5('lat=52.09&lon=5.12'), $this->cached);
	}//end testReverseAsksForLatAndLon()

	/**
	 * The status on a client exception's response decides whether it counts as an outage.
	 *
	 * @return void
	 */
	public function testTheStatusOnAnExceptionResponseDecidesTheOutageBudget(): void {
		$notFound = $this->service(answer: fn (): \Throwable => $this->exceptionWithResponse(404));
		$this->assertSame(['response' => ['docs' => []]], $notFound->free('a'));
		$this->assertArrayNotHasKey('failures', $this->cached);

		$unavailable = $this->service(answer: fn (): \Throwable => $this->exceptionWithResponse(503));
		$this->assertSame(['response' => ['docs' => []]], $unavailable->free('b'));
		$this->assertCount(1, $this->cached['failures']);
		$this->assertArrayNotHasKey('free_' . md5('q=b'), $this->cached);
	}//end testTheStatusOnAnExceptionResponseDecidesTheOutageBudget()

	/**
	 * Without a response, an HTTP-shaped exception code is the status; any other code is a network error.
	 *
	 * @return void
	 */
	public function testAnExceptionCodeIsTheStatusWhenThereIsNoResponse(): void {
		$badRequest = $this->service(answer: static fn (): \Throwable => new RuntimeException('bad request', 400));
		$badRequest->free('a');
		$this->assertArrayNotHasKey('failures', $this->cached);

		$badGateway = $this->service(answer: static fn (): \Throwable => new RuntimeException('bad gateway', 502));
		$badGateway->free('b');
		$this->assertCount(1, $this->cached['failures']);

		// A code outside the HTTP range is a transport error, which counts too.
		$transport = $this->service(answer: static fn (): \Throwable => new RuntimeException('timed out', 28));
		$transport->free('c');
		$this->assertCount(2, $this->cached['failures']);
	}//end testAnExceptionCodeIsTheStatusWhenThereIsNoResponse()

	/**
	 * A gateway that throws is a failed read counted as an outage, with no direct fallback.
	 *
	 * @return void
	 */
	public function testAThrowingGatewayFailsWithoutFallingBack(): void {
		$service = $this->service(
			answer: static fn (): string => '{"response":{"docs":[{"id":"direct"}]}}',
			source: 'pdok',
			gateway: $this->gateway(new RuntimeException('source misconfigured')),
		);

		$this->assertSame(['response' => ['docs' => []]], $service->free('a'));
		$this->assertSame([], $this->fetched);
		$this->assertCount(1, $this->cached['failures']);
		$this->assertArrayNotHasKey('free_' . md5('q=a'), $this->cached);
	}//end testAThrowingGatewayFailsWithoutFallingBack()

	/**
	 * A string body is used as given, under `body` or under `response.body`.
	 *
	 * @return void
	 */
	public function testAGatewayStringBodyIsDecoded(): void {
		$top = $this->service(answer: static fn (): string => '{}', source: 'pdok', gateway: $this->gateway(['body' => '{"response":{"docs":[{"id":"top"}]}}']));
		$this->assertSame(['response' => ['docs' => [['id' => 'top']]]], $top->free('a'));

		$nested = $this->service(answer: static fn (): string => '{}', source: 'pdok', gateway: $this->gateway(['response' => ['body' => '{"response":{"docs":[{"id":"nested"}]}}']]));
		$this->assertSame(['response' => ['docs' => [['id' => 'nested']]]], $nested->free('b'));
		$this->assertSame([], $this->fetched);
	}//end testAGatewayStringBodyIsDecoded()

	/**
	 * An answer with no body in a known place is refused as a 502 and not cached.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedGatewayShapeIsAFailedRead(): void {
		foreach (['status' => ['ok' => true], 'scalar' => 'just a string', 'number body' => ['body' => 42]] as $label => $answer) {
			$this->cached = [];
			$service = $this->service(answer: static fn (): string => '{}', source: 'pdok', gateway: $this->gateway($answer));

			$this->assertSame(['response' => ['docs' => []]], $service->free('a'), $label);
			$this->assertArrayNotHasKey('free_' . md5('q=a'), $this->cached, $label);
			$this->assertCount(1, $this->cached['failures'], $label . ': a 502 counts towards the outage budget');
		}

		$this->assertSame([], $this->fetched);
	}//end testAnUnrecognisedGatewayShapeIsAFailedRead()

	/**
	 * A free-text read is not held back by a degraded state, and its success clears it.
	 *
	 * @return void
	 */
	public function testASuccessfulReadClearsTheDegradedState(): void {
		$this->cached['degraded_until'] = time() + 300;
		$this->cached['failures'] = [time()];
		$service = $this->service(answer: static fn (): string => '{"response":{"docs":[{"id":"a"}]}}');

		$this->assertSame(['response' => ['docs' => [['id' => 'a']]]], $service->free('a'));
		$this->assertArrayNotHasKey('degraded_until', $this->cached);
		$this->assertArrayNotHasKey('failures', $this->cached);
		$this->assertSame('ok', $service->health());
	}//end testASuccessfulReadClearsTheDegradedState()
}//end class
