<?php

/**
 * Haal Centraal BRP adapter mapping tests (requester-on-the-case).
 *
 * Covers the one mapping this change adds to the live adapter: the GBA
 * code `geheimhoudingPersoonsgegevens` becomes the boolean
 * `indicatieGeheim` the `brpPerson` schema carries. The rule is
 * "anything but 0 is true, absent is false", so the three cases that can
 * come back from the personen-mock and the proefomgeving are asserted
 * separately: 1, 0 and no field at all.
 *
 * Mocks `IClientService`, so no network is touched. The tier resolution,
 * BSN stripping and error mapping already have coverage in
 * `IntegrationTierTest`; this file does not repeat them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External\Brp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/brp-register/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External\Brp;

use OCA\Dossiq\Service\External\Brp\HaalCentraalBrpAdapter;
use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\External\Brp\HaalCentraalBrpAdapter
 *
 * @uses \OCA\Dossiq\Service\External\Brp\BrpLookupResult
 * @uses \OCA\Dossiq\Service\External\IntegrationMode
 */
class HaalCentraalBrpAdapterTest extends TestCase {
	/**
	 * The secrecy indication maps onto `indicatieGeheim` for every value
	 * Haal Centraal can answer with, and the source field never survives
	 * onto the mapped row.
	 *
	 * @param mixed $raw      The value the API returns (or the marker
	 *                        'absent' for a response without the field).
	 * @param bool  $expected The flag the mapped row must carry.
	 *
	 * @dataProvider provideGeheimhoudingValues
	 *
	 * @return void
	 *
	 * @spec openspec/specs/brp-register/spec.md
	 */
	public function testGeheimhoudingMapsToIndicatieGeheim(mixed $raw, bool $expected): void {
		$persoon = [
			'citizenServiceNumber' => '999990792',
			'name' => ['givenNames' => 'Jan', 'namePrefix' => 'de', 'surname' => 'Cuykelaer'],
			'birth' => ['date' => '1977-12-10'],
		];

		if ($raw !== 'absent') {
			$persoon['geheimhoudingPersoonsgegevens'] = $raw;
		}

		$result = $this->adapterReturning(personen: [$persoon])->lookup(bsn: '999990792');

		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame(
			$expected,
			$result->persoon['indicatieGeheim'],
			'the mapped row must answer whether the person is protected'
		);
		$this->assertArrayNotHasKey(
			'geheimhoudingPersoonsgegevens',
			$result->persoon,
			'the source field is consumed by the mapping, not carried alongside it'
		);
	}//end testGeheimhoudingMapsToIndicatieGeheim()

	/**
	 * The values Haal Centraal answers with, and what each means.
	 *
	 * The string cases matter: JSON from the personen-mock has carried the
	 * code as a string, and a plain truthiness test would read `"0"` as
	 * TRUE, which unmasks exactly the person the flag exists to protect.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function provideGeheimhoudingValues(): array {
		return [
			'code 1 is protected' => [1, true],
			'string "1" is protected' => ['1', true],
			'code 6 is protected' => [6, true],
			'code 0 is not protected' => [0, false],
			'string "0" is not protected' => ['0', false],
			'absent is not protected' => ['absent', false],
			'null is not protected' => [null, false],
			'empty string is not protected' => ['', false],
		];
	}//end provideGeheimhoudingValues()

	/**
	 * The adapter asks the API for the secrecy indication. Mapping a field
	 * that was never requested returns false for everybody, which reads as
	 * "nobody is protected" rather than as a broken request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/brp-register/spec.md
	 */
	public function testTheAdapterRequestsTheSecrecyIndication(): void {
		$captured = [];
		$adapter = $this->adapterReturning(personen: [], captured: $captured);

		$adapter->lookup(bsn: '999990792');

		$this->assertContains(
			'geheimhoudingPersoonsgegevens',
			$captured['options']['json']['fields'],
			'the adapter must ask for the field it maps'
		);
	}//end testTheAdapterRequestsTheSecrecyIndication()

	/**
	 * An adapter over a mocked client that answers with the given personen.
	 *
	 * @param array<int, array<string,mixed>> $personen The response rows.
	 * @param array<string,mixed>             $captured Filled with the
	 *                                                  request url + options.
	 *
	 * @return HaalCentraalBrpAdapter
	 */
	private function adapterReturning(array $personen, array &$captured = []): HaalCentraalBrpAdapter {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn((string)json_encode(['personen' => $personen]));

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options = []) use (&$captured, $response) {
				$captured['url'] = $url;
				$captured['options'] = $options;
				return $response;
			}
		);

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return ($key === 'integration.brp.mode') ? 'mock' : $default;
			}
		);

		return new HaalCentraalBrpAdapter(
			clientService: $service,
			mode: new IntegrationMode(appConfig: $appConfig),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end adapterReturning()
}//end class
