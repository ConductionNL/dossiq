<?php

/**
 * BRK adapter unit tests (brk-woz-register-adapters).
 *
 * Mirrors `BagAdapterTest`'s coverage shape for the BRK adapter: request
 * building (URL/headers), the config-tier model via `IntegrationMode`,
 * dormant-default behaviour, kadastrale-aanduiding validation, and error
 * mapping (404 vs 5xx vs network failure — never throws into the caller).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External\Brk;

use OCA\Dossiq\Service\External\Brk\BrkApiAdapter;
use OCA\Dossiq\Service\External\Brk\BrkResponseMapper;
use OCA\Dossiq\Service\External\Brk\LogBrkAdapter;
use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\External\Brk\BrkApiAdapter
 * @covers \OCA\Dossiq\Service\External\Brk\LogBrkAdapter
 *
 * @uses \OCA\Dossiq\Service\External\Brk\BrkLookupResult
 * @uses \OCA\Dossiq\Service\External\Brk\BrkResponseMapper
 * @uses \OCA\Dossiq\Service\External\IntegrationMode
 */
class BrkAdapterTest extends TestCase {
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
		$this->assertSame('log', $this->mode([])->resolve('brk', [IntegrationMode::TEST, IntegrationMode::LIVE]));
	}//end testModeDefaultsToLog()

	/**
	 * The dormant LogBrkAdapter never calls out and always defers.
	 *
	 * @return void
	 */
	public function testLogAdapterDefersAndIsDormant(): void {
		$adapter = new LogBrkAdapter(logger: $this->createMock(LoggerInterface::class));

		$this->assertTrue($adapter->isDormant());

		$searchResult = $adapter->lookupByKadastraleAanduiding(kadastraleMunicipalityCode: 'VBSTD', section: 'A', perceelnummer: '1234');
		$this->assertSame('LOOKUP_DEFERRED', $searchResult->lookupStatus);
		$this->assertTrue($searchResult->dormant);
		$this->assertSame([], $searchResult->parcel);

		$objectResult = $adapter->lookupObject(id: '10280123450000');
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
		$adapter = new BrkApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['kadastraalOnroerendeZaken' => []]]), $captured),
			mode: $this->mode(['integration.brk.mode' => 'test']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertFalse($adapter->isDormant());
	}//end testApiAdapterIsNeverDormant()

	/**
	 * Kadastrale-aanduiding search builds the request against the correct
	 * resource, with the configured API key header and query params.
	 *
	 * @return void
	 */
	public function testKadastraleAanduidingSearchBuildsRequestWithHeadersAndQuery(): void {
		$body = json_encode(
			[
				'_embedded' => [
					'kadastraalOnroerendeZaken' => [
						['kadastraleAanduiding' => ['sectie' => 'A', 'perceelnummer' => 1234], 'kadastraleGrootte' => ['value' => 350]],
					],
				],
			]
		);

		$captured = [];
		$adapter = new BrkApiAdapter(
			clientService: $this->clientCapturing(200, $body, $captured),
			mode: $this->mode(['integration.brk.mode' => 'test', 'integration.brk.apiKey' => 'secret-key']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupByKadastraleAanduiding(kadastraleMunicipalityCode: 'VBSTD', section: 'a', perceelnummer: '1234');

		$this->assertStringEndsWith('/kadastraalonroerendezaken', $captured['url']);
		$this->assertSame('VBSTD', $captured['options']['query']['kadastraleGemeenteCode']);
		$this->assertSame('A', $captured['options']['query']['sectie'], 'sectie must be normalized to uppercase');
		$this->assertSame('1234', $captured['options']['query']['perceelnummer']);
		$this->assertSame('secret-key', $captured['options']['headers']['X-Api-Key']);
		$this->assertSame('application/hal+json', $captured['options']['headers']['Accept']);

		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame(350, $result->parcel['oppervlakte']);
		$this->assertSame('test', $result->extras['tier']);
		$this->assertSame(1, $result->extras['count']);
	}//end testKadastraleAanduidingSearchBuildsRequestWithHeadersAndQuery()

	/**
	 * An optional appartementsrechtVolgnummer is forwarded only when
	 * present.
	 *
	 * @return void
	 */
	public function testSearchForwardsOptionalVolgnummer(): void {
		$captured = [];
		$adapter = new BrkApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['kadastraalOnroerendeZaken' => []]]), $captured),
			mode: $this->mode(['integration.brk.mode' => 'test']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$adapter->lookupByKadastraleAanduiding(
			kadastraleMunicipalityCode: 'VBSTD',
			section: 'A',
			perceelnummer: '1234',
			appartementsrechtSequenceNumber: 'a2',
		);

		$this->assertSame('A2', $captured['options']['query']['appartementsrechtVolgnummer']);
	}//end testSearchForwardsOptionalVolgnummer()

	/**
	 * Malformed kadastrale-aanduiding input never reaches the network.
	 *
	 * @return void
	 */
	public function testInvalidKadastraleAanduidingInputIsRejectedWithoutNetworkCall(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$adapter = new BrkApiAdapter(
			clientService: $service,
			mode: $this->mode(['integration.brk.mode' => 'test']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupByKadastraleAanduiding(kadastraleMunicipalityCode: '', section: 'A', perceelnummer: '1234');
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);

		$result = $adapter->lookupByKadastraleAanduiding(kadastraleMunicipalityCode: 'VBSTD', section: 'ABC', perceelnummer: '1234');
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);

		$result = $adapter->lookupByKadastraleAanduiding(kadastraleMunicipalityCode: 'VBSTD', section: 'A', perceelnummer: 'not-a-number');
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);

		$result = $adapter->lookupByKadastraleAanduiding(
			kadastraleMunicipalityCode: 'VBSTD',
			section: 'A',
			perceelnummer: '1234',
			appartementsrechtSequenceNumber: 'invalid',
		);
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);
	}//end testInvalidKadastraleAanduidingInputIsRejectedWithoutNetworkCall()

	/**
	 * Empty search result set (200, no matches) is NOT_FOUND.
	 *
	 * @return void
	 */
	public function testSearchEmptyResultIsNotFound(): void {
		$captured = [];
		$adapter = new BrkApiAdapter(
			clientService: $this->clientCapturing(200, json_encode(['_embedded' => ['kadastraalOnroerendeZaken' => []]]), $captured),
			mode: $this->mode(['integration.brk.mode' => 'test']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupByKadastraleAanduiding(kadastraleMunicipalityCode: 'ZZZZZ', section: 'Z', perceelnummer: '99999');
		$this->assertSame('NOT_FOUND', $result->lookupStatus);
	}//end testSearchEmptyResultIsNotFound()

	/**
	 * An empty id is rejected without a network call.
	 *
	 * @return void
	 */
	public function testEmptyIdIsRejectedWithoutNetworkCall(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$adapter = new BrkApiAdapter(
			clientService: $service,
			mode: $this->mode(['integration.brk.mode' => 'test']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(id: '');
		$this->assertSame('INVALID_INPUT', $result->lookupStatus);
	}//end testEmptyIdIsRejectedWithoutNetworkCall()

	/**
	 * A true HTTP 404 on the object endpoint maps to NOT_FOUND, not an
	 * exception.
	 *
	 * @return void
	 */
	public function testObjectLookup404IsNotFound(): void {
		$captured = [];
		$adapter = new BrkApiAdapter(
			clientService: $this->clientCapturing(404, '', $captured),
			mode: $this->mode(['integration.brk.mode' => 'test']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(id: '00000000000000');
		$this->assertSame('NOT_FOUND', $result->lookupStatus);
		$this->assertStringEndsWith('/kadastraalonroerendezaken/00000000000000', $captured['url']);
	}//end testObjectLookup404IsNotFound()

	/**
	 * A found object maps to FOUND.
	 *
	 * @return void
	 */
	public function testObjectLookupFoundMapsToFound(): void {
		$captured = [];
		$adapter = new BrkApiAdapter(
			clientService: $this->clientCapturing(
				200,
				json_encode(['kadastraalOnroerendeZaak' => ['kadastraleAanduiding' => ['sectie' => 'A', 'perceelnummer' => 1]]]),
				$captured
			),
			mode: $this->mode(['integration.brk.mode' => 'test']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(id: '10280123450000');
		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('A', $result->parcel['sectie']);
	}//end testObjectLookupFoundMapsToFound()

	/**
	 * A 5xx (or any non-404 non-2xx) status degrades to LOOKUP_ERROR.
	 *
	 * @return void
	 */
	public function testObjectLookup5xxIsLookupError(): void {
		$captured = [];
		$adapter = new BrkApiAdapter(
			clientService: $this->clientCapturing(503, '', $captured),
			mode: $this->mode(['integration.brk.mode' => 'test']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(id: '10280123450000');
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
		$adapter = new BrkApiAdapter(
			clientService: $this->clientThrowing(),
			mode: $this->mode(['integration.brk.mode' => 'test']),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$searchResult = $adapter->lookupByKadastraleAanduiding(kadastraleMunicipalityCode: 'VBSTD', section: 'A', perceelnummer: '1234');
		$this->assertSame('LOOKUP_ERROR', $searchResult->lookupStatus);
		$this->assertSame('transport-error', $searchResult->extras['reason']);

		$objectResult = $adapter->lookupObject(id: '10280123450000');
		$this->assertSame('LOOKUP_ERROR', $objectResult->lookupStatus);
	}//end testNetworkFailureDegradesToLookupError()

	/**
	 * A live adapter's default base URL points at Kadaster's BRK Bevragen
	 * API-key test environment.
	 *
	 * @return void
	 */
	public function testDefaultBaseUrlIsApiKeyTestEnvironment(): void {
		$this->assertSame(
			'https://api.brk.kadaster.nl/esd-eto-apikey/bevragen/v2',
			BrkApiAdapter::DEFAULT_BASE_URL
		);
	}//end testDefaultBaseUrlIsApiKeyTestEnvironment()
}//end class
