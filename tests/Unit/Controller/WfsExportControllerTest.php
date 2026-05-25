<?php

/**
 * WfsExportController Unit Tests
 *
 * Tests for the WFS export controller that exposes case locations as a
 * GeoJSON WFS layer for external GIS applications (gis-integration AC 6).
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
 * @spec openspec/changes/gis-integration/tasks.md#task-21
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\WfsExportController;
use OCA\Procest\Service\WfsExportService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WfsExportController.
 *
 * @covers \OCA\Procest\Controller\WfsExportController
 */
class WfsExportControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The mocked WFS export service.
     *
     * @var WfsExportService|\PHPUnit\Framework\MockObject\MockObject
     */
    private WfsExportService $wfsExportService;

    /**
     * The mocked user session.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * The controller under test.
     *
     * @var WfsExportController
     */
    private WfsExportController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request          = $this->createMock(originalClassName: IRequest::class);
        $this->wfsExportService = $this->createMock(originalClassName: WfsExportService::class);
        $this->userSession      = $this->createMock(originalClassName: IUserSession::class);

        $mockUser = $this->createMock(originalClassName: IUser::class);
        $this->userSession->method('getUser')->willReturn($mockUser);

        $this->controller = new WfsExportController(
            appName: 'procest',
            request: $this->request,
            wfsExportService: $this->wfsExportService,
            userSession: $this->userSession,
        );

    }//end setUp()

    /**
     * Test that getFeatures() returns 200 with a GeoJSON FeatureCollection.
     *
     * @return void
     */
    public function testGetFeaturesReturnsFeatureCollection(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap(
                    [
                        ['typeName', WfsExportService::TYPE_NAME_CASES, WfsExportService::TYPE_NAME_CASES],
                        ['outputFormat', 'application/json', 'application/json'],
                        ['maxFeatures', WfsExportService::DEFAULT_MAX_FEATURES, 500],
                        ['bbox', null, null],
                        ['status', null, null],
                        ['caseType', null, null],
                    ]
                    );

        $featureCollection = [
            'type'     => 'FeatureCollection',
            'name'     => WfsExportService::TYPE_NAME_CASES,
            'features' => [],
        ];

        $this->wfsExportService
            ->expects($this->once())
            ->method('buildFeatureCollection')
            ->with(500, null, null, null)
            ->willReturn($featureCollection);

        $response = $this->controller->getFeatures();

        $this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $this->assertSame(expected: 200, actual: $response->getStatus());
        $data = $response->getData();
        $this->assertSame(expected: 'FeatureCollection', actual: $data['type']);

    }//end testGetFeaturesReturnsFeatureCollection()

    /**
     * Test that getFeatures() throws OCSForbiddenException when user is null.
     *
     * @return void
     */
    public function testGetFeaturesThrowsWhenUserIsNull(): void
    {
        $this->userSession = $this->createMock(originalClassName: IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);

        $controller = new WfsExportController(
            appName: 'procest',
            request: $this->request,
            wfsExportService: $this->wfsExportService,
            userSession: $this->userSession,
        );

        $this->expectException(exception: OCSForbiddenException::class);

        $controller->getFeatures();

    }//end testGetFeaturesThrowsWhenUserIsNull()

    /**
     * Test that getFeatures() returns 400 for unsupported typeName.
     *
     * @return void
     */
    public function testGetFeaturesReturnsBadRequestForUnknownTypeName(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap(
                    [
                        ['typeName', WfsExportService::TYPE_NAME_CASES, 'unknown:type'],
                        ['outputFormat', 'application/json', 'application/json'],
                        ['maxFeatures', WfsExportService::DEFAULT_MAX_FEATURES, 500],
                        ['bbox', null, null],
                        ['status', null, null],
                        ['caseType', null, null],
                    ]
                    );

        $this->wfsExportService
            ->expects($this->never())
            ->method('buildFeatureCollection');

        $response = $this->controller->getFeatures();

        $this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $this->assertSame(expected: 400, actual: $response->getStatus());

    }//end testGetFeaturesReturnsBadRequestForUnknownTypeName()

    /**
     * Test that getFeatures() returns 400 for unsupported outputFormat.
     *
     * @return void
     */
    public function testGetFeaturesReturnsBadRequestForUnsupportedFormat(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap(
                    [
                        ['typeName', WfsExportService::TYPE_NAME_CASES, WfsExportService::TYPE_NAME_CASES],
                        ['outputFormat', 'application/json', 'text/xml'],
                        ['maxFeatures', WfsExportService::DEFAULT_MAX_FEATURES, 500],
                        ['bbox', null, null],
                        ['status', null, null],
                        ['caseType', null, null],
                    ]
                    );

        $this->wfsExportService
            ->expects($this->never())
            ->method('buildFeatureCollection');

        $response = $this->controller->getFeatures();

        $this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $this->assertSame(expected: 400, actual: $response->getStatus());

    }//end testGetFeaturesReturnsBadRequestForUnsupportedFormat()

    /**
     * Test that getFeatures() parses bbox parameter and passes it as array.
     *
     * @return void
     */
    public function testGetFeaturesParsesBboxParameter(): void
    {
        $this->request
            ->method('getParam')
            ->willReturnMap(
                    [
                        ['typeName', WfsExportService::TYPE_NAME_CASES, WfsExportService::TYPE_NAME_CASES],
                        ['outputFormat', 'application/json', 'application/json'],
                        ['maxFeatures', WfsExportService::DEFAULT_MAX_FEATURES, 100],
                        ['bbox', null, '4.5,52.0,5.5,53.0'],
                        ['status', null, 'open'],
                        ['caseType', null, null],
                    ]
                    );

        $this->wfsExportService
            ->expects($this->once())
            ->method('buildFeatureCollection')
            ->with(100, [4.5, 52.0, 5.5, 53.0], 'open', null)
            ->willReturn(['type' => 'FeatureCollection', 'features' => []]);

        $response = $this->controller->getFeatures();

        $this->assertSame(expected: 200, actual: $response->getStatus());

    }//end testGetFeaturesParsesBboxParameter()

    /**
     * Test that getCapabilities() returns 200 with WFS capabilities descriptor.
     *
     * @return void
     */
    public function testGetCapabilitiesReturnsDescriptor(): void
    {
        $this->request
            ->method('getServerProtocol')
            ->willReturn('https');
        $this->request
            ->method('getServerHost')
            ->willReturn('example.nl');

        $capabilities = [
            'version'      => '2.0.0',
            'featureTypes' => [['name' => WfsExportService::TYPE_NAME_CASES]],
        ];

        $this->wfsExportService
            ->expects($this->once())
            ->method('buildCapabilities')
            ->with('https://example.nl/index.php/apps/procest/api/gis/wfs')
            ->willReturn($capabilities);

        $response = $this->controller->getCapabilities();

        $this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $this->assertSame(expected: 200, actual: $response->getStatus());
        $data = $response->getData();
        $this->assertSame(expected: '2.0.0', actual: $data['version']);

    }//end testGetCapabilitiesReturnsDescriptor()

    /**
     * Test that getCapabilities() throws OCSForbiddenException when user is null.
     *
     * @return void
     */
    public function testGetCapabilitiesThrowsWhenUserIsNull(): void
    {
        $this->userSession = $this->createMock(originalClassName: IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);

        $controller = new WfsExportController(
            appName: 'procest',
            request: $this->request,
            wfsExportService: $this->wfsExportService,
            userSession: $this->userSession,
        );

        $this->expectException(exception: OCSForbiddenException::class);

        $controller->getCapabilities();

    }//end testGetCapabilitiesThrowsWhenUserIsNull()
}//end class
