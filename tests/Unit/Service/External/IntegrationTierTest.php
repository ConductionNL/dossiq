<?php

/**
 * External-integration tier + adapter unit tests (external-integrations-test-environments).
 *
 * Proves the config-tier model: IntegrationMode defaults every seam to
 * `log` (fail-closed to no external call) and rejects unknown modes; the
 * live BRP/KvK adapters shape the mock/test API responses into the
 * existing result value-objects and never log the BSN; the DigiD/eHerkenning
 * simulators return simulator-flagged assertions without touching SAML.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\External
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\External;

use OCA\Procest\Service\Auth\SimulatorDigidSamlAdapter;
use OCA\Procest\Service\Auth\SimulatorEHerkenningSamlAdapter;
use OCA\Procest\Service\External\Brp\HaalCentraalBrpAdapter;
use OCA\Procest\Service\External\IntegrationMode;
use OCA\Procest\Service\External\Kvk\KvkApiAdapter;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\External\IntegrationMode
 * @covers \OCA\Procest\Service\External\Brp\HaalCentraalBrpAdapter
 * @covers \OCA\Procest\Service\External\Kvk\KvkApiAdapter
 * @covers \OCA\Procest\Service\Auth\SimulatorDigidSamlAdapter
 * @covers \OCA\Procest\Service\Auth\SimulatorEHerkenningSamlAdapter
 *
 * @uses \OCA\Procest\Service\Auth\BrokerAssertionResult
 * @uses \OCA\Procest\Service\External\Brp\BrpLookupResult
 * @uses \OCA\Procest\Service\External\Kvk\KvkLookupResult
 */
class IntegrationTierTest extends TestCase
{
    /**
     * Build an IntegrationMode over a config map.
     *
     * @param array<string,string> $config integration.<x>.<y> => value.
     *
     * @return IntegrationMode
     */
    private function mode(array $config): IntegrationMode
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') use ($config): string {
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
    private function clientReturning(string $json): IClientService
    {
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
    public function testModeDefaultsToLogAndRejectsUnknown(): void
    {
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
    public function testBrpAdapterShapesFoundAndStripsBsn(): void
    {
        $body = json_encode(
                [
                    'personen' => [
                        [
                            'burgerservicenummer' => '999990627',
                            'naam'                => ['voornamen' => 'Stephan', 'geslachtsnaam' => 'Janssen'],
                            'geboorte'            => ['datum' => '1975-04-06'],
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
        $this->assertArrayNotHasKey('burgerservicenummer', $result->persoon, 'BSN must not persist in the result envelope');
        $this->assertSame('Janssen', $result->persoon['naam']['geslachtsnaam']);
    }//end testBrpAdapterShapesFoundAndStripsBsn()

    /**
     * BRP adapter degrades an empty result set to NOT_FOUND (never throws).
     *
     * @return void
     */
    public function testBrpAdapterEmptyIsNotFound(): void
    {
        $adapter = new HaalCentraalBrpAdapter(
            clientService: $this->clientReturning(json_encode(['personen' => []])),
            mode: $this->mode(['integration.brp.mode' => 'mock']),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertSame('NOT_FOUND', $adapter->lookup(bsn: '000000000')->lookupStatus);
    }//end testBrpAdapterEmptyIsNotFound()

    /**
     * KvK adapter prefers the hoofdvestiging and exposes the public test key.
     *
     * @return void
     */
    public function testKvkAdapterPrefersHoofdvestiging(): void
    {
        $this->assertSame('l7xx1f2691f2520d487b902f4e0b57a0b197', KvkApiAdapter::PUBLIC_TEST_API_KEY);
        $this->assertSame('https://api.kvk.nl/test/api', KvkApiAdapter::DEFAULT_BASE_URL);

        $body = json_encode(
                [
                    'resultaten' => [
                        ['kvkNummer' => '69599084', 'naam' => 'Test EMZ Nevenvestiging', 'type' => 'nevenvestiging'],
                        ['kvkNummer' => '69599084', 'naam' => 'Test EMZ Dagobert', 'type' => 'hoofdvestiging'],
                    ],
                ]
                );

        $adapter = new KvkApiAdapter(
            clientService: $this->clientReturning($body),
            mode: $this->mode(['integration.kvk.mode' => 'test']),
            logger: $this->createMock(LoggerInterface::class),
        );

        $result = $adapter->lookup(kvkNumber: '69599084');
        $this->assertSame('FOUND', $result->lookupStatus);
        $this->assertSame('Test EMZ Dagobert', $result->entity['naam']);
        $this->assertFalse($adapter->isDormant());
    }//end testKvkAdapterPrefersHoofdvestiging()

    /**
     * DigiD simulator returns a simulator-flagged assertion for a valid BSN
     * and refuses anything that is not a 9-digit BSN.
     *
     * @return void
     */
    public function testDigidSimulatorFlagsItselfAndValidatesBsn(): void
    {
        $adapter = new SimulatorDigidSamlAdapter();
        $this->assertTrue($adapter->isActive());

        $assertion = $adapter->decodeAssertion(json_encode(['bsn' => '999990627']), 'relay-1');
        $this->assertSame('999990627', $assertion->bsn);
        $this->assertTrue($assertion->attributes['simulator']);
        $this->assertSame('simulator', $assertion->attributes['authenticatedBy']);

        $this->expectException(RuntimeException::class);
        $adapter->decodeAssertion(json_encode(['bsn' => 'not-a-bsn']), 'relay-2');
    }//end testDigidSimulatorFlagsItselfAndValidatesBsn()

    /**
     * eHerkenning simulator returns a simulator-flagged assertion for a
     * valid KvK number and refuses non-8-digit input.
     *
     * @return void
     */
    public function testEHerkenningSimulatorFlagsItselfAndValidatesKvk(): void
    {
        $adapter   = new SimulatorEHerkenningSamlAdapter();
        $assertion = $adapter->decodeAssertion(json_encode(['kvkNummer' => '69599084']), 'relay-3');
        $this->assertSame('69599084', $assertion->kvkNummer);
        $this->assertTrue($assertion->attributes['simulator']);

        $this->expectException(RuntimeException::class);
        $adapter->decodeAssertion(json_encode(['kvkNummer' => '123']), 'relay-4');
    }//end testEHerkenningSimulatorFlagsItselfAndValidatesKvk()
}//end class
