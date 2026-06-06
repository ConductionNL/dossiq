<?php

/**
 * MandaatController Unit Tests
 *
 * Tests for the MandaatController that validates mandates in the
 * besluitvorming workflow.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\MandaatController;
use OCA\Procest\Service\MandaatValidationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MandaatController.
 *
 * @covers \OCA\Procest\Controller\MandaatController
 */
class MandaatControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The mocked mandate validation service.
     *
     * @var MandaatValidationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private MandaatValidationService $mandaatValidationService;

    /**
     * The mocked user session.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The controller under test.
     *
     * @var MandaatController
     */
    private MandaatController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request                  = $this->createMock(IRequest::class);
        $this->mandaatValidationService = $this->createMock(MandaatValidationService::class);
        $this->userSession              = $this->createMock(IUserSession::class);
        $this->logger                   = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new MandaatController(
            appName: 'procest',
            request: $this->request,
            mandaatValidationService: $this->mandaatValidationService,
            userSession: $this->userSession,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that mandaatCheck() returns JSONResponse on success.
     *
     * @return void
     */
    public function testCheckMandaatReturnsJsonResponse(): void
    {
        $this->request
            ->method('getParam')
            ->with('signingUserId', '')
            ->willReturn('user-1');

        $this->mandaatValidationService
            ->expects($this->once())
            ->method('validate')
            ->willReturn(['valid' => true, 'requiresManualConfirmation' => false, 'message' => 'OK.']);

        $response = $this->controller->mandaatCheck(id: 'case-uuid-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());

    }//end testCheckMandaatReturnsJsonResponse()


    /**
     * Test that mandaatCheck() returns 400 when signingUserId is missing.
     *
     * @return void
     */
    public function testCheckMandaatReturns400WhenSigningUserIdMissing(): void
    {
        $this->request
            ->method('getParam')
            ->with('signingUserId', '')
            ->willReturn('');

        $response = $this->controller->mandaatCheck(id: 'case-uuid-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());

    }//end testCheckMandaatReturns400WhenSigningUserIdMissing()


    /**
     * Test that unauthenticated user gets 401 response.
     *
     * @return void
     */
    public function testCheckMandaatReturns401WhenUnauthenticated(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new MandaatController(
            appName: 'procest',
            request: $this->request,
            mandaatValidationService: $this->mandaatValidationService,
            userSession: $unauthSession,
            logger: $this->logger,
        );

        $response = $controller->mandaatCheck(id: 'case-uuid-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(401, $response->getStatus());

    }//end testCheckMandaatReturns401WhenUnauthenticated()


}//end class
