<?php

/**
 * WOZ adapter unit tests (brk-woz-register-adapters).
 *
 * Mirrors `BagAdapterTest`'s coverage shape for the WOZ adapter: request
 * building (URL/headers), the config-tier model via `IntegrationMode`,
 * dormant-default behaviour, Dutch postcode validation, and error mapping
 * (404 vs 5xx vs network failure — never throws into the caller).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\External\Woz
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\External\Woz;

use OCA\Procest\Service\External\IntegrationMode;
use OCA\Procest\Service\External\Woz\LogWozAdapter;
use OCA\Procest\Service\External\Woz\WozApiAdapter;
use OCA\Procest\Service\External\Woz\WozResponseMapper;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\External\Woz\WozApiAdapter
 * @covers \OCA\Procest\Service\External\Woz\LogWozAdapter
 *
 * @uses \OCA\Procest\Service\External\IntegrationMode
 * @uses \OCA\Procest\Service\External\Woz\WozLookupResult
 * @uses \OCA\Procest\Service\External\Woz\WozResponseMapper
 */
class WozAdapterTest extends TestCase {
	/**
	 * Build an IntegrationMode over a config map.
	 *
	 * @param array<string,string> $config integration.<x>.<y> => value.
	 *
	 * @return IntegrationMode
	 */
	private function mode(array $config): IntegrationMode {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return $config[$key] ?? $default;
			}
		);

		return new IntegrationMode(appConfig: $appConfig);
	}//end mode()

	/**
	 * A client factory whose single GET call captures its args and returns
	 * a canned status + body.
	 *
	 * @param int $status HTTP status to return.
	 * @param string $body Response body.
	 * @param array $captured Reference filled with ['url' => ..., 'options' => ...].
	 *
	 * @return IClientService
	 */
	private function clientCapturing(int $status, string $body, array &$captured): IClientService {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options = []) use (&$captured, $response) {
				$captured['url'] = $url;
				$captured['options'] = $options;
				return $response;
			}
		);

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}//end clientCapturing()

	/**
	 * A client factory whose GET call throws (network failure).
	 *
	 * @return IClientService
	 */
	private function clientThrowing(): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \RuntimeException('connection refused'));

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}//end clientThrowing()

	/**
	 * Fresh install (unset mode) resolves to `log` — the dormant adapter's
	 * tier, never `test`/`live`.
	 *
	 * @return void
	 */
	public function testModeDefaultsToLog(): void {
		$this->assertSame('log', $this->mode([])->resolve('woz', [IntegrationMode::TEST, IntegrationMode::LIVE]));
	}//end testModeDefaultsToLog()

	/**
	 * The dormant LogWozAdapter never calls out and always defers, for
	 * all three lookup shapes.
	 *
	 * @return void
	 */
	public function testLogAdapterDefersAndIsDormant(): void {
		$adapter = new LogWozAdapter(logger: $this->createMock(LoggerInterface::class));

		$this->assertTrue($adapter->isDormant());

		$addressResult = $adapter->lookupAddress(postcode: '1234AB', huisnummer: '10');
		$this->assertSame('LOOKUP_DEFERRED', $addressResult->lookupStatus);
		$this->assertTrue($addressResult->dormant);

		$nummeraanduidingResult = $adapter->lookupByNummeraanduiding(nummeraanduidingId: '0518010000123456');
		$this->assertSame('LOOKUP_DEFERRED', $nummeraanduidingResult->lookupStatus);

		$objectResult = $adapter->lookupByWozObjectNummer(wozobjectnummer: '05180000001234');
		$this->assertSame('LOOKUP_DEFERRED', $objectResult->lookupStatus);
		$this->assertTrue($objectResult->dormant);
	}//end testLogAdapterDefersAndIsDormant()

	/**
	 * A live adapter is never dormant.
	 *
	 * @return void
	 */
	public function testApiAdapterIsNeverDormant(): void {
		$captured = [];
		$adapter = new WozApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['wozObjecten' => []]]), $captured),
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertFalse($adapter->isDormant());
	}//end testApiAdapterIsNeverDormant()

	/**
	 * Address lookup builds the request against the correct resource,
	 * with the configured API key header and query.
	 *
	 * @return void
	 */
	public function testAddressLookupBuildsRequestWithHeadersAndQuery(): void {
		$body = json_encode(
			['_embedded' => ['wozObjecten' => [['wozobjectnummer' => '05180000001234', 'grondoppervlakte' => 250]]]]
		);

		$captured = [];
		$adapter = new WozApiAdapter(
			clientService: $this->clientCapturing(200, $body, $captured),
			mode: $this->mode(['integration.woz.mode' => 'test', 'integration.woz.apiKey' => 'secret-key']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupAddress(postcode: '1234ab', huisnummer: '10');

		$this->assertStringEndsWith('/wozobjecten', $captured['url']);
		$this->assertSame('1234AB', $captured['options']['query']['postcode'], 'postcode must be normalized to uppercase, no spaces');
		$this->assertSame('10', $captured['options']['query']['huisnummer']);
		$this->assertSame('secret-key', $captured['options']['headers']['X-Api-Key']);

		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('05180000001234', $result->wozObject['wozobjectnummer']);
		$this->assertSame('test', $result->extras['tier']);
	}//end testAddressLookupBuildsRequestWithHeadersAndQuery()

	/**
	 * Optional huisletter/toevoeging are forwarded only when present.
	 *
	 * @return void
	 */
	public function testAddressLookupForwardsOptionalHuisletterAndToevoeging(): void {
		$captured = [];
		$adapter = new WozApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['wozObjecten' => []]]), $captured),
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$adapter->lookupAddress(postcode: '1234AB', huisnummer: '10', huisletter: 'A', toevoeging: 'II');

		$this->assertSame('A', $captured['options']['query']['huisletter']);
		$this->assertSame('II', $captured['options']['query']['huisnummertoevoeging']);
	}//end testAddressLookupForwardsOptionalHuisletterAndToevoeging()

	/**
	 * Dutch postcode validation matrix — malformed input never reaches
	 * the network.
	 *
	 * @return void
	 */
	public function testInvalidPostcodeIsRejectedWithoutNetworkCall(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$adapter = new WozApiAdapter(
			clientService: $service,
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		foreach (['0001AB', '1234A', '1234ABC', '12345A', 'ABCD12', ''] as $badPostcode) {
			$result = $adapter->lookupAddress(postcode: $badPostcode, huisnummer: '10');
			$this->assertSame('INVALID_INPUT', $result->lookupStatus, "postcode '{$badPostcode}' must be rejected");
		}
	}//end testInvalidPostcodeIsRejectedWithoutNetworkCall()

	/**
	 * A non-numeric huisnummer is rejected without a network call.
	 *
	 * @return void
	 */
	public function testInvalidHuisnummerIsRejected(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$adapter = new WozApiAdapter(
			clientService: $service,
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupAddress(postcode: '1234AB', huisnummer: 'abc');
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);
	}//end testInvalidHuisnummerIsRejected()

	/**
	 * An empty nummeraanduidingId is rejected without a network call.
	 *
	 * @return void
	 */
	public function testEmptyNummeraanduidingIdIsRejected(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$adapter = new WozApiAdapter(
			clientService: $service,
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupByNummeraanduiding(nummeraanduidingId: '');
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);
	}//end testEmptyNummeraanduidingIdIsRejected()

	/**
	 * lookupByNummeraanduiding builds the request with the correct query
	 * parameter, bypassing address validation entirely (no BAG overlap).
	 *
	 * @return void
	 */
	public function testNummeraanduidingLookupBuildsCorrectQuery(): void {
		$captured = [];
		$adapter = new WozApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['wozObjecten' => []]]), $captured),
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$adapter->lookupByNummeraanduiding(nummeraanduidingId: '0518010000123456');

		$this->assertSame('0518010000123456', $captured['options']['query']['nummeraanduidingIdentificatie']);
	}//end testNummeraanduidingLookupBuildsCorrectQuery()

	/**
	 * Empty search result set (200, no matches) is NOT_FOUND.
	 *
	 * @return void
	 */
	public function testSearchEmptyResultIsNotFound(): void {
		$captured = [];
		$adapter = new WozApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['wozObjecten' => []]]), $captured),
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupAddress(postcode: '9999ZZ', huisnummer: '1');
		$this->assertSame('NOT_FOUND', $result->lookupStatus);
	}//end testSearchEmptyResultIsNotFound()

	/**
	 * An empty wozobjectnummer is rejected without a network call.
	 *
	 * @return void
	 */
	public function testEmptyWozObjectNummerIsRejected(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$adapter = new WozApiAdapter(
			clientService: $service,
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupByWozObjectNummer(wozobjectnummer: '');
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);
	}//end testEmptyWozObjectNummerIsRejected()

	/**
	 * A true HTTP 404 on the object endpoint maps to NOT_FOUND, not an
	 * exception.
	 *
	 * @return void
	 */
	public function testObjectLookup404IsNotFound(): void {
		$captured = [];
		$adapter = new WozApiAdapter(
			clientService: $this->clientCapturing(404, '', $captured),
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupByWozObjectNummer(wozobjectnummer: '00000000000000');
		$this->assertSame('NOT_FOUND', $result->lookupStatus);
		$this->assertStringEndsWith('/wozobjecten/00000000000000', $captured['url']);
	}//end testObjectLookup404IsNotFound()

	/**
	 * A found object maps to FOUND.
	 *
	 * @return void
	 */
	public function testObjectLookupFoundMapsToFound(): void {
		$captured = [];
		$adapter = new WozApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['wozObject' => ['wozobjectnummer' => '05180000001234']]), $captured),
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupByWozObjectNummer(wozobjectnummer: '05180000001234');
		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('05180000001234', $result->wozObject['wozobjectnummer']);
	}//end testObjectLookupFoundMapsToFound()

	/**
	 * A 5xx (or any non-404 non-2xx) status degrades to LOOKUP_ERROR.
	 *
	 * @return void
	 */
	public function testObjectLookup5xxIsLookupError(): void {
		$captured = [];
		$adapter = new WozApiAdapter(
			clientService: $this->clientCapturing(503, '', $captured),
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupByWozObjectNummer(wozobjectnummer: '05180000001234');
		$this->assertSame('LOOKUP_ERROR', $result->lookupStatus);
		$this->assertSame('http-503', $result->extras['reason']);
	}//end testObjectLookup5xxIsLookupError()

	/**
	 * A network failure (thrown exception) degrades to LOOKUP_ERROR, never
	 * propagates.
	 *
	 * @return void
	 */
	public function testNetworkFailureDegradesToLookupError(): void {
		$adapter = new WozApiAdapter(
			clientService: $this->clientThrowing(),
			mode: $this->mode(['integration.woz.mode' => 'test']),
			mapper: new WozResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$addressResult = $adapter->lookupAddress(postcode: '1234AB', huisnummer: '10');
		$this->assertSame('LOOKUP_ERROR', $addressResult->lookupStatus);
		$this->assertSame('transport-error', $addressResult->extras['reason']);

		$objectResult = $adapter->lookupByWozObjectNummer(wozobjectnummer: '05180000001234');
		$this->assertSame('LOOKUP_ERROR', $objectResult->lookupStatus);
	}//end testNetworkFailureDegradesToLookupError()

	/**
	 * A live adapter's default base URL points at the documented WOZ
	 * Bevragen sandbox (see class docblock — Kadaster publishes no stable
	 * public acceptatie hostname for this API).
	 *
	 * @return void
	 */
	public function testDefaultBaseUrlIsDocumentedSandbox(): void {
		$this->assertSame(
			'https://virtserver.swaggerhub.com/VNG-sandbox/Waardering-onroerende-zaken/1.0.0',
			WozApiAdapter::DEFAULT_BASE_URL
		);
	}//end testDefaultBaseUrlIsDocumentedSandbox()
}//end class
