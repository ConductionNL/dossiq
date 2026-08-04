<?php

/**
 * BAG contract test (bag-register-adapter).
 *
 * Offline contract lane: feeds the live adapter recorded/schema-accurate
 * fixtures of the Kadaster BAG API Individuele Bevragingen v2
 * `/adressen` and `/panden/{id}` shapes and asserts the adapter honours
 * the koppelvlak contract (HAL+JSON `_embedded.adressen` envelope,
 * singular `pand` envelope, field mapping). Mirrors `BrpKvkContractTest`.
 *
 * Unlike KvK (which ships a public, shared, zero-registration TEST api
 * key) Kadaster's BAG API Individuele Bevragingen has no equivalent
 * published shared key — see `openspec/changes/bag-register-adapter/design.md`
 * ("Known trade-off"). This lane therefore runs against recorded fixtures
 * built from Kadaster's published API schema/examples, not a live network
 * call — no network access required, same as the BRP mock / KvK test
 * lanes already do in CI.
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
use OCA\Procest\Service\External\IntegrationMode;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\External\Bag\BagApiAdapter
 * @covers \OCA\Procest\Service\External\Bag\BagResponseMapper
 *
 * @uses \OCA\Procest\Service\External\Bag\BagLookupResult
 * @uses \OCA\Procest\Service\External\IntegrationMode
 */
class BagContractTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../../fixtures/contracts/bag';

    /**
     * Build an IntegrationMode returning $mode for the `bag` integration.
     *
     * @param string $mode Tier.
     *
     * @return IntegrationMode
     */
    private function mode(string $mode): IntegrationMode
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') use ($mode): string {
                if ($key === 'integration.bag.mode') {
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
     * @param string $fixture Path under tests/fixtures/contracts/bag.
     *
     * @return IClientService
     */
    private function clientReturningFixture(string $fixture): IClientService
    {
        $body = file_get_contents(self::FIXTURES.'/'.$fixture);
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
     * The adapter honours the `/adressen` HAL+JSON contract.
     *
     * @return void
     */
    public function testAddressContractAgainstAdressenEnvelope(): void
    {
        $adapter = new BagApiAdapter(
            clientService: $this->clientReturningFixture('adressen-1234ab-10.json'),
            mode: $this->mode(IntegrationMode::TEST),
            mapper: new BagResponseMapper(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $result = $adapter->lookupAddress(postcode: '1234AB', huisnummer: '10');
        $this->assertSame('FOUND', $result->lookupStatus);
        $this->assertSame('Voorstraat', $result->address['street']);
        $this->assertSame('1234AB', $result->address['postcode']);
        $this->assertSame('Voorbeeldstad', $result->address['city']);
        $this->assertSame(['woonfunctie'], $result->address['gebruiksdoel']);
        $this->assertSame(1998, $result->address['oorspronkelijkBouwjaar']);
        $this->assertSame(120, $result->address['oppervlakte']);
        $this->assertSame(['lng' => 4.4699, 'lat' => 51.9244], $result->address['geo']);
        $this->assertSame('test', $result->extras['tier']);
    }//end testAddressContractAgainstAdressenEnvelope()

    /**
     * The adapter honours the singular `pand` resource envelope.
     *
     * @return void
     */
    public function testPandContractAgainstSingularEnvelope(): void
    {
        $adapter = new BagApiAdapter(
            clientService: $this->clientReturningFixture('pand-0518100000123456.json'),
            mode: $this->mode(IntegrationMode::TEST),
            mapper: new BagResponseMapper(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $result = $adapter->lookupObject(objectType: 'pand', id: '0518100000123456');
        $this->assertSame('FOUND', $result->lookupStatus);
        $this->assertSame(1998, $result->address['oorspronkelijkBouwjaar']);
        $this->assertNull($result->address['geo'], 'pand geometry is a vlak polygon, not a punt — geo must be null');
    }//end testPandContractAgainstSingularEnvelope()
}//end class
