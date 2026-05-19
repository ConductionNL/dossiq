<?php

/**
 * ChunkUploadHandler Unit Tests
 *
 * Tests for the ChunkUploadHandler extracted from DrcController.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller\DrcController
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

namespace OCA\Procest\Tests\Unit\Controller\DrcController;

use OCA\Procest\Controller\DrcController\ChunkUploadHandler;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChunkUploadHandler.
 *
 * @covers \OCA\Procest\Controller\DrcController\ChunkUploadHandler
 */
class ChunkUploadHandlerTest extends TestCase
{

    /**
     * The mocked ZGW service.
     *
     * @var ZgwService|\PHPUnit\Framework\MockObject\MockObject
     */
    private ZgwService $zgwService;

    /**
     * The mocked localization service.
     *
     * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
     */
    private IL10N $l10n;

    /**
     * The mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The handler under test.
     *
     * @var ChunkUploadHandler
     */
    private ChunkUploadHandler $handler;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->zgwService = $this->createMock(ZgwService::class);
        $this->l10n       = $this->createMock(IL10N::class);
        $this->request    = $this->createMock(IRequest::class);

        $this->handler = new ChunkUploadHandler(
            zgwService: $this->zgwService,
            l10n: $this->l10n,
        );

    }//end setUp()


    /**
     * Test that uploadChunk returns 503 when object service is null.
     *
     * @return void
     */
    public function testUploadChunkReturnsUnavailableWhenObjectServiceIsNull(): void
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

        $result = $this->handler->uploadChunk(uuid: 'some-uuid', request: $this->request);

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $result->getStatus());

    }//end testUploadChunkReturnsUnavailableWhenObjectServiceIsNull()


    /**
     * Test that uploadChunk returns 404 when mapping config is null.
     *
     * @return void
     */
    public function testUploadChunkReturnsMappingNotFoundWhenConfigIsNull(): void
    {
        $objectServiceMock = $this->createMock(\stdClass::class);

        $this->zgwService->expects($this->once())
            ->method('getObjectService')
            ->willReturn($objectServiceMock);

        $this->zgwService->expects($this->once())
            ->method('loadMappingConfig')
            ->with('documenten', 'enkelvoudiginformatieobjecten')
            ->willReturn(null);

        $notFoundResponse = new JSONResponse(
            data: ['detail' => 'Mapping not found'],
            statusCode: Http::STATUS_NOT_FOUND
        );

        $this->zgwService->expects($this->once())
            ->method('mappingNotFoundResponse')
            ->with('documenten', 'enkelvoudiginformatieobjecten')
            ->willReturn($notFoundResponse);

        $result = $this->handler->uploadChunk(uuid: 'some-uuid', request: $this->request);

        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testUploadChunkReturnsMappingNotFoundWhenConfigIsNull()


}//end class
