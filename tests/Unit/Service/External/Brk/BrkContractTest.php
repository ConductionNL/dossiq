<?php

/**
 * BRK contract test (brk-woz-register-adapters).
 *
 * Offline contract lane: feeds the live adapter recorded/schema-accurate
 * fixtures of the Kadaster Haal Centraal BRK Bevragen v2
 * `/kadastraalonroerendezaken` search and singular-resource shapes and
 * asserts the adapter honours the koppelvlak contract (HAL+JSON
 * `_embedded.kadastraalOnroerendeZaken` envelope, singular
 * `kadastraalOnroerendeZaak` envelope, field mapping). Mirrors
 * `BagContractTest`.
 *
 * Like BAG, this lane runs against recorded fixtures built from Kadaster's
 * published API schema/examples, not a live network call — no network
 * access required.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\External\Brk;

use OCA\Procest\Service\External\Brk\BrkApiAdapter;
use OCA\Procest\Service\External\Brk\BrkResponseMapper;
use OCA\Procest\Service\External\IntegrationMode;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\External\Brk\BrkApiAdapter
 * @covers \OCA\Procest\Service\External\Brk\BrkResponseMapper
 *
 * @uses \OCA\Procest\Service\External\Brk\BrkLookupResult
 * @uses \OCA\Procest\Service\External\IntegrationMode
 */
class BrkContractTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../../fixtures/contracts/brk';

	/**
	 * Build an IntegrationMode returning $mode for the `brk` integration.
	 *
	 * @param string $mode Tier.
	 *
	 * @return IntegrationMode
	 */
	private function mode(string $mode): IntegrationMode {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($mode): string {
				if ($key === 'integration.brk.mode') {
					return $mode;
				}

				return $default;
			}
		);

		return new IntegrationMode(appConfig: $appConfig);
	}//end mode()

	/**
	 * Client factory returning the given fixture body with a 200 status.
	 *
	 * @param string $fixture Path under tests/fixtures/contracts/brk.
	 *
	 * @return IClientService
	 */
	private function clientReturningFixture(string $fixture): IClientService {
		$body = file_get_contents(self::FIXTURES . '/' . $fixture);
		$this->assertIsString($body, "fixture {$fixture} must exist");

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getStatusCode')->willReturn(200);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}//end clientReturningFixture()

	/**
	 * The adapter honours the `/kadastraalonroerendezaken` HAL+JSON search
	 * contract.
	 *
	 * @return void
	 */
	public function testKadastraleAanduidingContractAgainstSearchEnvelope(): void {
		$adapter = new BrkApiAdapter(
			clientService: $this->clientReturningFixture('kadastraalonroerendezaken-search.json'),
			mode: $this->mode(IntegrationMode::TEST),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupByKadastraleAanduiding(kadastraleMunicipalityCode: 'VBSTD', section: 'A', perceelnummer: '1234');
		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('Voorbeeldstad', $result->parcel['kadastraleGemeente']);
		$this->assertSame('VBSTD', $result->parcel['kadastraleGemeenteCode']);
		$this->assertSame('A', $result->parcel['sectie']);
		$this->assertSame(1234, $result->parcel['perceelnummer']);
		$this->assertSame(350, $result->parcel['oppervlakte']);
		$this->assertSame(['wonen'], $result->parcel['soortCultuurBebouwd']);
		$this->assertSame('ZG0001', $result->parcel['zakelijkGerechtigden'][0]['identificatie']);
		$this->assertSame(['lng' => 4.4699, 'lat' => 51.9244], $result->parcel['geo']);
		$this->assertSame('test', $result->extras['tier']);
	}//end testKadastraleAanduidingContractAgainstSearchEnvelope()

	/**
	 * The adapter honours the singular `kadastraalOnroerendeZaak` resource
	 * envelope.
	 *
	 * @return void
	 */
	public function testObjectContractAgainstSingularEnvelope(): void {
		$adapter = new BrkApiAdapter(
			clientService: $this->clientReturningFixture('kadastraalonroerendezaak-10280123450000.json'),
			mode: $this->mode(IntegrationMode::TEST),
			mapper: new BrkResponseMapper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookupObject(id: '10280123450000');
		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame(350, $result->parcel['oppervlakte']);
		$this->assertNull($result->parcel['geo'], 'the id-lookup fixture carries no centroideLL — geo must be null');
	}//end testObjectContractAgainstSingularEnvelope()
}//end class
