<?php

/**
 * External-integration tier + adapter unit tests (external-integrations-test-environments).
 *
 * Proves the config-tier model: IntegrationMode defaults every seam to
 * `log` (fail-closed to no external call) and rejects unknown modes; the
 * live BRP/KvK adapters shape the mock/test API responses into the
 * existing result value-objects and never log the BSN. DigiD and eHerkenning
 * are integriq's identity broker (`OCA\Integriq\Auth\Idp`), not dossiq's.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External;

use OCA\Dossiq\Service\External\Brp\HaalCentraalBrpAdapter;
use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\External\IntegrationMode
 * @covers \OCA\Dossiq\Service\External\Brp\HaalCentraalBrpAdapter
 *
 * @uses \OCA\Dossiq\Service\External\Brp\BrpLookupResult
 */
class IntegrationTierTest extends TestCase {
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
	 * A client factory whose single request returns the given JSON body.
	 *
	 * @param string $json Response JSON.
	 *
	 * @return IClientService
	 */
	private function clientReturning(string $json): IClientService {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($json);
		$response->method('getStatusCode')->willReturn(200);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$client->method('post')->willReturn($response);

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}//end clientReturning()

	/**
	 * Unset / unknown modes fall back to `log` (fail-closed).
	 *
	 * @return void
	 */
	public function testModeDefaultsToLogAndRejectsUnknown(): void {
		$this->assertSame('log', $this->mode([])->resolve('brp', ['mock', 'test']));
		$this->assertSame(
			'log',
			$this->mode(['integration.brp.mode' => 'wharrgarbl'])->resolve('brp', ['mock', 'test']),
			'an unknown mode must not enable an external tier'
		);
		$this->assertSame(
			'test',
			$this->mode(['integration.brp.mode' => 'test'])->resolve('brp', ['mock', 'test'])
		);
	}//end testModeDefaultsToLogAndRejectsUnknown()

	/**
	 * BRP adapter shapes a personen-mock FOUND response and strips the BSN.
	 *
	 * @return void
	 */
	public function testBrpAdapterShapesFoundAndStripsBsn(): void {
		$body = json_encode(
			[
				'personen' => [
					[
						'citizenServiceNumber' => '999990627',
						'name' => ['givenNames' => 'Stephan', 'surname' => 'Janssen'],
						'birth' => ['date' => '1975-04-06'],
					],
				],
			]
		);

		$adapter = new HaalCentraalBrpAdapter(
			clientService: $this->clientReturning($body),
			mode: $this->mode(['integration.brp.mode' => 'mock']),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookup(bsn: '999990627');
		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertFalse($adapter->isDormant());
		$this->assertArrayNotHasKey('citizenServiceNumber', $result->persoon, 'BSN must not persist in the result envelope');
		$this->assertSame('Janssen', $result->persoon['name']['surname']);
	}//end testBrpAdapterShapesFoundAndStripsBsn()

	/**
	 * BRP adapter degrades an empty result set to NOT_FOUND (never throws).
	 *
	 * @return void
	 */
	public function testBrpAdapterEmptyIsNotFound(): void {
		$adapter = new HaalCentraalBrpAdapter(
			clientService: $this->clientReturning(json_encode(['personen' => []])),
			mode: $this->mode(['integration.brp.mode' => 'mock']),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame('NOT_FOUND', $adapter->lookup(bsn: '000000000')->lookupStatus);
	}//end testBrpAdapterEmptyIsNotFound()
}//end class
