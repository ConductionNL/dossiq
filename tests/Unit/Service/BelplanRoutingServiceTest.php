<?php

/**
 * BelplanRoutingService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BelplanRoutingService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fake ObjectService exposing the subset of the real OpenRegister API used by
 * BelplanRoutingService, so routing can be exercised without a live register.
 */
class FakeBelplanObjectService
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $belplannen = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $specialisten = [];

    /**
     * Mimic OpenRegister ObjectService::findObjects().
     *
     * @param string               $register The register id.
     * @param string               $schema   The schema id.
     * @param array<string, mixed> $filters  The filters (unused in the fake).
     * @param array<int, mixed>    $sort     The sort (unused).
     * @param int                  $limit    The limit (unused).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findObjects(string $register, string $schema, array $filters=[], array $sort=[], int $limit=100): array
    {
        if ($schema === 'belplan-schema') {
            return $this->belplannen;
        }

        if ($schema === 'specialist-schema') {
            return $this->specialisten;
        }

        return [];
    }//end findObjects()
}//end class

/**
 * Unit tests for BelplanRoutingService.
 *
 * @covers \OCA\Procest\Service\BelplanRoutingService
 */
class BelplanRoutingServiceTest extends TestCase
{
    /**
     * @var FakeBelplanObjectService
     */
    private FakeBelplanObjectService $objectService;

    /**
     * @var BelplanRoutingService
     */
    private BelplanRoutingService $service;

    /**
     * Set up fixtures with a configured belplan + specialists.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = new FakeBelplanObjectService();

        $this->objectService->belplannen = [
            [
                'naam'             => 'Algemeen',
                'isActive'         => true,
                'triggerNummer'    => ['14000'],
                'routeringStappen' => [
                    ['type' => 'vaardigheid_match', 'zaaktype_to_vaardigheid' => ['omgevingsvergunning' => 'omgevingsvergunningen']],
                    ['type' => 'wachtrij_overflow', 'threshold_wachttijd_sec' => 180, 'fallback_rol' => 'generalist'],
                ],
            ],
        ];

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objectService);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                         => 'reg',
                    'belplan_schema'                   => 'belplan-schema',
                    'specialist_beschikbaarheid_schema' => 'specialist-schema',
                    default                            => '',
                };
            }
        );
        $settings->method('getKccConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'belplan_overflow_threshold_wachttijd'       => '180',
                    'belplan_overflow_threshold_wachtrij_lengte' => '5',
                    default                                      => '',
                };
            }
        );

        $this->service = new BelplanRoutingService(
            settingsService: $settings,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Routing picks the available specialist with the shortest wachtrij.
     *
     * @return void
     */
    public function testRoutesToShortestQueueSpecialist(): void
    {
        $this->objectService->specialisten = [
            ['medewerkerId' => 'busy', 'status' => 'beschikbaar', 'expertises' => ['omgevingsvergunningen'], 'huidigeWachtrijLengte' => 3, 'gemiddeldeBehandelduur' => 100],
            ['medewerkerId' => 'free', 'status' => 'beschikbaar', 'expertises' => ['omgevingsvergunningen'], 'huidigeWachtrijLengte' => 0, 'gemiddeldeBehandelduur' => 120],
        ];

        $result = $this->service->routeCall('14000', 'omgevingsvergunning');

        $this->assertSame('free', $result['destinationSpecialistId']);
        $this->assertFalse($result['escalatieFlag']);
        $this->assertSame('omgevingsvergunningen', $result['vaardigheid']);
    }//end testRoutesToShortestQueueSpecialist()

    /**
     * When every specialist is busy, routing overflows with an escalatie-flag.
     *
     * @return void
     */
    public function testOverflowToGeneralistWhenAllBusy(): void
    {
        $this->objectService->specialisten = [
            ['medewerkerId' => 'a', 'status' => 'in_gesprek', 'expertises' => ['omgevingsvergunningen'], 'huidigeWachtrijLengte' => 2],
            ['medewerkerId' => 'b', 'status' => 'wrap_up', 'expertises' => ['omgevingsvergunningen'], 'huidigeWachtrijLengte' => 1],
        ];

        $result = $this->service->routeCall('14000', 'omgevingsvergunning');

        $this->assertNull($result['destinationSpecialistId']);
        $this->assertTrue($result['escalatieFlag']);
        $this->assertSame('generalist', $result['fallbackRol']);
    }//end testOverflowToGeneralistWhenAllBusy()

    /**
     * An unknown dialed number throws (no active belplan).
     *
     * @return void
     */
    public function testUnknownNumberThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->routeCall('99999', 'omgevingsvergunning');
    }//end testUnknownNumberThrows()

    /**
     * Availability filtering by vaardigheid excludes non-matching specialists.
     *
     * @return void
     */
    public function testAvailabilityFiltersByVaardigheid(): void
    {
        $this->objectService->specialisten = [
            ['medewerkerId' => 'a', 'status' => 'beschikbaar', 'expertises' => ['omgevingsvergunningen']],
            ['medewerkerId' => 'b', 'status' => 'beschikbaar', 'expertises' => ['bouwtoezicht']],
        ];

        $matched = $this->service->getSpecialistBeschikbaarheid('bouwtoezicht');

        $this->assertCount(1, $matched);
        $this->assertSame('b', $matched[0]['medewerkerId']);
    }//end testAvailabilityFiltersByVaardigheid()
}//end class
