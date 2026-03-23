<?php

/**
 * GisProxyController Unit Tests
 *
 * Tests for the Procest GisProxyController that proxies WMS/WFS requests
 * from the Nextcloud frontend to external GIS services.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\GisProxyController;
use OCA\Procest\Service\GisProxyService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GisProxyController.
 *
 * @covers \OCA\Procest\Controller\GisProxyController
 */
class GisProxyControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The mocked GIS proxy service.
     *
     * @var GisProxyService|\PHPUnit\Framework\MockObject\MockObject
     */
    private GisProxyService $gisProxyService;

    /**
     * The controller under test.
     *
     * @var GisProxyController
     */
    private GisProxyController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->gisProxyService = $this->createMock(GisProxyService::class);

        $this->controller = new GisProxyController(
            appName: 'procest',
            request: $this->request,
            gisProxyService: $this->gisProxyService,
        );

    }//end setUp()


    /**
     * Test that proxy() returns 400 when URL parameter is missing.
     *
     * @return void
     */
    public function testProxyReturnsBadRequestWhenUrlMissing(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap([
                ['url', '', ''],
                ['query', [], []],
                ['type', 'wms', 'wms'],
            ]);

        $response = $this->controller->proxy();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());

    }//end testProxyReturnsBadRequestWhenUrlMissing()


    /**
     * Test that proxy() returns 200 with data on success.
     *
     * @return void
     */
    public function testProxyReturnsSuccessWithData(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap([
                ['url', '', 'https://service.pdok.nl/wms'],
                ['query', [], ['SERVICE' => 'WMS']],
                ['type', 'wms', 'wms'],
            ]);

        $this->gisProxyService
            ->expects($this->once())
            ->method('proxyRequest')
            ->with('https://service.pdok.nl/wms', ['SERVICE' => 'WMS'], 'wms')
            ->willReturn(['data' => ['features' => []], 'contentType' => 'application/json']);

        $response = $this->controller->proxy();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());

    }//end testProxyReturnsSuccessWithData()


    /**
     * Test that proxy() returns 403 when service throws RuntimeException code 403.
     *
     * @return void
     */
    public function testProxyReturnsForbiddenWhenUrlBlocked(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap([
                ['url', '', 'https://evil.example.com/wms'],
                ['query', [], []],
                ['type', 'wms', 'wms'],
            ]);

        $this->gisProxyService
            ->method('proxyRequest')
            ->willThrowException(new \RuntimeException('URL not in configured layer allowlist', 403));

        $response = $this->controller->proxy();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(403, $response->getStatus());

    }//end testProxyReturnsForbiddenWhenUrlBlocked()


    /**
     * Test that proxy() returns 429 when rate limit is exceeded.
     *
     * @return void
     */
    public function testProxyReturnsTooManyRequestsWhenRateLimited(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap([
                ['url', '', 'https://service.pdok.nl/wms'],
                ['query', [], []],
                ['type', 'wms', 'wms'],
            ]);

        $this->gisProxyService
            ->method('proxyRequest')
            ->willThrowException(new \RuntimeException('Rate limit exceeded', 429));

        $response = $this->controller->proxy();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(429, $response->getStatus());

    }//end testProxyReturnsTooManyRequestsWhenRateLimited()


    /**
     * Test that capabilities() returns 400 when URL parameter is missing.
     *
     * @return void
     */
    public function testCapabilitiesReturnsBadRequestWhenUrlMissing(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap([
                ['url', '', ''],
                ['type', 'wms', 'wms'],
            ]);

        $response = $this->controller->capabilities();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());

    }//end testCapabilitiesReturnsBadRequestWhenUrlMissing()


    /**
     * Test that capabilities() returns parsed data on success.
     *
     * @return void
     */
    public function testCapabilitiesReturnsSuccessWithLayers(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap([
                ['url', '', 'https://service.pdok.nl/wms'],
                ['type', 'wms', 'wms'],
            ]);

        $parsedCapabilities = [
            'service' => 'WMS',
            'layers'  => [
                ['name' => 'layer1', 'title' => 'Layer 1'],
            ],
        ];

        $this->gisProxyService
            ->expects($this->once())
            ->method('getCapabilities')
            ->with('https://service.pdok.nl/wms', 'wms')
            ->willReturn($parsedCapabilities);

        $response = $this->controller->capabilities();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());

    }//end testCapabilitiesReturnsSuccessWithLayers()


    /**
     * Test that capabilities() returns 502 when service throws an exception.
     *
     * @return void
     */
    public function testCapabilitiesReturnsBadGatewayOnException(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap([
                ['url', '', 'https://service.pdok.nl/wms'],
                ['type', 'wms', 'wms'],
            ]);

        $this->gisProxyService
            ->method('getCapabilities')
            ->willThrowException(new \RuntimeException('Failed to fetch GetCapabilities'));

        $response = $this->controller->capabilities();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(502, $response->getStatus());

    }//end testCapabilitiesReturnsBadGatewayOnException()


}//end class
