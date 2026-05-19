<?php

/**
 * WfsExportService Unit Tests
 *
 * Tests for the WFS export service that builds GeoJSON FeatureCollections
 * from case location objects (gis-integration AC 6).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gis-integration/tasks.md#task-22
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\WfsExportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WfsExportService.
 *
 * @covers \OCA\Procest\Service\WfsExportService
 */
class WfsExportServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var WfsExportService
     */
    private WfsExportService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new WfsExportService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that buildFeatureCollection() returns empty features when ObjectService unavailable.
     *
     * @return void
     */
    public function testBuildFeatureCollectionReturnsEmptyWhenObjectServiceUnavailable(): void
    {
        $this->settingsService
            ->method('getObjectService')
            ->willReturn(null);

        $result = $this->service->buildFeatureCollection();

        $this->assertSame('FeatureCollection', $result['type']);
        $this->assertSame([], $result['features']);

    }//end testBuildFeatureCollectionReturnsEmptyWhenObjectServiceUnavailable()


    /**
     * Test that buildFeatureCollection() converts location records to GeoJSON features.
     *
     * @return void
     */
    public function testBuildFeatureCollectionConvertsLocationsToFeatures(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);

        $locations = [
            [
                '@id'              => 'loc-uuid-1',
                'case'             => 'case-uuid-1',
                'caseTitle'        => 'Test Case',
                'caseStatus'       => 'open',
                'caseType'         => 'Omgevingsvergunning',
                'source'           => 'bag',
                'formattedAddress' => 'Teststraat 1, Amsterdam',
                'latitude'         => 52.3,
                'longitude'        => 4.9,
            ],
        ];

        $mockObjectService
            ->method('findObjects')
            ->willReturn($locations);

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($mockObjectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap([
                ['register', 'procest'],
                ['location_schema', 'location'],
            ]);

        $result = $this->service->buildFeatureCollection();

        $this->assertSame('FeatureCollection', $result['type']);
        $this->assertCount(1, $result['features']);

        $feature = $result['features'][0];
        $this->assertSame('Feature', $feature['type']);
        $this->assertSame('Point', $feature['geometry']['type']);
        $this->assertSame([4.9, 52.3], $feature['geometry']['coordinates']);
        $this->assertSame('Test Case', $feature['properties']['caseTitle']);
        $this->assertSame('open', $feature['properties']['caseStatus']);

    }//end testBuildFeatureCollectionConvertsLocationsToFeatures()


    /**
     * Test that buildFeatureCollection() skips locations without coordinates.
     *
     * @return void
     */
    public function testBuildFeatureCollectionSkipsLocationsWithoutCoordinates(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);

        $locations = [
            [
                '@id'   => 'loc-no-coords',
                'case'  => 'case-uuid-2',
                'source' => 'free',
                'label' => 'No coordinates',
                // No latitude / longitude.
            ],
            [
                '@id'      => 'loc-with-coords',
                'case'     => 'case-uuid-3',
                'source'   => 'gps',
                'latitude'  => 51.9,
                'longitude' => 4.47,
            ],
        ];

        $mockObjectService
            ->method('findObjects')
            ->willReturn($locations);

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($mockObjectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap([
                ['register', 'procest'],
                ['location_schema', 'location'],
            ]);

        $result = $this->service->buildFeatureCollection();

        $this->assertCount(1, $result['features']);
        $this->assertSame([4.47, 51.9], $result['features'][0]['geometry']['coordinates']);

    }//end testBuildFeatureCollectionSkipsLocationsWithoutCoordinates()


    /**
     * Test that buildFeatureCollection() filters features by bounding box.
     *
     * @return void
     */
    public function testBuildFeatureCollectionFiltersByBbox(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);

        $locations = [
            [
                '@id'      => 'inside',
                'case'     => 'case-1',
                'source'   => 'gps',
                'latitude'  => 52.3,
                'longitude' => 4.9,
            ],
            [
                '@id'      => 'outside',
                'case'     => 'case-2',
                'source'   => 'gps',
                'latitude'  => 51.0,
                'longitude' => 3.0,
            ],
        ];

        $mockObjectService
            ->method('findObjects')
            ->willReturn($locations);

        $this->settingsService->method('getObjectService')->willReturn($mockObjectService);
        $this->settingsService->method('getConfigValue')->willReturnMap([
            ['register', 'procest'],
            ['location_schema', 'location'],
        ]);

        // Bbox covering Amsterdam area only.
        $result = $this->service->buildFeatureCollection(bbox: [4.5, 52.0, 5.5, 53.0]);

        $this->assertCount(1, $result['features']);
        $this->assertSame('inside', $result['features'][0]['id']);

    }//end testBuildFeatureCollectionFiltersByBbox()


    /**
     * Test that maxFeatures is capped at the hard cap.
     *
     * @return void
     */
    public function testBuildFeatureCollectionCapsMaxFeaturesAtHardCap(): void
    {
        $mockObjectService = $this->createMock(\stdClass::class);

        $mockObjectService
            ->expects($this->once())
            ->method('findObjects')
            ->with(
                'procest',
                'location',
                $this->callback(function (array $params): bool {
                    return $params['_limit'] === WfsExportService::MAX_FEATURES_HARD_CAP;
                })
            )
            ->willReturn([]);

        $this->settingsService->method('getObjectService')->willReturn($mockObjectService);
        $this->settingsService->method('getConfigValue')->willReturnMap([
            ['register', 'procest'],
            ['location_schema', 'location'],
        ]);

        // Request 99999 features — must be capped at hard cap.
        $result = $this->service->buildFeatureCollection(maxFeatures: 99999);

        $this->assertSame('FeatureCollection', $result['type']);

    }//end testBuildFeatureCollectionCapsMaxFeaturesAtHardCap()


    /**
     * Test that buildCapabilities() returns a valid WFS capabilities descriptor.
     *
     * @return void
     */
    public function testBuildCapabilitiesReturnsValidDescriptor(): void
    {
        $result = $this->service->buildCapabilities('https://example.nl/api/gis/wfs');

        $this->assertSame('2.0.0', $result['version']);
        $this->assertNotEmpty($result['featureTypes']);
        $this->assertSame(WfsExportService::TYPE_NAME_CASES, $result['featureTypes'][0]['name']);
        $this->assertStringContainsString('https://example.nl/api/gis/wfs', $result['featureTypes'][0]['getFeatureUrl']);

    }//end testBuildCapabilitiesReturnsValidDescriptor()


}//end class
