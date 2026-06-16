<?php

/**
 * ConsultationController Unit Tests
 *
 * Tests for the ConsultationController REST API covering authentication guards,
 * consultation listing, creation, and the public token endpoint.
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
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Controller\ConsultationController;
use OCA\Procest\Service\AdvisoryBodyService;
use OCA\Procest\Service\ConsultationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ConsultationController.
 *
 * @covers \OCA\Procest\Controller\ConsultationController
 */
class ConsultationControllerTest extends TestCase
{

    /**
     * Mocked IRequest.
     *
     * @var IRequest|MockObject
     */
    private IRequest $request;

    /**
     * Mocked ConsultationService.
     *
     * @var ConsultationService|MockObject
     */
    private ConsultationService $consultationService;

    /**
     * Mocked AdvisoryBodyService.
     *
     * @var AdvisoryBodyService|MockObject
     */
    private AdvisoryBodyService $advisoryBodyService;

    /**
     * Mocked IUserSession.
     *
     * @var IUserSession|MockObject
     */
    private IUserSession $userSession;

    /**
     * Mocked IGroupManager.
     *
     * @var IGroupManager|MockObject
     */
    private IGroupManager $groupManager;

    /**
     * Mocked LoggerInterface.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * Controller under test.
     *
     * @var ConsultationController
     */
    private ConsultationController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request             = $this->createMock(IRequest::class);
        $this->consultationService = $this->createMock(ConsultationService::class);
        $this->advisoryBodyService = $this->createMock(AdvisoryBodyService::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->groupManager        = $this->createMock(IGroupManager::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->controller = new ConsultationController(
            appName: Application::APP_ID,
            request: $this->request,
            consultationService: $this->consultationService,
            advisoryBodyService: $this->advisoryBodyService,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * index returns 401 when user is not authenticated.
     *
     * @return void
     */
    public function testIndexReturns401WhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->index(caseId: 'zaak-uuid');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testIndexReturns401WhenNotAuthenticated()


    /**
     * index returns the list of consultations for the authenticated user.
     *
     * @return void
     */
    public function testIndexReturnsConsultations(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $consultations = [
            ['id' => 'c1', 'status' => 'open'],
            ['id' => 'c2', 'status' => 'in_behandeling'],
        ];
        $this->consultationService->method('getConsultationsForCase')
            ->with(caseId: 'zaak-uuid')
            ->willReturn($consultations);

        $response = $this->controller->index(caseId: 'zaak-uuid');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(2, $data['results']);

    }//end testIndexReturnsConsultations()


    /**
     * create returns 401 when user is not authenticated.
     *
     * @return void
     */
    public function testCreateReturns401WhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->create();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testCreateReturns401WhenNotAuthenticated()


    /**
     * publicResponseGet returns 400 when token is empty.
     *
     * @return void
     */
    public function testPublicResponseGetReturns400ForEmptyToken(): void
    {
        $response = $this->controller->publicResponseGet(token: '');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testPublicResponseGetReturns400ForEmptyToken()


    /**
     * publicResponseGet returns 404 when token is invalid.
     *
     * @return void
     */
    public function testPublicResponseGetReturns404ForInvalidToken(): void
    {
        $this->consultationService->method('findBySecureToken')
            ->willReturn(null);

        $response = $this->controller->publicResponseGet(token: 'invalid-token-value');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testPublicResponseGetReturns404ForInvalidToken()


    /**
     * publicResponseGet returns consultation data for a valid token.
     *
     * @return void
     */
    public function testPublicResponseGetReturnsConsultationForValidToken(): void
    {
        $consultation = [
            'id'                     => 'con-uuid',
            'status'                 => 'in_behandeling',
            'onderwerp'              => 'Brandveiligheidsadvies',
            'uiterlijkeReactiedatum' => '2026-08-01',
        ];

        $this->consultationService->method('findBySecureToken')
            ->willReturn($consultation);

        $response = $this->controller->publicResponseGet(token: str_repeat('a', 64));
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('con-uuid', $data['id']);

    }//end testPublicResponseGetReturnsConsultationForValidToken()


    /**
     * listAdvisoryBodies returns 401 when user is not authenticated.
     *
     * @return void
     */
    public function testListAdvisoryBodiesReturns401WhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->listAdvisoryBodies();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testListAdvisoryBodiesReturns401WhenNotAuthenticated()


}//end class
