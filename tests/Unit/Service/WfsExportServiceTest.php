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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\WfsExportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService shape used by WfsExportService.
 *
 * Declares the positional signature used in production so that
 * `createMock(WfsObjectServiceStub::class)` returns a configurable stub.
 * A `getMockBuilder(\stdClass::class)->addMethods([...])` stub throws
 * "Unknown named parameter" on named-arg calls in PHPUnit 10.
 */
interface WfsObjectServiceStub
{
    /**
     * Search objects (real ObjectService::searchObjects()).
     *
     * @param array<string,mixed> $query Query with @self block and field filters.
     *
     * @return array<int,mixed>|int
     */
    public function searchObjects(array $query=[]): array | int;

    /**
     * Slug-aware search bridge (real ObjectService::searchObjectsBySlug()).
     *
     * @param string              $registerSlug Register slug.
     * @param string              $schemaSlug   Schema slug.
     * @param array<string,mixed> $filters      Field filters and pagination keys.
     *
     * @return array<int,mixed>|int
     */
    public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters=[]): array | int;
}//end interface

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
        $this->settingsService = $this->createMock(originalClassName: SettingsService::class);
        $this->logger          = $this->createMock(originalClassName: LoggerInterface::class);

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

        $this->assertSame(expected: 'FeatureCollection', actual: $result['type']);
        $this->assertSame(expected: [], actual: $result['features']);

    }//end testBuildFeatureCollectionReturnsEmptyWhenObjectServiceUnavailable()

    /**
     * Test that buildFeatureCollection() converts location records to GeoJSON features.
     *
     * @return void
     */
    public function testBuildFeatureCollectionConvertsLocationsToFeatures(): void
    {
        $mockObjectService = $this->createMock(originalClassName: WfsObjectServiceStub::class);

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
            ->method('searchObjectsBySlug')
            ->willReturn($locations);

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($mockObjectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap(
                    [
                        ['register', 'procest'],
                        ['location_schema', 'location'],
                    ]
                    );

        $result = $this->service->buildFeatureCollection();

        $this->assertSame(expected: 'FeatureCollection', actual: $result['type']);
        $this->assertCount(expectedCount: 1, haystack: $result['features']);

        $feature = $result['features'][0];
        $this->assertSame(expected: 'Feature', actual: $feature['type']);
        $this->assertSame(expected: 'Point', actual: $feature['geometry']['type']);
        $this->assertSame(expected: [4.9, 52.3], actual: $feature['geometry']['coordinates']);
        $this->assertSame(expected: 'Test Case', actual: $feature['properties']['caseTitle']);
        $this->assertSame(expected: 'open', actual: $feature['properties']['caseStatus']);

    }//end testBuildFeatureCollectionConvertsLocationsToFeatures()

    /**
     * Test that buildFeatureCollection() skips locations without coordinates.
     *
     * @return void
     */
    public function testBuildFeatureCollectionSkipsLocationsWithoutCoordinates(): void
    {
        $mockObjectService = $this->createMock(originalClassName: WfsObjectServiceStub::class);

        $locations = [
            [
                '@id'    => 'loc-no-coords',
                'case'   => 'case-uuid-2',
                'source' => 'free',
                'label'  => 'No coordinates',
                // No latitude / longitude.
            ],
            [
                '@id'       => 'loc-with-coords',
                'case'      => 'case-uuid-3',
                'source'    => 'gps',
                'latitude'  => 51.9,
                'longitude' => 4.47,
            ],
        ];

        $mockObjectService
            ->method('searchObjectsBySlug')
            ->willReturn($locations);

        $this->settingsService
            ->method('getObjectService')
            ->willReturn($mockObjectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap(
                    [
                        ['register', 'procest'],
                        ['location_schema', 'location'],
                    ]
                    );

        $result = $this->service->buildFeatureCollection();

        $this->assertCount(expectedCount: 1, haystack: $result['features']);
        $this->assertSame(expected: [4.47, 51.9], actual: $result['features'][0]['geometry']['coordinates']);

    }//end testBuildFeatureCollectionSkipsLocationsWithoutCoordinates()

    /**
     * Test that buildFeatureCollection() filters features by bounding box.
     *
     * @return void
     */
    public function testBuildFeatureCollectionFiltersByBbox(): void
    {
        $mockObjectService = $this->createMock(originalClassName: WfsObjectServiceStub::class);

        $locations = [
            [
                '@id'       => 'inside',
                'case'      => 'case-1',
                'source'    => 'gps',
                'latitude'  => 52.3,
                'longitude' => 4.9,
            ],
            [
                '@id'       => 'outside',
                'case'      => 'case-2',
                'source'    => 'gps',
                'latitude'  => 51.0,
                'longitude' => 3.0,
            ],
        ];

        $mockObjectService
            ->method('searchObjectsBySlug')
            ->willReturn($locations);

        $this->settingsService->method('getObjectService')->willReturn($mockObjectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
                [
                    ['register', 'procest'],
                    ['location_schema', 'location'],
                ]
                );

        // Bbox covering Amsterdam area only.
        $result = $this->service->buildFeatureCollection(bbox: [4.5, 52.0, 5.5, 53.0]);

        $this->assertCount(expectedCount: 1, haystack: $result['features']);
        $this->assertSame(expected: 'inside', actual: $result['features'][0]['id']);

    }//end testBuildFeatureCollectionFiltersByBbox()

    /**
     * Test that maxFeatures is capped at the hard cap.
     *
     * @return void
     */
    public function testBuildFeatureCollectionCapsMaxFeaturesAtHardCap(): void
    {
        $mockObjectService = $this->createMock(originalClassName: WfsObjectServiceStub::class);

        $mockObjectService
            ->expects($this->once())
            ->method('searchObjectsBySlug')
            ->with(
                'procest',
                'location',
                $this->callback(
                        callback: function (array $params): bool {
                            return $params['_limit'] === WfsExportService::MAX_FEATURES_HARD_CAP;
                        }
                        )
            )
            ->willReturn([]);

        $this->settingsService->method('getObjectService')->willReturn($mockObjectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
                [
                    ['register', 'procest'],
                    ['location_schema', 'location'],
                ]
                );

        // Request 99999 features — must be capped at hard cap.
        $result = $this->service->buildFeatureCollection(maxFeatures: 99999);

        $this->assertSame(expected: 'FeatureCollection', actual: $result['type']);

    }//end testBuildFeatureCollectionCapsMaxFeaturesAtHardCap()

    /**
     * Test that buildCapabilities() returns a valid WFS capabilities descriptor.
     *
     * @return void
     */
    public function testBuildCapabilitiesReturnsValidDescriptor(): void
    {
        $result = $this->service->buildCapabilities('https://example.nl/api/gis/wfs');

        $this->assertSame(expected: '2.0.0', actual: $result['version']);
        $this->assertNotEmpty(actual: $result['featureTypes']);
        $this->assertSame(expected: WfsExportService::TYPE_NAME_CASES, actual: $result['featureTypes'][0]['name']);
        $this->assertStringContainsString(needle: 'https://example.nl/api/gis/wfs', haystack: $result['featureTypes'][0]['getFeatureUrl']);

    }//end testBuildCapabilitiesReturnsValidDescriptor()
}//end class
