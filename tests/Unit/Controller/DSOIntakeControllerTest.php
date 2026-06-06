<?php

/**
 * DSOIntakeController Unit Tests
 *
 * Tests for the DSO vergunningaanvraag intake controller that receives signed
 * STAM 2.0 payloads from OpenConnector and creates omgevingsvergunning cases.
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
 * @spec openspec/changes/vth-module/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\DSOIntakeController;
use OCA\Procest\Service\DsoIntakeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the DSOIntakeController class.
 *
 * @covers \OCA\Procest\Controller\DSOIntakeController
 */
class DSOIntakeControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest|MockObject
     */
    private IRequest $request;

    /**
     * The mocked DSO intake service.
     *
     * @var DsoIntakeService|MockObject
     */
    private DsoIntakeService $dsoIntakeService;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * The controller under test.
     *
     * @var DSOIntakeController
     */
    private DSOIntakeController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        // IRequest does not declare getContent() — add it via getMockBuilder so mocks work.
        $this->request = $this->getMockBuilder(IRequest::class)
            ->addMethods(['getContent'])
            ->getMockForAbstractClass();

        $this->dsoIntakeService = $this->createMock(DsoIntakeService::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->controller = new DSOIntakeController(
            appName: 'procest',
            request: $this->request,
            dsoIntakeService: $this->dsoIntakeService,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that intake() returns 400 when the request body is empty.
     *
     * @return void
     */
    public function testIntakeReturns400WhenBodyEmpty(): void
    {
        $this->request->method('getContent')->willReturn('');

        $response = $this->controller->intake();

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        self::assertArrayHasKey('error', $response->getData());

    }//end testIntakeReturns400WhenBodyEmpty()


    /**
     * Test that intake() returns 400 when the request body is not valid JSON.
     *
     * @return void
     */
    public function testIntakeReturns400WhenBodyInvalidJson(): void
    {
        $this->request->method('getContent')->willReturn('not-json');

        $response = $this->controller->intake();

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        self::assertArrayHasKey('error', $response->getData());

    }//end testIntakeReturns400WhenBodyInvalidJson()


    /**
     * Test that intake() returns 401 when the X-DSO-Signature header is missing.
     *
     * @return void
     */
    public function testIntakeReturns401WhenMissingSignatureHeader(): void
    {
        $this->request->method('getContent')->willReturn('{"zaaknummer":"DSO-001"}');
        $this->request->method('getHeader')->with('X-DSO-Signature')->willReturn('');

        $response = $this->controller->intake();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        self::assertArrayHasKey('error', $response->getData());

    }//end testIntakeReturns401WhenMissingSignatureHeader()


    /**
     * Test that intake() returns 201 on a successful valid payload.
     *
     * @return void
     */
    public function testIntakeReturns201OnSuccess(): void
    {
        $payload = ['zaaknummer' => 'DSO-001', 'procedureType' => 'regulier'];
        $mapped  = ['title' => 'Omgevingsvergunning', 'dsoZaaknummer' => 'DSO-001'];
        $created = ['caseId' => 'uuid-123', 'dsoZaaknummer' => 'DSO-001'];

        $this->request->method('getContent')->willReturn(json_encode($payload));
        $this->request->method('getHeader')->with('X-DSO-Signature')->willReturn('sha256=abc123');

        $this->dsoIntakeService->method('map')->with($payload)->willReturn($mapped);
        $this->dsoIntakeService->method('createCase')->with($mapped)->willReturn($created);

        $response = $this->controller->intake();

        self::assertSame(Http::STATUS_CREATED, $response->getStatus());
        self::assertSame($created, $response->getData());

    }//end testIntakeReturns201OnSuccess()


    /**
     * Test that intake() returns 500 when the service throws a RuntimeException.
     *
     * @return void
     */
    public function testIntakeReturns500WhenServiceThrows(): void
    {
        $payload = ['zaaknummer' => 'DSO-003'];

        $this->request->method('getContent')->willReturn(json_encode($payload));
        $this->request->method('getHeader')->with('X-DSO-Signature')->willReturn('sha256=abc123');

        $this->dsoIntakeService->method('map')->willThrowException(
            new RuntimeException('OpenRegister is not available')
        );

        $response = $this->controller->intake();

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        self::assertArrayHasKey('error', $response->getData());

    }//end testIntakeReturns500WhenServiceThrows()


    /**
     * Test that intake() calls map() with the decoded payload.
     *
     * @return void
     */
    public function testIntakeCallsMapWithDecodedPayload(): void
    {
        $payload = ['zaaknummer' => 'DSO-004', 'procedureType' => 'uitgebreid'];
        $mapped  = ['title' => 'Omgevingsvergunning: uitgebreid'];

        $this->request->method('getContent')->willReturn(json_encode($payload));
        $this->request->method('getHeader')->with('X-DSO-Signature')->willReturn('sha256=xyz');

        $this->dsoIntakeService
            ->expects($this->once())
            ->method('map')
            ->with($payload)
            ->willReturn($mapped);

        $this->dsoIntakeService->method('createCase')->willReturn(['caseId' => 'uuid-456']);

        $this->controller->intake();

    }//end testIntakeCallsMapWithDecodedPayload()


}//end class
