<?php

/**
 * WOZ contract test (brk-woz-register-adapters).
 *
 * Offline contract lane: feeds the live adapter recorded/schema-accurate
 * fixtures of the Kadaster Haal Centraal WOZ Bevragen `/wozobjecten`
 * search and singular-resource shapes and asserts the adapter honours the
 * koppelvlak contract (HAL+JSON `_embedded.wozObjecten` envelope, singular
 * `wozObject` envelope, field mapping including most-recent-valuation
 * selection). Mirrors `BagContractTest` / `BrkContractTest`.
 *
 * Like BAG/BRK, this lane runs against recorded fixtures built from
 * Kadaster's published API schema/examples, not a live network call — no
 * network access required.
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
 * @covers \OCA\Procest\Service\External\Woz\WozResponseMapper
 *
 * @uses \OCA\Procest\Service\External\IntegrationMode
 * @uses \OCA\Procest\Service\External\Woz\WozLookupResult
 */
class WozContractTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../../fixtures/contracts/woz';

    /**
     * Build an IntegrationMode returning $mode for the `woz` integration.
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
                if ($key === 'integration.woz.mode') {
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
     * @param string $fixture Path under tests/fixtures/contracts/woz.
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
     * The adapter honours the `/wozobjecten` HAL+JSON search contract,
     * including selecting the most recent valuation.
     *
     * @return void
     */
    public function testAddressContractAgainstSearchEnvelope(): void
    {
        $adapter = new WozApiAdapter(
            clientService: $this->clientReturningFixture('wozobjecten-search.json'),
            mode: $this->mode(IntegrationMode::TEST),
            mapper: new WozResponseMapper(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $result = $adapter->lookupAddress(postcode: '1234AB', huisnummer: '10');
        $this->assertSame('FOUND', $result->lookupStatus);
        $this->assertSame('05180000001234', $result->wozObject['wozobjectnummer']);
        $this->assertSame('0518010000123456', $result->wozObject['nummeraanduidingId']);
        $this->assertSame(250, $result->wozObject['grondoppervlakte']);
        $this->assertSame(['woonfunctie'], $result->wozObject['gebruiksdoel']);
        $this->assertSame(385000, $result->wozObject['waarde']);
        $this->assertSame('2025-01-01', $result->wozObject['waardepeildatum']);
        $this->assertSame('test', $result->extras['tier']);
    }//end testAddressContractAgainstSearchEnvelope()

    /**
     * The adapter honours the singular `wozObject` resource envelope.
     *
     * @return void
     */
    public function testObjectContractAgainstSingularEnvelope(): void
    {
        $adapter = new WozApiAdapter(
            clientService: $this->clientReturningFixture('wozobject-05180000001234.json'),
            mode: $this->mode(IntegrationMode::TEST),
            mapper: new WozResponseMapper(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $result = $adapter->lookupByWozObjectNummer(wozobjectnummer: '05180000001234');
        $this->assertSame('FOUND', $result->lookupStatus);
        $this->assertSame(385000, $result->wozObject['waarde']);
        $this->assertSame('2025-01-01', $result->wozObject['waardepeildatum']);
    }//end testObjectContractAgainstSingularEnvelope()
}//end class
