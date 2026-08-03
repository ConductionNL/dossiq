<?php

/**
 * MapTileService Unit Tests
 *
 * Tests the offline-map tile-manifest builder.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-6
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\MapTileService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\MapTileService
 */
class MapTileServiceTest extends TestCase
{
    private MapTileService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new MapTileService();
    }//end setUp()

    /**
     * Single zoom level over Amsterdam-Centrum-ish bbox.
     *
     * @return void
     */
    public function testManifestSingleTileForVerySmallBbox(): void
    {
        $result = $this->service->buildManifest(
            bbox: ['minLat' => 52.370, 'minLon' => 4.890, 'maxLat' => 52.371, 'maxLon' => 4.891],
            zoomLevels: [10]
        );

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['tiles']);
        self::assertSame(10, $result['tiles'][0]['z']);
        self::assertNotEmpty($result['tiles'][0]['url']);
        self::assertStringContainsString('10/', $result['tiles'][0]['url']);
    }//end testManifestSingleTileForVerySmallBbox()

    /**
     * @return void
     */
    public function testManifestCoversMultipleZooms(): void
    {
        $result = $this->service->buildManifest(
            bbox: ['minLat' => 52.30, 'minLon' => 4.80, 'maxLat' => 52.45, 'maxLon' => 5.05],
            zoomLevels: [10, 11]
        );

        self::assertGreaterThan(2, $result['total']);
        $zooms = array_unique(array_column($result['tiles'], 'z'));
        sort($zooms);
        self::assertSame([10, 11], $zooms);
    }//end testManifestCoversMultipleZooms()

    /**
     * @return void
     */
    public function testEstimateMatchesBuildManifestTotal(): void
    {
        $bbox = ['minLat' => 52.30, 'minLon' => 4.80, 'maxLat' => 52.45, 'maxLon' => 5.05];
        $zooms = [10, 11];

        $estimate = $this->service->estimate(bbox: $bbox, zoomLevels: $zooms);
        $manifest = $this->service->buildManifest(bbox: $bbox, zoomLevels: $zooms);

        self::assertSame($estimate['total'], $manifest['total']);
        self::assertSame($estimate['estimatedSizeKiB'], $manifest['estimatedSizeKiB']);
    }//end testEstimateMatchesBuildManifestTotal()

    /**
     * @return void
     */
    public function testCustomTemplateOverridesPdokDefault(): void
    {
        $result = $this->service->buildManifest(
            bbox: ['minLat' => 52.370, 'minLon' => 4.890, 'maxLat' => 52.371, 'maxLon' => 4.891],
            zoomLevels: [10],
            template: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
        );

        self::assertStringStartsWith('https://tile.openstreetmap.org/', $result['tiles'][0]['url']);
        self::assertSame('https://tile.openstreetmap.org/{z}/{x}/{y}.png', $result['template']);
    }//end testCustomTemplateOverridesPdokDefault()

    /**
     * @return void
     */
    public function testRejectsInvalidBbox(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->buildManifest(
            bbox: ['minLat' => 52.5, 'minLon' => 4.8, 'maxLat' => 52.3, 'maxLon' => 5.0],
            zoomLevels: [10]
        );
    }//end testRejectsInvalidBbox()

    /**
     * @return void
     */
    public function testRejectsMissingBboxField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bbox.maxLon');

        $this->service->buildManifest(
            bbox: ['minLat' => 52.30, 'minLon' => 4.80, 'maxLat' => 52.45],
            zoomLevels: [10]
        );
    }//end testRejectsMissingBboxField()

    /**
     * @return void
     */
    public function testRejectsEmptyZoomLevels(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->buildManifest(
            bbox: ['minLat' => 52.30, 'minLon' => 4.80, 'maxLat' => 52.45, 'maxLon' => 5.05],
            zoomLevels: []
        );
    }//end testRejectsEmptyZoomLevels()

    /**
     * @return void
     */
    public function testRejectsZoomOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zoom');

        $this->service->buildManifest(
            bbox: ['minLat' => 52.30, 'minLon' => 4.80, 'maxLat' => 52.45, 'maxLon' => 5.05],
            zoomLevels: [25]
        );
    }//end testRejectsZoomOutOfRange()

    /**
     * @return void
     */
    public function testUrlForSubstitutesTokens(): void
    {
        self::assertSame(
            'https://example.com/15/16805/10758.png',
            $this->service->urlFor(
                template: 'https://example.com/{z}/{x}/{y}.png',
                zoom: 15,
                tileX: 16805,
                tileY: 10758
            )
        );
    }//end testUrlForSubstitutesTokens()

    /**
     * Guards against accidental whole-NL requests at z=18.
     *
     * @return void
     */
    public function testRefusesUnboundedManifestsPastMaxTiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->buildManifest(
            bbox: ['minLat' => 50.6, 'minLon' => 3.3, 'maxLat' => 53.7, 'maxLon' => 7.3],
            zoomLevels: [18]
        );
    }//end testRefusesUnboundedManifestsPastMaxTiles()
}//end class
