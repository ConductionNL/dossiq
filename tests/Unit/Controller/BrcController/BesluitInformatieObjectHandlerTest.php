<?php

/**
 * BesluitInformatieObjectHandler Unit Tests
 *
 * Tests for the BesluitInformatieObjectHandler extracted from BrcController.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller\BrcController
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller\BrcController;

use OCA\Procest\Controller\BrcController\BesluitInformatieObjectHandler;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BesluitInformatieObjectHandler.
 *
 * @covers \OCA\Procest\Controller\BrcController\BesluitInformatieObjectHandler
 */
class BesluitInformatieObjectHandlerTest extends TestCase
{

    /**
     * The mocked ZGW service.
     *
     * @var ZgwService|\PHPUnit\Framework\MockObject\MockObject
     */
    private ZgwService $zgwService;

    /**
     * The mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The handler under test.
     *
     * @var BesluitInformatieObjectHandler
     */
    private BesluitInformatieObjectHandler $handler;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->zgwService = $this->createMock(ZgwService::class);
        $this->request    = $this->createMock(IRequest::class);

        $this->handler = new BesluitInformatieObjectHandler(
            zgwService: $this->zgwService,
        );

    }//end setUp()


    /**
     * Test that indexBesluitInformatieObjecten returns 503 when object service is null.
     *
     * @return void
     */
    public function testIndexReturnsUnavailableWhenObjectServiceIsNull(): void
    {
        $this->zgwService->expects($this->once())
            ->method('getObjectService')
            ->willReturn(null);

        $unavailableResponse = new JSONResponse(
            data: ['detail' => 'Service unavailable'],
            statusCode: Http::STATUS_SERVICE_UNAVAILABLE
        );

        $this->zgwService->expects($this->once())
            ->method('unavailableResponse')
            ->willReturn($unavailableResponse);

        $result = $this->handler->indexBesluitInformatieObjecten(request: $this->request);

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());

    }//end testIndexReturnsUnavailableWhenObjectServiceIsNull()


    /**
     * Test that indexBesluitInformatieObjecten returns 404 when mapping config is null.
     *
     * @return void
     */
    public function testIndexReturnsMappingNotFoundWhenConfigIsNull(): void
    {
        $objectServiceMock = $this->createMock(\stdClass::class);

        $this->zgwService->expects($this->once())
            ->method('getObjectService')
            ->willReturn($objectServiceMock);

        $this->zgwService->expects($this->once())
            ->method('loadMappingConfig')
            ->with('besluiten', 'besluitinformatieobjecten')
            ->willReturn(null);

        $notFoundResponse = new JSONResponse(
            data: ['detail' => 'Mapping not found'],
            statusCode: Http::STATUS_NOT_FOUND
        );

        $this->zgwService->expects($this->once())
            ->method('mappingNotFoundResponse')
            ->with('besluiten', 'besluitinformatieobjecten')
            ->willReturn($notFoundResponse);

        $result = $this->handler->indexBesluitInformatieObjecten(request: $this->request);

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testIndexReturnsMappingNotFoundWhenConfigIsNull()


    /**
     * Test that createBesluitInformatieObject returns 503 when object service is null.
     *
     * @return void
     */
    public function testCreateReturnsUnavailableWhenObjectServiceIsNull(): void
    {
        $this->zgwService->expects($this->once())
            ->method('getObjectService')
            ->willReturn(null);

        $unavailableResponse = new JSONResponse(
            data: ['detail' => 'Service unavailable'],
            statusCode: Http::STATUS_SERVICE_UNAVAILABLE
        );

        $this->zgwService->expects($this->once())
            ->method('unavailableResponse')
            ->willReturn($unavailableResponse);

        $result = $this->handler->createBesluitInformatieObject(request: $this->request);

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());

    }//end testCreateReturnsUnavailableWhenObjectServiceIsNull()


    /**
     * Test that createBesluitInformatieObject returns 404 when mapping config is null.
     *
     * @return void
     */
    public function testCreateReturnsMappingNotFoundWhenConfigIsNull(): void
    {
        $objectServiceMock = $this->createMock(\stdClass::class);

        $this->zgwService->expects($this->once())
            ->method('getObjectService')
            ->willReturn($objectServiceMock);

        $this->zgwService->expects($this->once())
            ->method('loadMappingConfig')
            ->with('besluiten', 'besluitinformatieobjecten')
            ->willReturn(null);

        $notFoundResponse = new JSONResponse(
            data: ['detail' => 'Mapping not found'],
            statusCode: Http::STATUS_NOT_FOUND
        );

        $this->zgwService->expects($this->once())
            ->method('mappingNotFoundResponse')
            ->with('besluiten', 'besluitinformatieobjecten')
            ->willReturn($notFoundResponse);

        $result = $this->handler->createBesluitInformatieObject(request: $this->request);

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testCreateReturnsMappingNotFoundWhenConfigIsNull()


    /**
     * Test that destroyBesluitInformatieObject returns 503 when object service is null.
     *
     * @return void
     */
    public function testDestroyReturnsUnavailableWhenObjectServiceIsNull(): void
    {
        $this->zgwService->expects($this->once())
            ->method('getObjectService')
            ->willReturn(null);

        $unavailableResponse = new JSONResponse(
            data: ['detail' => 'Service unavailable'],
            statusCode: Http::STATUS_SERVICE_UNAVAILABLE
        );

        $this->zgwService->expects($this->once())
            ->method('unavailableResponse')
            ->willReturn($unavailableResponse);

        $result = $this->handler->destroyBesluitInformatieObject(
            uuid: 'some-uuid',
            request: $this->request
        );

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());

    }//end testDestroyReturnsUnavailableWhenObjectServiceIsNull()


    /**
     * Test that deleteOiosForBesluit returns early when object service is null.
     *
     * @return void
     */
    public function testDeleteOiosForBesluitReturnsEarlyWhenObjectServiceIsNull(): void
    {
        $this->zgwService->expects($this->once())
            ->method('getObjectService')
            ->willReturn(null);

        // No further calls expected when service is null.
        $this->zgwService->expects($this->never())
            ->method('loadMappingConfig');

        $this->handler->deleteOiosForBesluit(besluitUrl: 'http://example.com/besluiten/some-uuid');

        // Reaching here without exception is the assertion.
        $this->assertTrue(true);

    }//end testDeleteOiosForBesluitReturnsEarlyWhenObjectServiceIsNull()


}//end class
