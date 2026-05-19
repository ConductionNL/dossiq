<?php

/**
 * CaseEmailController Unit Tests
 *
 * Tests for the Procest CaseEmailController REST API endpoints.
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
 * @spec openspec/changes/case-email-integration/tasks.md#task-T05
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\CaseEmailController;
use OCA\Procest\Service\CaseEmailService;
use OCA\Procest\Service\EmailTemplateService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CaseEmailController.
 *
 * @covers \OCA\Procest\Controller\CaseEmailController
 */
class CaseEmailControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The mocked email service.
     *
     * @var CaseEmailService|\PHPUnit\Framework\MockObject\MockObject
     */
    private CaseEmailService $emailService;

    /**
     * The mocked template service.
     *
     * @var EmailTemplateService|\PHPUnit\Framework\MockObject\MockObject
     */
    private EmailTemplateService $templateService;

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked user session.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * The mocked group manager.
     *
     * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private IGroupManager $groupManager;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The controller under test.
     *
     * @var CaseEmailController
     */
    private CaseEmailController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->emailService    = $this->createMock(CaseEmailService::class);
        $this->templateService = $this->createMock(EmailTemplateService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->controller = new CaseEmailController(
            'procest',
            $this->request,
            $this->emailService,
            $this->templateService,
            $this->settingsService,
            $this->userSession,
            $this->groupManager,
            $this->logger,
        );

    }//end setUp()

    /**
     * Test that listTemplates returns the template list with a 'results' key.
     *
     * @return void
     */
    public function testListTemplatesReturnsTemplateList(): void
    {
        $templates = [['id' => 'uuid-1', 'name' => 'Ontvangst']];
        $this->templateService->method('listTemplates')->willReturn($templates);

        $response = $this->controller->listTemplates(caseTypeId: 'casetype-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(1, $data['results']);

    }//end testListTemplatesReturnsTemplateList()

    /**
     * Test that listTemplates returns an empty results list when no templates exist.
     *
     * @return void
     */
    public function testListTemplatesReturnsEmptyResultsWhenNoneExist(): void
    {
        $this->templateService->method('listTemplates')->willReturn([]);

        $response = $this->controller->listTemplates(caseTypeId: 'casetype-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame([], $data['results']);

    }//end testListTemplatesReturnsEmptyResultsWhenNoneExist()

    /**
     * Test that listEmails returns 200 with thread data.
     *
     * @return void
     */
    public function testListEmailsReturnsCaseEmails(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->emailService->method('listEmailsForCase')->willReturn(
            ['threads' => [], 'total' => 0]
        );

        $response = $this->controller->listEmails(caseId: 'case-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('threads', $data);
        $this->assertSame(0, $data['total']);

    }//end testListEmailsReturnsCaseEmails()

    /**
     * Test that sendEmail returns 400 Bad Request when 'to' is missing.
     *
     * @return void
     */
    public function testSendEmailReturnsBadRequestWhenNoRecipient(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getContent')->willReturn('{}');

        $response = $this->controller->sendEmail(caseId: 'case-uuid');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testSendEmailReturnsBadRequestWhenNoRecipient()

    /**
     * Test that sendEmail calls the service and returns the send result.
     *
     * @return void
     */
    public function testSendEmailCallsServiceAndReturnsResult(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getContent')->willReturn(
            json_encode(['to' => 'test@example.nl', 'subject' => 'Test', 'body' => 'Hello'])
        );

        $sendResult = [
            'messageId' => '<test@host>',
            'to'        => 'test@example.nl',
            'subject'   => 'Test',
            'threadId'  => 'thread-uuid',
            'sentAt'    => '2026-01-01T00:00:00Z',
        ];
        $this->emailService->method('sendEmail')->willReturn($sendResult);

        $response = $this->controller->sendEmail(caseId: 'case-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertArrayHasKey('messageId', $response->getData());

    }//end testSendEmailCallsServiceAndReturnsResult()

    /**
     * Test that sendEmail returns 400 when the email service throws.
     *
     * @return void
     */
    public function testSendEmailReturnsBadRequestOnServiceException(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getContent')->willReturn(
            json_encode(['to' => 'test@example.nl', 'subject' => 'Test', 'body' => 'Hello'])
        );

        $this->emailService->method('sendEmail')
            ->willThrowException(new \RuntimeException('SMTP error'));

        $response = $this->controller->sendEmail(caseId: 'case-uuid');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());

    }//end testSendEmailReturnsBadRequestOnServiceException()

    /**
     * Test that authorizeEmailAction throws when user is unauthenticated.
     *
     * @return void
     */
    public function testAuthorizeEmailActionThrowsWhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
        $this->controller->authorizeEmailAction(caseId: 'case-uuid');

    }//end testAuthorizeEmailActionThrowsWhenUnauthenticated()

    /**
     * Test that authorizeEmailAction does not throw for authenticated users.
     *
     * @return void
     */
    public function testAuthorizeEmailActionAllowsAuthenticatedUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        // Should not throw.
        $this->controller->authorizeEmailAction(caseId: 'case-uuid');
        $this->assertTrue(true);

    }//end testAuthorizeEmailActionAllowsAuthenticatedUser()
}//end class
