<?php

/**
 * BerichtenboxController Wire-Contract Tests
 *
 * Contract coverage for the three Berichtenbox endpoints (gate-25). All three
 * are `@NoAdminRequired`, i.e. reachable by EVERY authenticated Nextcloud user,
 * and the Berichtenbox is a citizen's statutory message box — a send is an
 * official, externally visible, non-undoable act. The contract pinned here:
 *
 *  - no session at all answers 401 and never reaches the service;
 *  - `send` is guarded by the MUTATION guard and `messages`/`poll` by the READ
 *    guard — using the read guard on `send` would let a bystander post official
 *    correspondence in a citizen's name, so the guard NAME is asserted, not just
 *    the 403;
 *  - the guard is consulted with the caseId from the request and the session
 *    user, so a guard called on the wrong case cannot pass;
 *  - `poll` denies an unresolvable message id with 403, not 404 — the endpoint
 *    must not be an existence oracle for other tenants' message ids.
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

use OCA\Dossiq\Controller\BerichtenboxController;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for BerichtenboxController.
 *
 * @covers \OCA\Dossiq\Controller\BerichtenboxController
 */
class BerichtenboxControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The BerichtenboxService mock.
	 *
	 * @var BerichtenboxService|MockObject
	 */
	private BerichtenboxService $berichtenboxService;

	/**
	 * The IUserSession mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The per-case authorization guard mock.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The controller under test.
	 *
	 * @var BerichtenboxController
	 */
	private BerichtenboxController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->berichtenboxService = $this->createMock(BerichtenboxService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$this->controller = new BerichtenboxController(
			request: $this->request,
			berichtenboxService: $this->berichtenboxService,
			userSession: $this->userSession,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @param string $uid The UID of the signed-in user.
	 *
	 * @return IUser|MockObject The user placed on the session.
	 */
	private function signIn(string $uid = 'alice'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * Answer request parameters from the supplied map.
	 *
	 * @param array<string, mixed> $params The parameter map.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withParams()

	/**
	 * `send` refuses an anonymous caller with 401 and never dispatches.
	 *
	 * @return void
	 */
	public function testSendRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->berichtenboxService->expects($this->never())->method('sendMessage');

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'Not authenticated'],
			$response->getData()
		);
	}//end testSendRefusesAnUnauthenticatedCallerWith401()

	/**
	 * `send` rejects a body without a caseId with 400 — before consulting the
	 * guard, because a guard asked about an empty case cannot decide anything.
	 *
	 * @return void
	 */
	public function testSendRejectsAMissingCaseIdWith400BeforeGuarding(): void {
		$this->signIn();
		$this->withParams([]);
		$this->caseAccessGuard->expects($this->never())->method('hasCaseMutationAccess');
		$this->berichtenboxService->expects($this->never())->method('sendMessage');

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('caseId is required', $response->getData()['error']);
	}//end testSendRejectsAMissingCaseIdWith400BeforeGuarding()

	/**
	 * `send` is gated on MUTATION access to the named case, not read access.
	 *
	 * Dispatching an official government message is a write in every sense that
	 * matters, so the read guard must not be the one consulted here.
	 *
	 * @return void
	 */
	public function testSendDemandsCaseMutationAccessAndRefusesWith403(): void {
		$user = $this->signIn(uid: 'alice');
		$this->withParams(['caseId' => 'case-1', 'subject' => 'Besluit', 'body' => 'Tekst']);

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseMutationAccess')
			->with('case-1', $user)
			->willReturn(false);
		$this->caseAccessGuard->expects($this->never())->method('hasCaseReadAccess');
		$this->berichtenboxService->expects($this->never())->method('sendMessage');

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'Not authorized'],
			$response->getData()
		);
	}//end testSendDemandsCaseMutationAccessAndRefusesWith403()

	/**
	 * A service-level failure is reported as 400 with the service's message and
	 * `success: false` — never as a 200 the client would read as delivered.
	 *
	 * @return void
	 */
	public function testSendReportsAServiceFailureAs400WithSuccessFalse(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-1']);
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->berichtenboxService->method('sendMessage')
			->willReturn(['error' => 'Berichtenbox endpoint not configured']);

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('Berichtenbox endpoint not configured', $response->getData()['error']);
	}//end testSendReportsAServiceFailureAs400WithSuccessFalse()

	/**
	 * A successful send answers 200 with the service's record under `message`.
	 *
	 * @return void
	 */
	public function testSendReturns200WithTheDispatchedMessageRecord(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-1', 'bsn' => '123456782']);
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->berichtenboxService->method('sendMessage')
			->willReturn(['id' => 'msg-1', 'status' => 'sent']);

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['success' => true, 'message' => ['id' => 'msg-1', 'status' => 'sent']],
			$response->getData()
		);
	}//end testSendReturns200WithTheDispatchedMessageRecord()

	/**
	 * `messages` refuses an anonymous caller with 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testMessagesRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->berichtenboxService->expects($this->never())->method('getMessagesForCase');

		$response = $this->controller->messages();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Not authenticated', $response->getData()['error']);
	}//end testMessagesRefusesAnUnauthenticatedCallerWith401()

	/**
	 * `messages` rejects a request without a caseId with 400 rather than
	 * listing every message it can reach.
	 *
	 * @return void
	 */
	public function testMessagesRejectsAMissingCaseIdWith400(): void {
		$this->signIn();
		$this->withParams([]);
		$this->berichtenboxService->expects($this->never())->method('getMessagesForCase');

		$response = $this->controller->messages();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('caseId is required', $response->getData()['error']);
	}//end testMessagesRejectsAMissingCaseIdWith400()

	/**
	 * `messages` is gated on READ access to the named case.
	 *
	 * @return void
	 */
	public function testMessagesDemandsCaseReadAccessAndRefusesWith403(): void {
		$user = $this->signIn(uid: 'bob');
		$this->withParams(['caseId' => 'case-7']);

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseReadAccess')
			->with('case-7', $user)
			->willReturn(false);
		$this->berichtenboxService->expects($this->never())->method('getMessagesForCase');

		$response = $this->controller->messages();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Not authorized', $response->getData()['error']);
	}//end testMessagesDemandsCaseReadAccessAndRefusesWith403()

	/**
	 * An authorized `messages` call answers 200 with the case's correspondence
	 * under `messages`.
	 *
	 * @return void
	 */
	public function testMessagesReturnsTheCorrespondenceForTheNamedCase(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-7']);
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->berichtenboxService->expects($this->once())
			->method('getMessagesForCase')
			->with('case-7')
			->willReturn([['id' => 'msg-1']]);

		$response = $this->controller->messages();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['success' => true, 'messages' => [['id' => 'msg-1']]],
			$response->getData()
		);
	}//end testMessagesReturnsTheCorrespondenceForTheNamedCase()

	/**
	 * `poll` refuses an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testPollRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->berichtenboxService->expects($this->never())->method('pollReadStatus');

		$response = $this->controller->poll(messageId: 'msg-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Not authenticated', $response->getData()['error']);
	}//end testPollRefusesAnUnauthenticatedCallerWith401()

	/**
	 * An unresolvable message id is denied with 403 — deliberately the SAME
	 * status as an unauthorized one, so the route cannot be used to discover
	 * which message ids exist.
	 *
	 * @return void
	 */
	public function testPollDeniesAnUnknownMessageWith403AndIsNotAnExistenceOracle(): void {
		$this->signIn();
		$this->berichtenboxService->method('getCaseIdForMessage')->willReturn(null);
		$this->caseAccessGuard->expects($this->never())->method('hasCaseReadAccess');
		$this->berichtenboxService->expects($this->never())->method('pollReadStatus');

		$response = $this->controller->poll(messageId: 'does-not-exist');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertNotSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Not authorized', $response->getData()['error']);
	}//end testPollDeniesAnUnknownMessageWith403AndIsNotAnExistenceOracle()

	/**
	 * `poll` resolves the message's OWNING case and applies the read guard to
	 * that case — not to some caller-supplied id.
	 *
	 * @return void
	 */
	public function testPollAppliesTheReadGuardToTheMessagesOwningCase(): void {
		$user = $this->signIn(uid: 'carol');
		$this->berichtenboxService->expects($this->once())
			->method('getCaseIdForMessage')
			->with('msg-42')
			->willReturn('case-99');

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseReadAccess')
			->with('case-99', $user)
			->willReturn(false);
		$this->berichtenboxService->expects($this->never())->method('pollReadStatus');

		$response = $this->controller->poll(messageId: 'msg-42');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testPollAppliesTheReadGuardToTheMessagesOwningCase()

	/**
	 * An authorized `poll` answers 200 with the polled read status.
	 *
	 * @return void
	 */
	public function testPollReturnsTheReadStatusForAnAuthorizedCaller(): void {
		$this->signIn();
		$this->berichtenboxService->method('getCaseIdForMessage')->willReturn('case-99');
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->berichtenboxService->expects($this->once())
			->method('pollReadStatus')
			->with('msg-42')
			->willReturn(['read' => true, 'readAt' => '2026-08-16T10:00:00+00:00']);

		$response = $this->controller->poll(messageId: 'msg-42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertTrue($response->getData()['message']['read']);
	}//end testPollReturnsTheReadStatusForAnAuthorizedCaller()
}//end class
