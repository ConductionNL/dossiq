<?php

/**
 * ZaakDeleteHandler Unit Tests
 *
 * Tests for the ZaakDeleteHandler extracted from ZrcController.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller\ZrcController
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller\ZrcController;

use OCA\Procest\Controller\ZrcController\ZaakDeleteHandler;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ZaakDeleteHandler.
 *
 * @covers \OCA\Procest\Controller\ZrcController\ZaakDeleteHandler
 */
class ZaakDeleteHandlerTest extends TestCase
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
     * @var ZaakDeleteHandler
     */
    private ZaakDeleteHandler $handler;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->zgwService = $this->createMock(ZgwService::class);
        $this->request    = $this->createMock(IRequest::class);

        $this->handler = new ZaakDeleteHandler(
            zgwService: $this->zgwService,
        );

    }//end setUp()


    /**
     * Test that destroyZaak returns 503 when object service is null.
     *
     * @return void
     */
    public function testDestroyZaakReturnsUnavailableWhenObjectServiceIsNull(): void
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

        $result = $this->handler->destroyZaak(uuid: 'some-uuid', request: $this->request);

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());

    }//end testDestroyZaakReturnsUnavailableWhenObjectServiceIsNull()


    /**
     * Test that destroyZaak returns 404 when mapping config is null.
     *
     * @return void
     */
    public function testDestroyZaakReturnsMappingNotFoundWhenConfigIsNull(): void
    {
        $objectServiceMock = $this->createMock(\stdClass::class);

        $this->zgwService->expects($this->once())
            ->method('getObjectService')
            ->willReturn($objectServiceMock);

        $this->zgwService->expects($this->once())
            ->method('loadMappingConfig')
            ->with('zaken', 'zaken')
            ->willReturn(null);

        $notFoundResponse = new JSONResponse(
            data: ['detail' => 'Mapping not found'],
            statusCode: Http::STATUS_NOT_FOUND
        );

        $this->zgwService->expects($this->once())
            ->method('mappingNotFoundResponse')
            ->with('zaken', 'zaken')
            ->willReturn($notFoundResponse);

        $result = $this->handler->destroyZaak(uuid: 'some-uuid', request: $this->request);

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testDestroyZaakReturnsMappingNotFoundWhenConfigIsNull()


    /**
     * Test that getZioDataForOioSync returns null when mapping config is null.
     *
     * @return void
     */
    public function testGetZioDataForOioSyncReturnsNullWhenMappingConfigIsNull(): void
    {
        $this->zgwService->expects($this->once())
            ->method('loadMappingConfig')
            ->with('zaken', 'zaakinformatieobjecten')
            ->willReturn(null);

        $result = $this->handler->getZioDataForOioSync(uuid: 'some-uuid', request: $this->request);

        $this->assertNull($result);

    }//end testGetZioDataForOioSyncReturnsNullWhenMappingConfigIsNull()


    /**
     * Test that syncDeleteObjectInformatieObject returns early when oioConfig is null.
     *
     * @return void
     */
    public function testSyncDeleteObjectInformatieObjectReturnsEarlyWhenOioConfigIsNull(): void
    {
        $mappingServiceMock = $this->createMock(\stdClass::class);

        $this->zgwService->method('getZgwMappingService')
            ->willReturn($mappingServiceMock);

        $mappingServiceMock->method('getMapping')
            ->with('objectinformatieobject')
            ->willReturn(null);

        // No object service calls expected when config is null.
        $this->zgwService->expects($this->never())
            ->method('getObjectService');

        $this->handler->syncDeleteObjectInformatieObject(zaakUrl: 'http://example.com/zaak/1', ioUrl: 'http://example.com/io/1');

        // Reaching here without exception is the assertion.
        $this->assertTrue(true);

    }//end testSyncDeleteObjectInformatieObjectReturnsEarlyWhenOioConfigIsNull()


}//end class
