<?php

/**
 * BAG adapter unit tests (bag-register-adapter).
 *
 * Mirrors `IntegrationTierTest`'s coverage shape for the BRP/KvK adapters:
 * request building (URL/headers), the config-tier model via
 * `IntegrationMode`, dormant-default behaviour, Dutch postcode validation,
 * and error mapping (404 vs 5xx vs network failure — never throws into
 * the caller).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\External\Bag
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\External\Bag;

use OCA\Procest\Service\External\Bag\BagApiAdapter;
use OCA\Procest\Service\External\Bag\BagResponseMapper;
use OCA\Procest\Service\External\Bag\LogBagAdapter;
use OCA\Procest\Service\External\IntegrationMode;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\External\Bag\BagApiAdapter
 * @covers \OCA\Procest\Service\External\Bag\LogBagAdapter
 *
 * @uses \OCA\Procest\Service\External\Bag\BagLookupResult
 * @uses \OCA\Procest\Service\External\Bag\BagResponseMapper
 * @uses \OCA\Procest\Service\External\IntegrationMode
 */
class BagAdapterTest extends TestCase {
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
		$this->assertSame('log', $this->mode([])->resolve('bag', [IntegrationMode::TEST, IntegrationMode::LIVE]));
	}//end testModeDefaultsToLog()

	/**
	 * The dormant LogBagAdapter never calls out and always defers.
	 *
	 * @return void
	 */
	public function testLogAdapterDefersAndIsDormant(): void {
		$adapter = new LogBagAdapter(logger: $this->createMock(LoggerInterface::class));

		$this->assertTrue($adapter->isDormant());

		$addressResult = $adapter->lookupAddress(postcode: '1234AB', houseNumber: '10');
		$this->assertSame('LOOKUP_DEFERRED', $addressResult->lookupStatus);
		$this->assertTrue($addressResult->dormant);
		$this->assertSame([], $addressResult->address);

		$objectResult = $adapter->lookupObject(objectType: 'pand', id: '0518100000123456');
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
		$adapter = new BagApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['adressen' => []]]), $captured),
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertFalse($adapter->isDormant());
	}//end testApiAdapterIsNeverDormant()

	/**
	 * Address lookup builds the request against the correct resource,
	 * with the configured API key + Accept-Crs headers.
	 *
	 * @return void
	 */
	public function testAddressLookupBuildsRequestWithHeadersAndQuery(): void {
		$body = json_encode(
			[
				'_embedded' => [
					'adressen' => [
						['postcode' => '1234AB', 'huisnummer' => 10, 'openbareRuimteNaam' => 'Voorstraat', 'woonplaatsNaam' => 'Voorbeeldstad'],
					],
				],
			]
		);

		$captured = [];
		$adapter = new BagApiAdapter(
			clientService: $this->clientCapturing(200, $body, $captured),
			mode: $this->mode(['integration.bag.mode' => 'test', 'integration.bag.apiKey' => 'secret-key']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupAddress(postcode: '1234ab', houseNumber: '10');

		$this->assertStringEndsWith('/adressen', $captured['url']);
		$this->assertSame('1234AB', $captured['options']['query']['postcode'], 'postcode must be normalized to uppercase, no spaces');
		$this->assertSame('10', $captured['options']['query']['huisnummer']);
		$this->assertSame('secret-key', $captured['options']['headers']['X-Api-Key']);
		$this->assertSame('epsg:4326', $captured['options']['headers']['Accept-Crs']);
		$this->assertSame('application/hal+json', $captured['options']['headers']['Accept']);

		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('Voorstraat', $result->address['street']);
		$this->assertSame('test', $result->extras['tier']);
		$this->assertSame(1, $result->extras['count']);
	}//end testAddressLookupBuildsRequestWithHeadersAndQuery()

	/**
	 * Optional huisletter/toevoeging are forwarded only when present.
	 *
	 * @return void
	 */
	public function testAddressLookupForwardsOptionalHuisletterAndToevoeging(): void {
		$captured = [];
		$adapter = new BagApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['adressen' => []]]), $captured),
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$adapter->lookupAddress(postcode: '1234AB', houseNumber: '10', huisletter: 'A', toevoeging: 'II');

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

		$adapter = new BagApiAdapter(
			clientService: $service,
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		foreach (['0001AB', '1234A', '1234ABC', '12345A', 'ABCD12', ''] as $badPostcode) {
			$result = $adapter->lookupAddress(postcode: $badPostcode, houseNumber: '10');
			$this->assertSame('INVALID_INPUT', $result->lookupStatus, "postcode '{$badPostcode}' must be rejected");
		}
	}//end testInvalidPostcodeIsRejectedWithoutNetworkCall()

	/**
	 * A well-formed postcode with lowercase letters / no separators is
	 * accepted (normalized before validation).
	 *
	 * @return void
	 */
	public function testWellFormedPostcodeVariantsAreAccepted(): void {
		foreach (['1234AB', '1234ab'] as $goodPostcode) {
			$captured = [];
			$adapter = new BagApiAdapter(
				clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['adressen' => []]]), $captured),
				mode: $this->mode(['integration.bag.mode' => 'test']),
				mapper: new BagResponseMapper(),
				logger: $this->createMock(LoggerInterface::class),
			);

			$result = $adapter->lookupAddress(postcode: $goodPostcode, houseNumber: '10');
			$this->assertSame('NOT_FOUND', $result->lookupStatus, "postcode '{$goodPostcode}' must be accepted and reach the network");
		}
	}//end testWellFormedPostcodeVariantsAreAccepted()

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

		$adapter = new BagApiAdapter(
			clientService: $service,
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(objectType: 'unknown-type', id: 'x');
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);

		$result = $adapter->lookupAddress(postcode: '1234AB', houseNumber: 'abc');
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);
	}//end testInvalidHuisnummerIsRejected()

	/**
	 * Empty address result set (200, no matches) is NOT_FOUND.
	 *
	 * @return void
	 */
	public function testAddressLookupEmptyResultIsNotFound(): void {
		$captured = [];
		$adapter = new BagApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['adressen' => []]]), $captured),
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupAddress(postcode: '9999ZZ', houseNumber: '1');
		$this->assertSame('NOT_FOUND', $result->lookupStatus);
	}//end testAddressLookupEmptyResultIsNotFound()

	/**
	 * A true HTTP 404 on the object endpoint maps to NOT_FOUND, not an
	 * exception.
	 *
	 * @return void
	 */
	public function testObjectLookup404IsNotFound(): void {
		$captured = [];
		$adapter = new BagApiAdapter(
			clientService: $this->clientCapturing(404, '', $captured),
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(objectType: 'pand', id: '0000000000000000');
		$this->assertSame('NOT_FOUND', $result->lookupStatus);
		$this->assertStringEndsWith('/panden/0000000000000000', $captured['url']);
	}//end testObjectLookup404IsNotFound()

	/**
	 * `nummeraanduiding` is a supported object type (bag-location-save-validation)
	 * — it hits `/nummeraanduidingen/{id}` and maps 404 → NOT_FOUND, 2xx →
	 * FOUND, exactly like `pand`/`verblijfsobject`.
	 *
	 * @return void
	 */
	public function testNummeraanduidingLookupHitsCorrectResourceAndMaps404(): void {
		$captured = [];
		$adapter = new BagApiAdapter(
			clientService: $this->clientCapturing(404, '', $captured),
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(objectType: 'nummeraanduiding', id: '0363010000123456');
		$this->assertSame('NOT_FOUND', $result->lookupStatus);
		$this->assertStringEndsWith('/nummeraanduidingen/0363010000123456', $captured['url']);
	}//end testNummeraanduidingLookupHitsCorrectResourceAndMaps404()

	/**
	 * A found nummeraanduiding maps to FOUND.
	 *
	 * @return void
	 */
	public function testNummeraanduidingLookupFoundMapsToFound(): void {
		$captured = [];
		$adapter = new BagApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['nummeraanduiding' => ['postcode' => '1234AB']]), $captured),
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(objectType: 'nummeraanduiding', id: '0363010000123456');
		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('1234AB', $result->address['postcode']);
	}//end testNummeraanduidingLookupFoundMapsToFound()

	/**
	 * A 5xx (or any non-404 non-2xx) status degrades to LOOKUP_ERROR.
	 *
	 * @return void
	 */
	public function testObjectLookup5xxIsLookupError(): void {
		$captured = [];
		$adapter = new BagApiAdapter(
			clientService: $this->clientCapturing(503, '', $captured),
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(objectType: 'verblijfsobject', id: '0518010000123456');
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
		$adapter = new BagApiAdapter(
			clientService: $this->clientThrowing(),
			mode: $this->mode(['integration.bag.mode' => 'test']),
			mapper: new BagResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$addressResult = $adapter->lookupAddress(postcode: '1234AB', houseNumber: '10');
		$this->assertSame('LOOKUP_ERROR', $addressResult->lookupStatus);
		$this->assertSame('transport-error', $addressResult->extras['reason']);

		$objectResult = $adapter->lookupObject(objectType: 'pand', id: '0518100000123456');
		$this->assertSame('LOOKUP_ERROR', $objectResult->lookupStatus);
	}//end testNetworkFailureDegradesToLookupError()

	/**
	 * A live adapter's default base URL points at Kadaster's acceptatie
	 * (test) environment.
	 *
	 * @return void
	 */
	public function testDefaultBaseUrlIsAcceptatie(): void {
		$this->assertSame(
			'https://api.bag.acceptatie.kadaster.nl/lvbag/individuelebevragingen/v2',
			BagApiAdapter::DEFAULT_BASE_URL
		);
	}//end testDefaultBaseUrlIsAcceptatie()
}//end class
