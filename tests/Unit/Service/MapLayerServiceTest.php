<?php

/**
 * MapLayerService Unit Tests
 *
 * Tests the validation surface of MapLayerService and the basic CRUD wiring
 * via stubbed SettingsService + ObjectService.
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
 * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-03
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\MapLayerService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Procest\Service\MapLayerService
 */
class MapLayerServiceTest extends TestCase
{
    /**
     * @return void
     */
    public function testValidatePayloadRejectsMissingTitle(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title is required');

        $service->validatePayload([
            'type' => 'tile',
            'url'  => 'https://example.com/{z}/{x}/{y}.png',
        ]);
    }//end testValidatePayloadRejectsMissingTitle()

    /**
     * @return void
     */
    public function testValidatePayloadRejectsUnknownType(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of');

        $service->validatePayload([
            'title' => 'OSM',
            'type'  => 'vector',
            'url'   => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        ]);
    }//end testValidatePayloadRejectsUnknownType()

    /**
     * @return void
     */
    public function testValidatePayloadRequiresUrlByDefault(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('url is required');

        $service->validatePayload([
            'title' => 'OSM',
            'type'  => 'tile',
        ]);
    }//end testValidatePayloadRequiresUrlByDefault()

    /**
     * @return void
     */
    public function testValidatePayloadAcceptsTileTemplateUrl(): void
    {
        $service = $this->makeService();

        $service->validatePayload([
            'title' => 'OSM',
            'type'  => 'tile',
            'url'   => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        ]);

        // No exception thrown.
        self::assertTrue(true);
    }//end testValidatePayloadAcceptsTileTemplateUrl()

    /**
     * @return void
     */
    public function testValidatePayloadAcceptsWmsHttpsUrl(): void
    {
        $service = $this->makeService();

        $service->validatePayload([
            'title' => 'PDOK BRT',
            'type'  => 'wms',
            'url'   => 'https://service.pdok.nl/brt/achtergrondkaart/wms/v2_0',
        ]);

        self::assertTrue(true);
    }//end testValidatePayloadAcceptsWmsHttpsUrl()

    /**
     * @return void
     */
    public function testValidatePayloadRejectsNonUrlNonTemplate(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid URL or tile template');

        $service->validatePayload([
            'title' => 'OSM',
            'type'  => 'tile',
            'url'   => 'not-a-url',
        ]);
    }//end testValidatePayloadRejectsNonUrlNonTemplate()

    /**
     * @return void
     */
    public function testValidatePayloadEnforcesOpacityBounds(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('opacity must be in');

        $service->validatePayload([
            'title'   => 'OSM',
            'type'    => 'tile',
            'url'     => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'opacity' => 1.5,
        ]);
    }//end testValidatePayloadEnforcesOpacityBounds()

    /**
     * @return void
     */
    public function testValidatePayloadInUpdateModeMakesUrlOptional(): void
    {
        $service = $this->makeService();

        $service->validatePayload(
            payload: [
                'title' => 'OSM Light',
                'type'  => 'tile',
            ],
            requireUrl: false
        );

        self::assertTrue(true);
    }//end testValidatePayloadInUpdateModeMakesUrlOptional()

    /**
     * @return void
     */
    public function testListLayersReturnsEmptyWhenObjectServiceUnavailable(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn(null);

        $service = new MapLayerService(settingsService: $settings, logger: new NullLogger());

        self::assertSame([], $service->listLayers());
    }//end testListLayersReturnsEmptyWhenObjectServiceUnavailable()

    /**
     * Build a service whose ObjectService is null so the validate-only tests
     * don't need to mock OR.
     *
     * @return MapLayerService
     */
    private function makeService(): MapLayerService
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn(null);
        return new MapLayerService(settingsService: $settings, logger: new NullLogger());
    }//end makeService()
}//end class
