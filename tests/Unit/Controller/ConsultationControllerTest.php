<?php

/**
 * ConsultationController Unit Tests
 *
 * Tests for the Procest ConsultationController REST endpoints.
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
 * @spec openspec/changes/consultation-management/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ConsultationController;
use OCA\Procest\Service\ConsultationService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the ConsultationController class.
 *
 * @covers \OCA\Procest\Controller\ConsultationController
 */
class ConsultationControllerTest extends TestCase
{

    /**
     * Mocked ConsultationService.
     *
     * @var ConsultationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private ConsultationService $consultationService;

    /**
     * Mocked SettingsService.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mocked IUserSession.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * Mocked IRequest.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * Mocked LoggerInterface.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
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
        $this->consultationService = $this->createMock(ConsultationService::class);
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->request             = $this->createMock(IRequest::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->controller = new ConsultationController(
            appName: 'procest',
            request: $this->request,
            consultationService: $this->consultationService,
            settingsService: $this->settingsService,
            userSession: $this->userSession,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test index returns 400 when caseId is empty.
     *
     * @return void
     */
    public function testIndexReturnsBadRequestWhenCaseIdEmpty(): void
    {
        $response = $this->controller->index(caseId: '');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testIndexReturnsBadRequestWhenCaseIdEmpty()


    /**
     * Test index returns consultation list for a valid caseId.
     *
     * @return void
     */
    public function testIndexReturnsConsultationList(): void
    {
        $expected = [
            ['id' => 'consult-1', 'status' => 'open'],
            ['id' => 'consult-2', 'status' => 'in_behandeling'],
        ];

        $this->consultationService
            ->expects($this->once())
            ->method('getConsultationsForCase')
            ->with(caseId: 'zaak-uuid-001')
            ->willReturn($expected);

        $response = $this->controller->index(caseId: 'zaak-uuid-001');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame($expected, $data['results']);

    }//end testIndexReturnsConsultationList()


    /**
     * Test show returns 404 when consultation is not found.
     *
     * @return void
     */
    public function testShowReturns404WhenNotFound(): void
    {
        $this->consultationService
            ->expects($this->once())
            ->method('getConsultation')
            ->with(id: 'unknown-uuid')
            ->willReturn(null);

        $response = $this->controller->show(id: 'unknown-uuid');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testShowReturns404WhenNotFound()


    /**
     * Test show returns the consultation when found.
     *
     * @return void
     */
    public function testShowReturnsConsultation(): void
    {
        $consultation = ['id' => 'consult-uuid-001', 'status' => 'open'];

        $this->consultationService
            ->expects($this->once())
            ->method('getConsultation')
            ->with(id: 'consult-uuid-001')
            ->willReturn($consultation);

        $response = $this->controller->show(id: 'consult-uuid-001');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($consultation, $response->getData());

    }//end testShowReturnsConsultation()


    /**
     * Test create returns 401 when user is not authenticated.
     *
     * @return void
     */
    public function testCreateReturns401WhenNotAuthenticated(): void
    {
        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $response = $this->controller->create();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testCreateReturns401WhenNotAuthenticated()


    /**
     * Test create returns 201 on success.
     *
     * @return void
     */
    public function testCreateReturns201OnSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getContent')->willReturn(json_encode([
            'parentZaak'             => 'zaak-uuid-001',
            'adviesInstantie'        => 'Brandweer',
            'onderwerp'              => 'Brandveiligheid',
            'vraagstelling'          => 'Is het gebouw brandveilig?',
            'uiterlijkeReactiedatum' => '2026-06-15',
        ]));

        $created = ['id' => 'new-consult-uuid', 'status' => 'open'];
        $this->consultationService
            ->expects($this->once())
            ->method('createConsultation')
            ->willReturn($created);

        $response = $this->controller->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());

    }//end testCreateReturns201OnSuccess()


    /**
     * Test publicRespond returns 403 when token is too short.
     *
     * @return void
     */
    public function testPublicRespondReturns403ForShortToken(): void
    {
        $response = $this->controller->publicRespond(token: 'short');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testPublicRespondReturns403ForShortToken()


    /**
     * Test blockingForCase returns results and blocked flag.
     *
     * @return void
     */
    public function testBlockingForCaseReturnsResultsWithBlockedFlag(): void
    {
        $blocking = [
            ['id' => 'consult-uuid-001', 'mandatory' => true, 'status' => 'open'],
        ];

        $this->consultationService
            ->expects($this->once())
            ->method('getBlockingConsultations')
            ->with(zaakId: 'zaak-uuid-001')
            ->willReturn($blocking);

        $response = $this->controller->blockingForCase(caseId: 'zaak-uuid-001');

        $data = $response->getData();
        $this->assertSame($blocking, $data['results']);
        $this->assertTrue($data['blocked']);

    }//end testBlockingForCaseReturnsResultsWithBlockedFlag()


    /**
     * Test overdue returns results list.
     *
     * @return void
     */
    public function testOverdueReturnsResults(): void
    {
        $overdue = [['id' => 'consult-uuid-002', 'status' => 'in_behandeling']];

        $this->consultationService
            ->expects($this->once())
            ->method('getOverdueConsultations')
            ->willReturn($overdue);

        $response = $this->controller->overdue();

        $data = $response->getData();
        $this->assertSame($overdue, $data['results']);

    }//end testOverdueReturnsResults()
}//end class
