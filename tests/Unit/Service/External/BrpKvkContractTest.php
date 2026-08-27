<?php

/**
 * BRP + KvK contract test (external-integrations-test-environments).
 *
 * Offline contract lane: feeds the live adapters the REAL recorded
 * responses of the test tiers — a `ghcr.io/brp-api/personen-mock`
 * `/personen` envelope and a verbatim `api.kvk.nl/test/api/v2/zoeken`
 * response for pinned KVK 69599084 (fetched 2026-07-06) — and asserts
 * the adapter honours the koppelvlak contract (envelope keys, hoofd-
 * vestiging preference, BSN stripping). This runs in PR CI with no
 * network: the fixtures ARE the mock/test-API contract, kept in sync
 * with the brp-kvk-register-sets seeds. The full docker-service /
 * network lanes (T03/T05) run on deploy against the real mock + test
 * API; this proves the request/response mapping deterministically.
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
use OCA\Dossiq\Service\External\Kvk\KvkApiAdapter;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\External\Brp\HaalCentraalBrpAdapter
 * @covers \OCA\Dossiq\Service\External\Kvk\KvkApiAdapter
 *
 * @uses \OCA\Dossiq\Service\External\Brp\BrpLookupResult
 * @uses \OCA\Dossiq\Service\External\IntegrationMode
 * @uses \OCA\Dossiq\Service\External\Kvk\KvkLookupResult
 */
class BrpKvkContractTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../fixtures/contracts';

	/**
	 * Build an IntegrationMode returning $mode for $integration.
	 *
	 * @param string $integration Integration name.
	 * @param string $mode Tier.
	 *
	 * @return IntegrationMode
	 */
	private function mode(string $integration, string $mode): IntegrationMode {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($integration, $mode): string {
				if ($key === 'integration.' . $integration . '.mode') {
					return $mode;
				}

				return $default;
			}
		);

		return new IntegrationMode(appConfig: $appConfig);
	}//end mode()

	/**
	 * Client factory returning the given fixture body.
	 *
	 * @param string $fixture Path under tests/fixtures/contracts.
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
		$client->method('post')->willReturn($response);

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}//end clientReturningFixture()

	/**
	 * The BRP adapter honours the personen-mock `/personen` contract.
	 *
	 * @return void
	 */
	public function testBrpContractAgainstMockEnvelope(): void {
		$adapter = new HaalCentraalBrpAdapter(
			clientService: $this->clientReturningFixture('brp/personen-999990627.json'),
			mode: $this->mode('brp', IntegrationMode::MOCK),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookup(bsn: '999990627');
		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('Janssen', $result->persoon['name']['surname']);
		$this->assertSame('1975-04-06', $result->persoon['birth']['date']);
		$this->assertArrayNotHasKey('citizenServiceNumber', $result->persoon);
		$this->assertSame('mock', $result->extras['tier']);
	}//end testBrpContractAgainstMockEnvelope()

	/**
	 * The KvK adapter honours the api.kvk.nl/test Zoeken contract for the
	 * pinned fictitious company 69599084.
	 *
	 * @return void
	 */
	public function testKvkContractAgainstTestApiResponse(): void {
		$adapter = new KvkApiAdapter(
			clientService: $this->clientReturningFixture('kvk/zoeken-69599084.json'),
			mode: $this->mode('kvk', IntegrationMode::TEST),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->lookup(kvkNumber: '69599084');
		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('69599084', $result->kvkNumber);
		$this->assertStringContainsString('Test EMZ', (string)$result->entity['name']);
		$this->assertSame('test', $result->extras['tier']);
	}//end testKvkContractAgainstTestApiResponse()
}//end class
