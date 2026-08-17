<?php

/**
 * ContactMomentController Wire-Contract Tests
 *
 * Contract coverage for the four KCC endpoints that had no automated proof of
 * their wire behaviour (gate-25): `statusGeven`, `doorverbinden`,
 * `acceptDoorverbinding` and `rejectDoorverbinding`.
 *
 * These four differ from their neighbours on this controller in one respect
 * that the tests make explicit: `create()`, `index()`, `voorblad()`,
 * `nieuweZaak()` and `klachtRegistreren()` all sit behind
 * `CitizenLookupGuard::isCitizenLookupAllowed()` because they resolve a
 * caller-supplied CITIZEN identifier, while these four address a case or a
 * transfer record and are gated on the session alone. What every one of them
 * must never do is take the acting identity from the request:
 *
 *  - `doorverbinden` records `fromEmployeeId` from the session;
 *  - `acceptDoorverbinding` accepts AS the session user;
 *  - `rejectDoorverbinding` rejects AS the session user;
 *  - `statusGeven` logs the activity under the session user.
 *
 * A caller able to supply those would attribute a warm transfer, an acceptance
 * or a citizen-facing status update to a colleague. Each test therefore posts a
 * contradicting value in the body and asserts the session value wins.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ContactMomentController;
use OCA\Procest\Service\BurgerIdentificationService;
use OCA\Procest\Service\CaseVoorbladService;
use OCA\Procest\Service\CitizenLookupGuard;
use OCA\Procest\Service\ContactMomentService;
use OCA\Procest\Service\DoorverbindingService;
use OCA\Procest\Service\QuickActionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Wire-contract tests for ContactMomentController.
 *
 * @covers \OCA\Procest\Controller\ContactMomentController
 */
class ContactMomentControllerContractTest extends TestCase {

	/**
	 * The inbound request mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The contactmoment service mock (activity logging).
	 *
	 * @var ContactMomentService|MockObject
	 */
	private ContactMomentService $contactMomentService;

	/**
	 * The quick-action service mock.
	 *
	 * @var QuickActionService|MockObject
	 */
	private QuickActionService $quickActionService;

	/**
	 * The doorverbinding (warm transfer) service mock.
	 *
	 * @var DoorverbindingService|MockObject
	 */
	private DoorverbindingService $transferService;

	/**
	 * The session mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var ContactMomentController
	 */
	private ContactMomentController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->contactMomentService = $this->createMock(ContactMomentService::class);
		$this->quickActionService = $this->createMock(QuickActionService::class);
		$this->transferService = $this->createMock(DoorverbindingService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new ContactMomentController(
			appName: 'procest',
			request: $this->request,
			contactMomentService: $this->contactMomentService,
			caseVoorbladService: $this->createMock(CaseVoorbladService::class),
			quickActionService: $this->quickActionService,
			transferService: $this->transferService,
			burgerService: $this->createMock(BurgerIdentificationService::class),
			userSession: $this->userSession,
			citizenLookupGuard: $this->createMock(CitizenLookupGuard::class),
		);
	}//end setUp()

	/**
	 * Mark the session as authenticated for KCC employee `alice`.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * Feed the request parameter bag.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
	}//end withParams()

	/**
	 * All four endpoints refuse an anonymous caller with 401 and never touch a
	 * case, a transfer record or the activity log.
	 *
	 * @return void
	 */
	public function testAllFourEndpointsRefuseAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams(['caseId' => 'case-1', 'reason' => 'geen tijd']);

		$this->quickActionService->expects($this->never())->method('executeStatusTerugkoppelen');
		$this->transferService->expects($this->never())->method('initiateWarmTransfer');
		$this->transferService->expects($this->never())->method('acceptTransfer');
		$this->transferService->expects($this->never())->method('rejectTransfer');
		$this->contactMomentService->expects($this->never())->method('recordActivity');

		$responses = [
			'statusGeven' => $this->controller->statusGeven(),
			'doorverbinden' => $this->controller->doorverbinden(),
			'acceptDoorverbinding' => $this->controller->acceptDoorverbinding(id: 'dv-1'),
			'rejectDoorverbinding' => $this->controller->rejectDoorverbinding(id: 'dv-1'),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$endpoint . ' must answer 401 for a caller without a session'
			);
			$this->assertSame(
				['error' => 'Not authenticated'],
				$response->getData(),
				$endpoint . ' must answer the standard unauthenticated body'
			);
		}
	}//end testAllFourEndpointsRefuseAnAnonymousCaller()

	/**
	 * Without `confirm`, statusGeven only DRAFTS: the draft text is returned and
	 * nothing is written to the case's activity log.
	 *
	 * This is the whole point of the two-step quick-action — the KCC employee
	 * reads the generated text to the citizen before it is recorded as given.
	 *
	 * @return void
	 */
	public function testStatusGevenDraftsWithoutRecordingWhenNotConfirmed(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-1']);

		$draft = ['draftText' => 'Uw aanvraag heeft de status: in behandeling.', 'status' => 'in_behandeling'];

		$this->quickActionService->expects($this->once())
			->method('executeStatusTerugkoppelen')
			->with('case-1')
			->willReturn($draft);

		$this->contactMomentService->expects($this->never())->method('recordActivity');

		$response = $this->controller->statusGeven();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($draft, $response->getData());
	}//end testStatusGevenDraftsWithoutRecordingWhenNotConfirmed()

	/**
	 * With `confirm`, statusGeven records the activity against the case under
	 * the SESSION employee id, tagged `status_given`, carrying the draft text
	 * that was actually generated — and still answers with the draft.
	 *
	 * @return void
	 */
	public function testStatusGevenRecordsTheActivityUnderTheSessionEmployeeWhenConfirmed(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-1', 'confirm' => true, 'kccEmployeeId' => 'mallory']);

		$draft = ['draftText' => 'Uw aanvraag heeft de status: in behandeling.'];

		$this->quickActionService->method('executeStatusTerugkoppelen')->willReturn($draft);

		$this->contactMomentService->expects($this->once())
			->method('recordActivity')
			->with('case-1', '', 'status_given', 'alice', 'Uw aanvraag heeft de status: in behandeling.')
			->willReturn(true);

		$response = $this->controller->statusGeven();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($draft, $response->getData());
	}//end testStatusGevenRecordsTheActivityUnderTheSessionEmployeeWhenConfirmed()

	/**
	 * An unresolvable case makes statusGeven a 400 carrying the service reason,
	 * not a 500 and not an empty draft the employee would read out loud.
	 *
	 * @return void
	 */
	public function testStatusGevenMapsAnUnresolvableCaseTo400(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-missing', 'confirm' => true]);

		$this->quickActionService->method('executeStatusTerugkoppelen')
			->willThrowException(new RuntimeException('Case not found'));

		$this->contactMomentService->expects($this->never())->method('recordActivity');

		$response = $this->controller->statusGeven();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Case not found'], $response->getData());
	}//end testStatusGevenMapsAnUnresolvableCaseTo400()

	/**
	 * doorverbinden builds the transfer payload from the request but takes
	 * `fromEmployeeId` from the SESSION, and maps the request's `reason`
	 * parameter onto the service's `transferReason` key.
	 *
	 * The body here supplies a contradicting `fromEmployeeId` — it must not
	 * appear in the payload.
	 *
	 * @return void
	 */
	public function testDoorverbindenTakesTheOriginatingEmployeeFromTheSession(): void {
		$this->authenticate();
		$this->withParams(
			[
				'interactionId' => 'cm-1',
				'fromEmployeeId' => 'mallory',
				'toEmployeeId' => 'bob',
				'toQueue' => null,
				'reason' => 'Specialistische vraag',
				'contextSnapshot' => '{"caseId":"case-1"}',
			]
		);

		$initiated = ['id' => 'dv-1', 'status' => 'initiated'];

		$this->transferService->expects($this->once())
			->method('initiateWarmTransfer')
			->with(
				[
					'interactionId' => 'cm-1',
					'fromEmployeeId' => 'alice',
					'toEmployeeId' => 'bob',
					'toQueue' => null,
					'transferReason' => 'Specialistische vraag',
					'contextSnapshot' => '{"caseId":"case-1"}',
				]
			)
			->willReturn($initiated);

		$response = $this->controller->doorverbinden();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($initiated, $response->getData());
	}//end testDoorverbindenTakesTheOriginatingEmployeeFromTheSession()

	/**
	 * A transfer the service rejects (e.g. no interaction id) is a 400 carrying
	 * the service reason.
	 *
	 * @return void
	 */
	public function testDoorverbindenMapsAServiceRefusalTo400(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->transferService->method('initiateWarmTransfer')
			->willThrowException(new RuntimeException('contactmomentId and vanMedewerkerId are required'));

		$response = $this->controller->doorverbinden();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'contactmomentId and vanMedewerkerId are required'],
			$response->getData()
		);
	}//end testDoorverbindenMapsAServiceRefusalTo400()

	/**
	 * acceptDoorverbinding accepts the transfer named in the ROUTE as the
	 * SESSION user — the caller uid is what the service checks the assignment
	 * against, so a body-supplied uid would let anybody accept anybody's
	 * transfer.
	 *
	 * @return void
	 */
	public function testAcceptDoorverbindingAcceptsAsTheSessionUser(): void {
		$this->authenticate();
		$this->withParams(['callerUid' => 'mallory', 'id' => 'dv-other']);

		$accepted = ['id' => 'dv-1', 'accepted' => true];

		$this->transferService->expects($this->once())
			->method('acceptTransfer')
			->with('dv-1', 'alice')
			->willReturn($accepted);

		$response = $this->controller->acceptDoorverbinding(id: 'dv-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($accepted, $response->getData());
	}//end testAcceptDoorverbindingAcceptsAsTheSessionUser()

	/**
	 * A transfer that was already answered cannot be accepted twice: the
	 * service's refusal surfaces as a 400 with its reason, not a 200 that would
	 * make the second specialist believe the call is theirs.
	 *
	 * @return void
	 */
	public function testAcceptDoorverbindingMapsAnAlreadyAnsweredTransferTo400(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->transferService->method('acceptTransfer')
			->willThrowException(new RuntimeException('Doorverbinding already answered'));

		$response = $this->controller->acceptDoorverbinding(id: 'dv-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Doorverbinding already answered'], $response->getData());
	}//end testAcceptDoorverbindingMapsAnAlreadyAnsweredTransferTo400()

	/**
	 * rejectDoorverbinding forwards the route id, the `reason` parameter and
	 * the SESSION uid — in that order. `acceptTransfer` and `rejectTransfer`
	 * both end with the caller uid, so a reason/uid transposition here would
	 * record the rejecting employee as the reason.
	 *
	 * @return void
	 */
	public function testRejectDoorverbindingForwardsReasonAndSessionUserInOrder(): void {
		$this->authenticate();
		$this->withParams(['reason' => 'Geen capaciteit', 'callerUid' => 'mallory']);

		$rejected = ['id' => 'dv-1', 'accepted' => false, 'rejectionReason' => 'Geen capaciteit'];

		$this->transferService->expects($this->once())
			->method('rejectTransfer')
			->with('dv-1', 'Geen capaciteit', 'alice')
			->willReturn($rejected);

		$response = $this->controller->rejectDoorverbinding(id: 'dv-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($rejected, $response->getData());
	}//end testRejectDoorverbindingForwardsReasonAndSessionUserInOrder()

	/**
	 * A rejection without a reason is refused by the service and surfaces as a
	 * 400 — a doorverbinding may not be bounced back to the KCC unexplained.
	 *
	 * @return void
	 */
	public function testRejectDoorverbindingRequiresAReason(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->transferService->expects($this->once())
			->method('rejectTransfer')
			->with('dv-1', '', 'alice')
			->willThrowException(new RuntimeException('Rejection reason is required'));

		$response = $this->controller->rejectDoorverbinding(id: 'dv-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Rejection reason is required'], $response->getData());
	}//end testRejectDoorverbindingRequiresAReason()
}//end class
