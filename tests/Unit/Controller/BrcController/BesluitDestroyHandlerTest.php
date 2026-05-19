<?php

/**
 * BesluitDestroyHandler Unit Tests
 *
 * Tests for the BesluitDestroyHandler extracted from BrcController.
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

use OCA\Procest\Controller\BrcController\BesluitDestroyHandler;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BesluitDestroyHandler.
 *
 * @covers \OCA\Procest\Controller\BrcController\BesluitDestroyHandler
 */
class BesluitDestroyHandlerTest extends TestCase
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
     * @var BesluitDestroyHandler
     */
    private BesluitDestroyHandler $handler;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->zgwService = $this->createMock(ZgwService::class);
        $this->request    = $this->createMock(IRequest::class);

        $this->handler = new BesluitDestroyHandler(
            zgwService: $this->zgwService,
        );

    }//end setUp()


    /**
     * Test that destroyBesluit returns 503 when object service is null.
     *
     * @return void
     */
    public function testDestroyBesluitReturnsUnavailableWhenObjectServiceIsNull(): void
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

        $result = $this->handler->destroyBesluit(uuid: 'some-uuid', request: $this->request);

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());

    }//end testDestroyBesluitReturnsUnavailableWhenObjectServiceIsNull()


    /**
     * Test that destroyBesluit returns 404 when mapping config is null.
     *
     * @return void
     */
    public function testDestroyBesluitReturnsMappingNotFoundWhenConfigIsNull(): void
    {
        $objectServiceMock = $this->createMock(\stdClass::class);

        $this->zgwService->expects($this->once())
            ->method('getObjectService')
            ->willReturn($objectServiceMock);

        $this->zgwService->expects($this->once())
            ->method('loadMappingConfig')
            ->with('besluiten', 'besluiten')
            ->willReturn(null);

        $notFoundResponse = new JSONResponse(
            data: ['detail' => 'Mapping not found'],
            statusCode: Http::STATUS_NOT_FOUND
        );

        $this->zgwService->expects($this->once())
            ->method('mappingNotFoundResponse')
            ->with('besluiten', 'besluiten')
            ->willReturn($notFoundResponse);

        $result = $this->handler->destroyBesluit(uuid: 'some-uuid', request: $this->request);

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testDestroyBesluitReturnsMappingNotFoundWhenConfigIsNull()


}//end class
