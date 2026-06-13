<?php

/**
 * GeoService Unit Tests
 *
 * Tests for the geo helper that validates/normalises GeoJSON geometry and
 * assembles clustered case-location FeatureCollections for the cases-on-map
 * view (gis-integration spec).
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
 * @spec openspec/specs/gis-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\GeoService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService shape used by GeoService (mirrors WfsExportServiceTest).
 */
interface GeoObjectServiceStub
{
    /**
     * @param array<string,mixed> $query Query with @self block and field filters.
     *
     * @return array<int,mixed>|int
     */
    public function searchObjects(array $query=[]): array | int;

    /**
     * @param string              $registerSlug Register slug.
     * @param string              $schemaSlug   Schema slug.
     * @param array<string,mixed> $filters      Field filters and pagination keys.
     *
     * @return array<int,mixed>|int
     */
    public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters=[]): array | int;
}//end interface

/**
 * Unit tests for GeoService.
 *
 * @covers \OCA\Procest\Service\GeoService
 */
class GeoServiceTest extends TestCase
{

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var GeoService
     */
    private GeoService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(originalClassName: SettingsService::class);
        $this->logger          = $this->createMock(originalClassName: LoggerInterface::class);
        $this->service         = new GeoService(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * A valid Point geometry passes validation.
     *
     * @return void
     */
    public function testValidatePointAcceptsValidCoordinates(): void
    {
        $errors = $this->service->validateGeometry(
            ['type' => 'Point', 'coordinates' => [5.123, 52.456]]
        );
        $this->assertSame(expected: [], actual: $errors);
    }//end testValidatePointAcceptsValidCoordinates()

    /**
     * Out-of-envelope coordinates are rejected.
     *
     * @return void
     */
    public function testValidatePointRejectsOutOfRangeCoordinates(): void
    {
        $errors = $this->service->validateGeometry(
            ['type' => 'Point', 'coordinates' => [500.0, 99.0]]
        );
        $this->assertContains(needle: 'geometry.coordinates_invalid', haystack: $errors);
    }//end testValidatePointRejectsOutOfRangeCoordinates()

    /**
     * A non-array value is rejected.
     *
     * @return void
     */
    public function testValidateRejectsNonObject(): void
    {
        $this->assertSame(expected: ['geometry.not_object'], actual: $this->service->validateGeometry('nope'));
    }//end testValidateRejectsNonObject()

    /**
     * An unsupported geometry type is rejected.
     *
     * @return void
     */
    public function testValidateRejectsUnsupportedType(): void
    {
        $errors = $this->service->validateGeometry(
            ['type' => 'LineString', 'coordinates' => [[5, 52], [5.1, 52.1]]]
        );
        $this->assertContains(needle: 'geometry.type_unsupported', haystack: $errors);
    }//end testValidateRejectsUnsupportedType()

    /**
     * A valid Polygon (closed ring of >= 4 positions) passes.
     *
     * @return void
     */
    public function testValidatePolygonAcceptsClosedRing(): void
    {
        $errors = $this->service->validateGeometry(
            [
                'type'        => 'Polygon',
                'coordinates' => [[[5.1, 52.1], [5.2, 52.1], [5.2, 52.2], [5.1, 52.1]]],
            ]
        );
        $this->assertSame(expected: [], actual: $errors);
    }//end testValidatePolygonAcceptsClosedRing()

    /**
     * A polygon ring with fewer than 4 positions is rejected.
     *
     * @return void
     */
    public function testValidatePolygonRejectsShortRing(): void
    {
        $errors = $this->service->validateGeometry(
            ['type' => 'Polygon', 'coordinates' => [[[5.1, 52.1], [5.2, 52.1]]]]
        );
        $this->assertContains(needle: 'geometry.coordinates_invalid', haystack: $errors);
    }//end testValidatePolygonRejectsShortRing()

    /**
     * A MultiPolygon of valid polygons passes.
     *
     * @return void
     */
    public function testValidateMultiPolygon(): void
    {
        $ring   = [[5.1, 52.1], [5.2, 52.1], [5.2, 52.2], [5.1, 52.1]];
        $errors = $this->service->validateGeometry(
            ['type' => 'MultiPolygon', 'coordinates' => [[$ring], [$ring]]]
        );
        $this->assertSame(expected: [], actual: $errors);
    }//end testValidateMultiPolygon()

    /**
     * A Feature wrapping a valid geometry passes.
     *
     * @return void
     */
    public function testValidateFeature(): void
    {
        $errors = $this->service->validateGeometry(
            [
                'type'       => 'Feature',
                'geometry'   => ['type' => 'Point', 'coordinates' => [5.0, 52.0]],
                'properties' => ['source' => 'pdok'],
            ]
        );
        $this->assertSame(expected: [], actual: $errors);
    }//end testValidateFeature()

    /**
     * A Feature without a geometry is rejected.
     *
     * @return void
     */
    public function testValidateFeatureRequiresGeometry(): void
    {
        $errors = $this->service->validateGeometry(['type' => 'Feature', 'properties' => []]);
        $this->assertContains(needle: 'feature.geometry_required', haystack: $errors);
    }//end testValidateFeatureRequiresGeometry()

    /**
     * normaliseGeometry decodes a JSON-encoded string into a canonical Feature
     * (procest stores geometry JSON-encoded — stringify on write, parse on read).
     *
     * @return void
     */
    public function testNormaliseGeometryDecodesJsonString(): void
    {
        $json   = json_encode(['type' => 'Point', 'coordinates' => [5.123, 52.456]]);
        $result = $this->service->normaliseGeometry($json);

        $this->assertIsArray($result);
        $this->assertSame(expected: 'Feature', actual: $result['type']);
        $this->assertSame(expected: 'Point', actual: $result['geometry']['type']);
        $this->assertSame(expected: [5.123, 52.456], actual: $result['geometry']['coordinates']);
    }//end testNormaliseGeometryDecodesJsonString()

    /**
     * normaliseGeometry returns null for empty / invalid input.
     *
     * @return void
     */
    public function testNormaliseGeometryReturnsNullForGarbage(): void
    {
        $this->assertNull($this->service->normaliseGeometry(''));
        $this->assertNull($this->service->normaliseGeometry('not json'));
        $this->assertNull($this->service->normaliseGeometry(null));
    }//end testNormaliseGeometryReturnsNullForGarbage()

    /**
     * normaliseGeometry takes the first feature of a FeatureCollection.
     *
     * @return void
     */
    public function testNormaliseGeometryUnwrapsFeatureCollection(): void
    {
        $fc = [
            'type'     => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [4.0, 51.0]], 'properties' => []],
            ],
        ];
        $result = $this->service->normaliseGeometry($fc);
        $this->assertSame(expected: [4.0, 51.0], actual: $result['geometry']['coordinates']);
    }//end testNormaliseGeometryUnwrapsFeatureCollection()

    /**
     * encodeGeometry round-trips a valid geometry and refuses an invalid one.
     *
     * @return void
     */
    public function testEncodeGeometry(): void
    {
        $encoded = $this->service->encodeGeometry(['type' => 'Point', 'coordinates' => [5.123, 52.456]]);
        $this->assertIsString($encoded);
        $this->assertSame(
            expected: ['type' => 'Point', 'coordinates' => [5.123, 52.456]],
            actual: json_decode($encoded, true)
        );

        $this->assertNull($this->service->encodeGeometry(['type' => 'Bogus']));
    }//end testEncodeGeometry()

    /**
     * buildCaseGeoCollection degrades to an empty collection when OR is absent.
     *
     * @return void
     */
    public function testBuildCaseGeoCollectionDegradesWhenObjectServiceMissing(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $result = $this->service->buildCaseGeoCollection(['zoom' => 16]);

        $this->assertSame(expected: 'FeatureCollection', actual: $result['type']);
        $this->assertSame(expected: [], actual: $result['features']);
        $this->assertSame(expected: 0, actual: $result['total']);
    }//end testBuildCaseGeoCollectionDegradesWhenObjectServiceMissing()

    /**
     * At high zoom, points are returned individually (no clustering) and the
     * zaaktype/status filters apply.
     *
     * @return void
     */
    public function testBuildCaseGeoCollectionFiltersAndReturnsPointsAtHighZoom(): void
    {
        $os = $this->makeObjectService(
            [
                ['@id' => 'l1', 'case' => 'c1', 'caseType' => 'omgevingsvergunning', 'caseStatus' => 'open', 'latitude' => 52.1, 'longitude' => 5.1],
                ['@id' => 'l2', 'case' => 'c2', 'caseType' => 'subsidie',            'caseStatus' => 'open', 'latitude' => 52.2, 'longitude' => 5.2],
            ]
        );

        $result = $this->service->buildCaseGeoCollection(
            ['zoom' => 16, 'zaaktype' => 'omgevingsvergunning']
        );

        $this->assertSame(expected: 2, actual: $result['total']);
        $this->assertSame(expected: 1, actual: $result['filtered']);
        $this->assertCount(expectedCount: 1, haystack: $result['features']);
        $this->assertFalse($result['features'][0]['properties']['cluster']);
        $this->assertSame(expected: 'c1', actual: $result['features'][0]['properties']['caseId']);
    }//end testBuildCaseGeoCollectionFiltersAndReturnsPointsAtHighZoom()

    /**
     * At low zoom, nearby points collapse into a single cluster feature.
     *
     * @return void
     */
    public function testBuildCaseGeoCollectionClustersAtLowZoom(): void
    {
        $os = $this->makeObjectService(
            [
                ['@id' => 'l1', 'case' => 'c1', 'latitude' => 52.10, 'longitude' => 5.10],
                ['@id' => 'l2', 'case' => 'c2', 'latitude' => 52.11, 'longitude' => 5.11],
                ['@id' => 'l3', 'case' => 'c3', 'latitude' => 52.12, 'longitude' => 5.12],
            ]
        );

        $result = $this->service->buildCaseGeoCollection(['zoom' => 3]);

        // All three near-coincident points fall in one low-zoom grid cell.
        $this->assertCount(expectedCount: 1, haystack: $result['features']);
        $this->assertTrue($result['features'][0]['properties']['cluster']);
        $this->assertSame(expected: 3, actual: $result['features'][0]['properties']['clusterCount']);
    }//end testBuildCaseGeoCollectionClustersAtLowZoom()

    /**
     * The readableCaseIds allow-list drops locations of inaccessible cases (no IDOR).
     *
     * @return void
     */
    public function testBuildCaseGeoCollectionEnforcesReadableCaseIds(): void
    {
        $os = $this->makeObjectService(
            [
                ['@id' => 'l1', 'case' => 'c1', 'latitude' => 52.1, 'longitude' => 5.1],
                ['@id' => 'l2', 'case' => 'secret', 'latitude' => 52.2, 'longitude' => 5.2],
            ]
        );

        $result = $this->service->buildCaseGeoCollection(
            ['zoom' => 16, 'readableCaseIds' => ['c1']]
        );

        $this->assertSame(expected: 1, actual: $result['filtered']);
        $this->assertSame(expected: 'c1', actual: $result['features'][0]['properties']['caseId']);
    }//end testBuildCaseGeoCollectionEnforcesReadableCaseIds()

    /**
     * listCaseIds returns the distinct set of case ids carrying a location.
     *
     * @return void
     */
    public function testListCaseIdsReturnsDistinct(): void
    {
        $os = $this->makeObjectService(
            [
                ['case' => 'c1', 'latitude' => 52.1, 'longitude' => 5.1],
                ['case' => 'c1', 'latitude' => 52.1, 'longitude' => 5.1],
                ['case' => 'c2', 'latitude' => 52.2, 'longitude' => 5.2],
                ['case' => '',   'latitude' => 52.3, 'longitude' => 5.3],
            ]
        );

        $ids = $this->service->listCaseIds();
        sort($ids);
        $this->assertSame(expected: ['c1', 'c2'], actual: $ids);
    }//end testListCaseIdsReturnsDistinct()

    /**
     * Build a mocked ObjectService returning the given location rows, wired into
     * the settings service.
     *
     * @param array<int, array<string, mixed>> $rows Location rows.
     *
     * @return object The mocked object service.
     */
    private function makeObjectService(array $rows): object
    {
        $os = $this->createMock(originalClassName: GeoObjectServiceStub::class);
        $os->method('searchObjectsBySlug')->willReturn($rows);
        $os->method('searchObjects')->willReturn($rows);

        $this->settingsService->method('getObjectService')->willReturn($os);
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['register', '', 'register-slug'],
                ['location_schema', '', 'location-slug'],
            ]
        );

        return $os;
    }//end makeObjectService()
}//end class
