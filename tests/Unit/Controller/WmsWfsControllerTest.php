<?php

/**
 * WmsWfsController Unit Tests
 *
 * Tests for the WmsWfsController that proxies per-layer WMS/WFS requests
 * to configured wmsLayer upstream endpoints.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gis-integration/tasks.md#task-24
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\WmsWfsController;
use OCA\Procest\Service\WmsWfsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WmsWfsController.
 *
 * @covers \OCA\Procest\Controller\WmsWfsController
 */
class WmsWfsControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The mocked WMS/WFS service.
     *
     * @var WmsWfsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private WmsWfsService $wmsWfsService;

    /**
     * The controller under test.
     *
     * @var WmsWfsController
     */
    private WmsWfsController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request       = $this->createMock(IRequest::class);
        $this->wmsWfsService = $this->createMock(WmsWfsService::class);

        $this->controller = new WmsWfsController(
            appName: 'procest',
            request: $this->request,
            wmsWfsService: $this->wmsWfsService,
        );

    }//end setUp()


    /**
     * Test that proxy() returns 400 when layerId is missing.
     *
     * @return void
     */
    public function testProxyReturnsBadRequestWhenLayerIdMissing(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap([['layerId', '', '']]);

        $response = $this->controller->proxy();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());

    }//end testProxyReturnsBadRequestWhenLayerIdMissing()


    /**
     * Test that proxy() returns 404 when the layer is not found.
     *
     * @return void
     */
    public function testProxyReturnsNotFoundWhenLayerDoesNotExist(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap([['layerId', '', 'non-existent-uuid']]);

        $this->wmsWfsService
            ->expects($this->once())
            ->method('getLayerById')
            ->with('non-existent-uuid')
            ->willReturn(null);

        $response = $this->controller->proxy();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(404, $response->getStatus());

    }//end testProxyReturnsNotFoundWhenLayerDoesNotExist()


    /**
     * Test that proxy() returns 200 with proxied data on success.
     *
     * @return void
     */
    public function testProxyReturnsSuccessWithProxiedData(): void
    {
        $layerId = 'layer-uuid-123';
        $layer   = ['id' => $layerId, 'type' => 'WMS', 'url' => 'https://service.pdok.nl/wms'];

        $this->request
            ->method('getParam')
            ->willReturnMap([['layerId', '', $layerId]]);

        $this->request
            ->method('getParams')
            ->willReturn(['layerId' => $layerId, 'request' => 'GetMap', 'width' => '256', 'height' => '256']);

        $this->wmsWfsService
            ->expects($this->once())
            ->method('getLayerById')
            ->with($layerId)
            ->willReturn($layer);

        $this->wmsWfsService
            ->expects($this->once())
            ->method('proxyRequest')
            ->with($layer, ['request' => 'GetMap', 'width' => '256', 'height' => '256'])
            ->willReturn(['data' => 'image-data', 'contentType' => 'image/png']);

        $response = $this->controller->proxy();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());

    }//end testProxyReturnsSuccessWithProxiedData()


    /**
     * Test that proxy() returns 502 when the WmsWfsService throws a RuntimeException.
     *
     * @return void
     */
    public function testProxyReturnsBadGatewayOnServiceException(): void
    {
        $layerId = 'layer-uuid-456';
        $layer   = ['id' => $layerId, 'type' => 'WMS', 'url' => 'https://service.pdok.nl/wms'];

        $this->request
            ->method('getParam')
            ->willReturnMap([['layerId', '', $layerId]]);

        $this->request
            ->method('getParams')
            ->willReturn(['layerId' => $layerId]);

        $this->wmsWfsService
            ->method('getLayerById')
            ->willReturn($layer);

        $this->wmsWfsService
            ->method('proxyRequest')
            ->willThrowException(new \RuntimeException('Upstream service failed', 502));

        $response = $this->controller->proxy();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(502, $response->getStatus());

    }//end testProxyReturnsBadGatewayOnServiceException()


    /**
     * Test that proxy() excludes the layerId param when forwarding to WmsWfsService.
     *
     * @return void
     */
    public function testProxyExcludesLayerIdFromForwardedParams(): void
    {
        $layerId = 'layer-uuid-789';
        $layer   = ['id' => $layerId, 'type' => 'WFS', 'url' => 'https://service.pdok.nl/wfs'];

        $this->request
            ->method('getParam')
            ->willReturnMap([['layerId', '', $layerId]]);

        $allParams = ['layerId' => $layerId, 'request' => 'GetFeature', 'typeName' => 'perceel'];
        $this->request
            ->method('getParams')
            ->willReturn($allParams);

        $this->wmsWfsService->method('getLayerById')->willReturn($layer);

        $this->wmsWfsService
            ->expects($this->once())
            ->method('proxyRequest')
            ->with(
                $layer,
                $this->callback(function (array $params): bool {
                    // layerId must NOT be in the forwarded params.
                    return isset($params['request']) === true
                        && isset($params['typeName']) === true
                        && isset($params['layerId']) === false;
                })
            )
            ->willReturn(['features' => []]);

        $response = $this->controller->proxy();

        $this->assertSame(200, $response->getStatus());

    }//end testProxyExcludesLayerIdFromForwardedParams()


}//end class
