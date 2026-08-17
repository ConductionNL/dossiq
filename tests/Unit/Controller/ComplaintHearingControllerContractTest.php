<?php

/**
 * ComplaintHearingController Wire-Contract Tests
 *
 * Contract coverage for the two hoorgesprek endpoints (gate-25). Both are
 * `@NoAdminRequired`. `hearings` is a read and `recordHearingOutcome` both
 * writes the hearing record AND advances the parent complaint's status, which
 * is what makes it interesting: two ids arrive on the same route and a status
 * transition rides on the write's success.
 *
 * The contract pinned here:
 *
 *  - no session answers 401 on both endpoints and neither touches HearingService;
 *  - an unknown complaint answers 404 before any write;
 *  - a caller who is neither the assigned behandelaar nor an admin is refused by
 *    the REAL ComplaintAccessGuard (403 via the NC middleware) before any write;
 *  - the outcome is recorded against the HEARING id and the transition is
 *    applied to the COMPLAINT id — transposing the two ids on a two-id route is
 *    the realistic defect, and both would still answer 200;
 *  - the transition target is the literal `hoorgesprek_completed`, and a failed
 *    recording answers 400 WITHOUT advancing the complaint — a complaint marked
 *    "hearing completed" with no hearing on file is an Awb record defect.
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

use OCA\Procest\Controller\ComplaintHearingController;
use OCA\Procest\Service\Complaint\ComplaintAccessGuard;
use OCA\Procest\Service\ComplaintService;
use OCA\Procest\Service\HearingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for ComplaintHearingController.
 *
 * @covers \OCA\Procest\Controller\ComplaintHearingController
 *
 * @uses \OCA\Procest\Service\Complaint\ComplaintAccessGuard
 */
class ComplaintHearingControllerContractTest extends TestCase {

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
	 * The HearingService mock.
	 *
	 * @var HearingService|MockObject
	 */
	private HearingService $hearingService;

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
	 * @var ComplaintHearingController
	 */
	private ComplaintHearingController $controller;

	/**
	 * Build the controller over a real ComplaintAccessGuard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->complaintService = $this->createMock(ComplaintService::class);
		$this->hearingService = $this->createMock(HearingService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new ComplaintHearingController(
			appName: 'procest',
			request: $this->request,
			complaintService: $this->complaintService,
			hearingService: $this->hearingService,
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
	 * `hearings` refuses an anonymous caller with 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testHearingsRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->hearingService->expects($this->never())->method('getHearingsForComplaint');

		$response = $this->controller->hearings(id: 'klacht-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testHearingsRefusesAnUnauthenticatedCallerWith401()

	/**
	 * `hearings` lists the hearings of the complaint named in the URL, under a
	 * `results` key.
	 *
	 * @return void
	 */
	public function testHearingsListsTheHearingsOfTheComplaintNamedInTheUrl(): void {
		$this->signIn();
		$this->hearingService->expects($this->once())
			->method('getHearingsForComplaint')
			->with('klacht-1')
			->willReturn([['id' => 'hoor-1', 'date' => '2026-09-01']]);

		$response = $this->controller->hearings(id: 'klacht-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['results' => [['id' => 'hoor-1', 'date' => '2026-09-01']]],
			$response->getData()
		);
	}//end testHearingsListsTheHearingsOfTheComplaintNamedInTheUrl()

	/**
	 * Listing hearings is a BROAD read: any authenticated user may list any
	 * complaint's hearings, matching ComplaintAccessGuard::authorizeAccess(),
	 * which deliberately grants read to every authenticated caller.
	 *
	 * This pins the posture rather than assuming it. If the read surface is ever
	 * narrowed to the behandelaar, this test must be updated deliberately — it
	 * will not silently keep passing.
	 *
	 * @return void
	 */
	public function testHearingsGrantsReadToAnyAuthenticatedCallerByDesign(): void {
		$this->signIn(uid: 'someone-else');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->hearingService->method('getHearingsForComplaint')->willReturn([]);

		$response = $this->controller->hearings(id: 'klacht-of-another-handler');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => []], $response->getData());
	}//end testHearingsGrantsReadToAnyAuthenticatedCallerByDesign()

	/**
	 * `recordHearingOutcome` refuses an anonymous caller with 401 and records
	 * nothing.
	 *
	 * @return void
	 */
	public function testRecordHearingOutcomeRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->hearingService->expects($this->never())->method('recordOutcome');
		$this->complaintService->expects($this->never())->method('transitionStatus');

		$response = $this->controller->recordHearingOutcome(id: 'klacht-1', hearingId: 'hoor-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testRecordHearingOutcomeRefusesAnUnauthenticatedCallerWith401()

	/**
	 * An unknown complaint answers 404 and neither records nor transitions.
	 *
	 * @return void
	 */
	public function testRecordHearingOutcomeReturns404ForAnUnknownComplaint(): void {
		$this->signIn();
		$this->complaintService->method('getComplaint')->willReturn(null);
		$this->hearingService->expects($this->never())->method('recordOutcome');
		$this->complaintService->expects($this->never())->method('transitionStatus');

		$response = $this->controller->recordHearingOutcome(id: 'nope', hearingId: 'hoor-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Complaint not found'], $response->getData());
	}//end testRecordHearingOutcomeReturns404ForAnUnknownComplaint()

	/**
	 * Recording an outcome is a MUTATION: a caller who is neither the assigned
	 * behandelaar nor an admin is refused before anything is written.
	 *
	 * @return void
	 */
	public function testRecordHearingOutcomeRefusesAStrangerToTheComplaint(): void {
		$this->signIn(uid: 'mallory');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('mallory')
			->willReturn(false);
		$this->hearingService->expects($this->never())->method('recordOutcome');
		$this->complaintService->expects($this->never())->method('transitionStatus');

		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Not authorized to modify this complaint');

		$this->controller->recordHearingOutcome(id: 'klacht-1', hearingId: 'hoor-1');
	}//end testRecordHearingOutcomeRefusesAStrangerToTheComplaint()

	/**
	 * The outcome is recorded against the HEARING id while the transition is
	 * applied to the COMPLAINT id, and the transition target is the literal
	 * `hoorgesprek_completed`.
	 *
	 * @return void
	 */
	public function testRecordHearingOutcomeWritesTheHearingAndAdvancesTheComplaint(): void {
		$this->signIn(uid: 'alice');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->request->method('getParams')->willReturn(['outcome' => 'gehoord']);

		$this->hearingService->expects($this->once())
			->method('recordOutcome')
			->with('hoor-9', ['outcome' => 'gehoord'])
			->willReturn(['id' => 'hoor-9', 'outcome' => 'gehoord']);

		$this->complaintService->expects($this->once())
			->method('transitionStatus')
			->with('klacht-1', 'hoorgesprek_completed')
			->willReturn(['id' => 'klacht-1', 'status' => 'hoorgesprek_completed']);

		$response = $this->controller->recordHearingOutcome(id: 'klacht-1', hearingId: 'hoor-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'hoor-9', 'outcome' => 'gehoord'], $response->getData());
	}//end testRecordHearingOutcomeWritesTheHearingAndAdvancesTheComplaint()

	/**
	 * A rejected outcome answers 400 and leaves the complaint's status alone —
	 * a complaint stamped "hearing completed" with no hearing on file would be
	 * an Awb record defect.
	 *
	 * @return void
	 */
	public function testRecordHearingOutcomeDoesNotAdvanceTheComplaintWhenRecordingFails(): void {
		$this->signIn(uid: 'alice');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->request->method('getParams')->willReturn(['outcome' => '']);
		$this->hearingService->method('recordOutcome')
			->willThrowException(new \RuntimeException('outcome is required'));
		$this->complaintService->expects($this->never())->method('transitionStatus');

		$response = $this->controller->recordHearingOutcome(id: 'klacht-1', hearingId: 'hoor-9');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'outcome is required'], $response->getData());
	}//end testRecordHearingOutcomeDoesNotAdvanceTheComplaintWhenRecordingFails()

	/**
	 * A coordinator may record an outcome on a complaint assigned to someone
	 * else — proving the refusal above is a real per-complaint decision and not
	 * a guard that refuses everybody.
	 *
	 * @return void
	 */
	public function testRecordHearingOutcomeAllowsACoordinatorOnAnotherHandlersComplaint(): void {
		$this->signIn(uid: 'coordinator');
		$this->complaintService->method('getComplaint')
			->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->groupManager->method('isAdmin')->with('coordinator')->willReturn(true);
		$this->request->method('getParams')->willReturn(['outcome' => 'gehoord']);
		$this->hearingService->expects($this->once())
			->method('recordOutcome')
			->willReturn(['id' => 'hoor-9']);
		$this->complaintService->method('transitionStatus')->willReturn([]);

		$response = $this->controller->recordHearingOutcome(id: 'klacht-1', hearingId: 'hoor-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testRecordHearingOutcomeAllowsACoordinatorOnAnotherHandlersComplaint()
}//end class
