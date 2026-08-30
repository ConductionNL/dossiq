<?php

/**
 * EmailController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the three EmailController endpoints that had
 * no automated proof of their wire behaviour.
 *
 * The interesting part of this controller is its error mapping. `send()` and
 * `sendFromTemplate()` both catch `\RuntimeException` and then SPLIT on the
 * message: the sentinel `email_send_failed` — thrown by CaseEmailService when
 * the transport itself fails — becomes a 500 whose body is exactly that
 * sentinel, because the underlying exception text would otherwise carry SMTP
 * host and credential detail to the caller (M4). Every other RuntimeException
 * is a caller-fixable validation error and becomes a 400 carrying its message.
 * Collapsing those two arms — one 500 for everything, or one 400 for
 * everything — is the realistic defect, and it is invisible from a happy-path
 * test, so both arms are pinned separately here.
 *
 * The body-shaped contract is pinned too: the caseId comes from the ROUTE, and
 * absent body fields are forwarded as empty strings / an empty array rather
 * than as nulls.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\EmailController;
use OCA\Dossiq\Service\CaseEmailService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for EmailController.
 *
 * @covers \OCA\Dossiq\Controller\EmailController
 */
class EmailControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The case e-mail backend.
	 *
	 * @var CaseEmailService|MockObject
	 */
	private CaseEmailService $emailService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var EmailController
	 */
	private EmailController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->emailService = $this->createMock(CaseEmailService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new EmailController(
			appName: 'dossiq',
			request: $this->request,
			emailService: $this->emailService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Put a user in the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * All three endpoints refuse an anonymous caller with 401 and send nothing.
	 *
	 * @return void
	 */
	public function testAllThreeEndpointsRefuseAnAnonymousCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->emailService->expects($this->never())->method('sendEmail');
		$this->emailService->expects($this->never())->method('sendFromTemplate');
		$this->emailService->expects($this->never())->method('getTemplatesForCaseType');

		$responses = [
			'send' => $this->controller->send(caseId: 'case-1'),
			'sendFromTemplate' => $this->controller->sendFromTemplate(caseId: 'case-1'),
			'templates' => $this->controller->templates(caseTypeId: 'ct-1'),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$endpoint . ' must refuse an anonymous caller'
			);
			$this->assertSame(['error' => 'Not authenticated'], $response->getData());
		}
	}//end testAllThreeEndpointsRefuseAnAnonymousCallerWith401()

	/**
	 * send forwards the ROUTE's caseId and defaults every absent body field to
	 * an empty string / empty attachment list, then answers the backend's
	 * result unwrapped.
	 *
	 * @return void
	 */
	public function testSendForwardsTheRouteCaseIdAndDefaultsTheAbsentBodyFields(): void {
		$this->signIn();

		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with('case-1', '', '', '', [])
			->willReturn(['sent' => true, 'messageId' => 'msg-1']);

		$response = $this->controller->send(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['sent' => true, 'messageId' => 'msg-1'], $response->getData());
	}//end testSendForwardsTheRouteCaseIdAndDefaultsTheAbsentBodyFields()

	/**
	 * A transport failure answers 500 with ONLY the sentinel — never the
	 * exception text, which carries the SMTP host and credentials.
	 *
	 * @return void
	 */
	public function testSendAnswers500WithTheSentinelAndNoTransportDetail(): void {
		$this->signIn();
		$this->emailService->method('sendEmail')
			->willThrowException(new \RuntimeException('email_send_failed'));

		$response = $this->controller->send(caseId: 'case-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'email_send_failed'], $response->getData());
	}//end testSendAnswers500WithTheSentinelAndNoTransportDetail()

	/**
	 * A caller-fixable validation failure takes the OTHER arm: 400 carrying the
	 * reason, so the UI can show it. Collapsing this into the 500 above would
	 * make every bad address look like an outage.
	 *
	 * @return void
	 */
	public function testSendAnswers400WithTheReasonOnAValidationFailure(): void {
		$this->signIn();
		$this->emailService->method('sendEmail')
			->willThrowException(new \RuntimeException('Recipient address is required'));

		$response = $this->controller->send(caseId: 'case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Recipient address is required'], $response->getData());
	}//end testSendAnswers400WithTheReasonOnAValidationFailure()

	/**
	 * sendFromTemplate reaches the TEMPLATE send path — not the free-form one —
	 * with the route's caseId, and answers its result unwrapped.
	 *
	 * @return void
	 */
	public function testSendFromTemplateUsesTheTemplateSendPathWithTheRouteCaseId(): void {
		$this->signIn();

		$this->emailService->expects($this->once())
			->method('sendFromTemplate')
			->with('case-2', '', '')
			->willReturn(['sent' => true, 'templateId' => 'tpl-1']);
		$this->emailService->expects($this->never())->method('sendEmail');

		$response = $this->controller->sendFromTemplate(caseId: 'case-2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['sent' => true, 'templateId' => 'tpl-1'], $response->getData());
	}//end testSendFromTemplateUsesTheTemplateSendPathWithTheRouteCaseId()

	/**
	 * sendFromTemplate maps the sentinel to 500 and a missing template to 400,
	 * exactly like send() — the two arms are separate here as well.
	 *
	 * @return void
	 */
	public function testSendFromTemplateSplitsTransportFailuresFromValidationFailures(): void {
		$this->signIn();
		$this->emailService->method('sendFromTemplate')->willReturnOnConsecutiveCalls(
			$this->throwException(new \RuntimeException('email_send_failed')),
			$this->throwException(new \RuntimeException('Email template not found')),
		);

		$transport = $this->controller->sendFromTemplate(caseId: 'case-2');
		$validation = $this->controller->sendFromTemplate(caseId: 'case-2');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $transport->getStatus());
		$this->assertSame(['error' => 'email_send_failed'], $transport->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $validation->getStatus());
		$this->assertSame(['error' => 'Email template not found'], $validation->getData());
	}//end testSendFromTemplateSplitsTransportFailuresFromValidationFailures()

	/**
	 * templates answers the caseType's template list under `results` — the key
	 * the editor reads — for the caseType on the route.
	 *
	 * @return void
	 */
	public function testTemplatesWrapsTheCaseTypeTemplateListUnderResults(): void {
		$this->signIn();
		$templates = [['id' => 'tpl-1', 'name' => 'Ontvangstbevestiging']];

		$this->emailService->expects($this->once())
			->method('getTemplatesForCaseType')
			->with('ct-9')
			->willReturn($templates);

		$response = $this->controller->templates(caseTypeId: 'ct-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $templates], $response->getData());
	}//end testTemplatesWrapsTheCaseTypeTemplateListUnderResults()
}//end class
