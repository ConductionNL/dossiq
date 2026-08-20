<?php

/**
 * ComplaintController Wire-Contract Tests
 *
 * Contract coverage for the two statutory-consequence endpoints on the
 * complaint lifecycle (gate-25): `verdaging` — the Awb art. 9:11 lid 2
 * deadline extension — and `escalate`, which binds a complaint to a formal
 * case. Both are `@NoAdminRequired`, so `ComplaintAccessGuard::authorizeMutation()`
 * is the whole authorization story; it is therefore exercised for REAL here
 * (a real guard over mocked NC collaborators), because a mocked guard would
 * wave through any complaint the controller handed it.
 *
 * The contract pinned here:
 *
 *  - no session answers 401 and never loads the complaint;
 *  - an unknown complaint answers 404 and never mutates;
 *  - a caller who is neither the assigned behandelaar nor an admin is refused
 *    with OCSForbiddenException (403 via the NC middleware) BEFORE any
 *    mutation, and the admin bypass is asserted to actually work — a guard that
 *    refuses everyone is as broken as one that refuses no one;
 *  - `escalate` demands a caseId (400 otherwise) and links the complaint to
 *    exactly the case the caller named;
 *  - `verdaging` reads its motivation from the `justificatie` body key. That
 *    key name is load-bearing: reading a differently-named key would silently
 *    record an EMPTY justification for a statutory deadline extension while
 *    still answering 200.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ComplaintController;
use OCA\Procest\Service\Complaint\ComplaintAccessGuard;
use OCA\Procest\Service\ComplaintService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for ComplaintController's verdaging + escalate endpoints.
 *
 * @covers \OCA\Procest\Controller\ComplaintController
 *
 * @uses \OCA\Procest\Service\Complaint\ComplaintAccessGuard
 */
class ComplaintControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The ComplaintService mock.
	 *
	 * @var ComplaintService|MockObject
	 */
	private ComplaintService $complaintService;

	/**
	 * The IUserSession mock backing the real access guard.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The IGroupManager mock backing the real access guard.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The controller under test.
	 *
	 * @var ComplaintController
	 */
	private ComplaintController $controller;

	/**
	 * Build the controller over a real ComplaintAccessGuard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->complaintService = $this->createMock(ComplaintService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new ComplaintController(
			appName: 'procest',
			request: $this->request,
			complaintService: $this->complaintService,
			accessGuard: new ComplaintAccessGuard(
				request: $this->request,
				userSession: $this->userSession,
				groupManager: $this->groupManager,
			),
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @param string $uid The UID of the signed-in user.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * `escalate` refuses an anonymous caller with 401 and loads nothing.
	 *
	 * @return void
	 */
	public function testEscalateRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->complaintService->expects($this->never())->method('getComplaint');

		$response = $this->controller->escalate(id: 'klacht-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testEscalateRefusesAnUnauthenticatedCallerWith401()

	/**
	 * An unknown complaint answers 404 and links nothing.
	 *
	 * @return void
	 */
	public function testEscalateReturns404ForAnUnknownComplaint(): void {
		$this->signIn();
		$this->complaintService->method('getComplaint')->willReturn(null);
		$this->complaintService->expects($this->never())->method('linkEscalatedCase');

		$response = $this->controller->escalate(id: 'does-not-exist');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Complaint not found'], $response->getData());
	}//end testEscalateReturns404ForAnUnknownComplaint()

	/**
	 * A caller who is neither the assigned behandelaar nor an admin may not
	 * escalate someone else's complaint.
	 *
	 * @return void
	 */
	public function testEscalateRefusesAStrangerToTheComplaint(): void {
		$this->signIn(uid: 'mallory');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('mallory')
			->willReturn(false);
		$this->complaintService->expects($this->never())->method('linkEscalatedCase');

		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Not authorized to modify this complaint');

		$this->controller->escalate(id: 'klacht-1');
	}//end testEscalateRefusesAStrangerToTheComplaint()

	/**
	 * Escalation without a caseId is a 400 — a complaint must not be marked
	 * escalated with nothing to escalate it to.
	 *
	 * @return void
	 */
	public function testEscalateRejectsAMissingCaseIdWith400(): void {
		$this->signIn(uid: 'alice');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->request->method('getParams')->willReturn(['_route' => 'procest.complaint.escalate']);
		$this->complaintService->expects($this->never())->method('linkEscalatedCase');

		$response = $this->controller->escalate(id: 'klacht-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'caseId is required for escalation'], $response->getData());
	}//end testEscalateRejectsAMissingCaseIdWith400()

	/**
	 * The assigned behandelaar escalates to exactly the case they named, and
	 * the endpoint answers 200 with the service's updated complaint.
	 *
	 * @return void
	 */
	public function testEscalateLinksTheNamedCaseForTheAssignedHandler(): void {
		$this->signIn(uid: 'alice');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->request->method('getParams')->willReturn(['caseId' => 'zaak-77']);

		$this->complaintService->expects($this->once())
			->method('linkEscalatedCase')
			->with('klacht-1', 'zaak-77')
			->willReturn(['id' => 'klacht-1', 'escalatedCase' => 'zaak-77']);

		$response = $this->controller->escalate(id: 'klacht-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('zaak-77', $response->getData()['escalatedCase']);
	}//end testEscalateLinksTheNamedCaseForTheAssignedHandler()

	/**
	 * The admin bypass is real: a coordinator escalates a complaint assigned to
	 * someone else. Without this the refusal test above could be satisfied by a
	 * guard that simply refuses everybody.
	 *
	 * @return void
	 */
	public function testEscalateAllowsACoordinatorOnSomeoneElsesComplaint(): void {
		$this->signIn(uid: 'coordinator');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->groupManager->method('isAdmin')->with('coordinator')->willReturn(true);
		$this->request->method('getParams')->willReturn(['caseId' => 'zaak-77']);

		$this->complaintService->expects($this->once())
			->method('linkEscalatedCase')
			->willReturn(['id' => 'klacht-1', 'escalatedCase' => 'zaak-77']);

		$response = $this->controller->escalate(id: 'klacht-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testEscalateAllowsACoordinatorOnSomeoneElsesComplaint()

	/**
	 * `verdaging` refuses an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testVerdagingRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->complaintService->expects($this->never())->method('getComplaint');

		$response = $this->controller->verdaging(id: 'klacht-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testVerdagingRefusesAnUnauthenticatedCallerWith401()

	/**
	 * An unknown complaint answers 404 and extends no deadline.
	 *
	 * @return void
	 */
	public function testVerdagingReturns404ForAnUnknownComplaint(): void {
		$this->signIn();
		$this->complaintService->method('getComplaint')->willReturn(null);
		$this->complaintService->expects($this->never())->method('requestVerdaging');

		$response = $this->controller->verdaging(id: 'does-not-exist');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Complaint not found'], $response->getData());
	}//end testVerdagingReturns404ForAnUnknownComplaint()

	/**
	 * A stranger to the complaint may not extend its statutory deadline.
	 *
	 * @return void
	 */
	public function testVerdagingRefusesAStrangerToTheComplaint(): void {
		$this->signIn(uid: 'mallory');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->groupManager->method('isAdmin')->with('mallory')->willReturn(false);
		$this->complaintService->expects($this->never())->method('requestVerdaging');

		$this->expectException(OCSForbiddenException::class);

		$this->controller->verdaging(id: 'klacht-1');
	}//end testVerdagingRefusesAStrangerToTheComplaint()

	/**
	 * The motivation is read from the `justificatie` body key and forwarded to
	 * the service verbatim.
	 *
	 * @return void
	 */
	public function testVerdagingForwardsTheJustificatieBodyKeyAsTheMotivation(): void {
		$this->signIn(uid: 'alice');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->request->method('getParams')
			->willReturn(['justificatie' => 'Extern advies afwachten']);

		$this->complaintService->expects($this->once())
			->method('requestVerdaging')
			->with('klacht-1', 'Extern advies afwachten')
			->willReturn(['id' => 'klacht-1', 'status' => 'verdaagd']);

		$response = $this->controller->verdaging(id: 'klacht-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('verdaagd', $response->getData()['status']);
	}//end testVerdagingForwardsTheJustificatieBodyKeyAsTheMotivation()

	/**
	 * A domain refusal from the service (e.g. verdaging already used) surfaces
	 * as 400 with the service's message, not as a 500.
	 *
	 * @return void
	 */
	public function testVerdagingReportsADomainRefusalAs400(): void {
		$this->signIn(uid: 'alice');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->request->method('getParams')->willReturn(['justificatie' => 'x']);
		$this->complaintService->method('requestVerdaging')
			->willThrowException(new \RuntimeException('Verdaging is al toegepast'));

		$response = $this->controller->verdaging(id: 'klacht-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Verdaging is al toegepast'], $response->getData());
	}//end testVerdagingReportsADomainRefusalAs400()
}//end class
