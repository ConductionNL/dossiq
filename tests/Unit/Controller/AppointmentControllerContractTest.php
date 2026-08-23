<?php

/**
 * AppointmentController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the three AppointmentController endpoints
 * that had no automated proof of their wire behaviour.
 *
 * `cancel()` and `noShow()` are the controller's two mutating routes and they
 * carry ONLY an appointment id — no case id — so the guard they need cannot be
 * applied directly: the owning case has to be resolved first and the ordinary
 * per-case mutation guard applied to THAT. Two properties of that indirection
 * are pinned here because both fail silently:
 *
 *  - an appointment whose owning case cannot be resolved DENIES (403), so an
 *    unknown id is not an existence oracle and not an open door;
 *  - the guard is asked about the RESOLVED CASE id, never about the
 *    appointment id — passing the id it happens to have is the realistic
 *    defect, and it would make the guard pass or fail on the wrong object.
 *
 * `timeslots()` is deliberately unguarded beyond the session (it is an
 * availability probe against the scheduling backend's public catalogue), so
 * what is pinned there is that it still refuses an anonymous caller and that
 * it forwards the three query parameters — defaulting the date to today rather
 * than sending an empty one.
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

use OCA\Dossiq\Controller\AppointmentController;
use OCA\Dossiq\Service\AppointmentService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for AppointmentController.
 *
 * @covers \OCA\Dossiq\Controller\AppointmentController
 */
class AppointmentControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The appointment backend.
	 *
	 * @var AppointmentService|MockObject
	 */
	private AppointmentService $appointmentService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The per-case authorization guard (fails closed).
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The controller under test.
	 *
	 * @var AppointmentController
	 */
	private AppointmentController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->appointmentService = $this->createMock(AppointmentService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$this->controller = new AppointmentController(
			request: $this->request,
			appointmentService: $this->appointmentService,
			userSession: $this->userSession,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

	/**
	 * Put a user in the session.
	 *
	 * @param string $uid The uid the session user reports.
	 *
	 * @return IUser|MockObject The session user.
	 */
	private function signIn(string $uid = 'handler'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * Make `getParam()` behave like the real request: serve the override when
	 * configured, otherwise the caller's own default.
	 *
	 * @param array<string, mixed> $overrides Parameter values to serve.
	 *
	 * @return void
	 */
	private function withRequestParams(array $overrides): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($overrides): mixed {
				return ($overrides[$key] ?? $default);
			}
		);
	}//end withRequestParams()

	/**
	 * All three endpoints refuse an anonymous caller with 401 and reach neither
	 * the scheduling backend nor the case guard.
	 *
	 * @return void
	 */
	public function testAllThreeEndpointsRefuseAnAnonymousCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appointmentService->expects($this->never())->method('cancelAppointment');
		$this->appointmentService->expects($this->never())->method('markNoShow');
		$this->appointmentService->expects($this->never())->method('getTimeslots');
		$this->caseAccessGuard->expects($this->never())->method('hasCaseMutationAccess');

		$responses = [
			'cancel' => $this->controller->cancel(appointmentId: 'apt-1'),
			'noShow' => $this->controller->noShow(appointmentId: 'apt-1'),
			'timeslots' => $this->controller->timeslots(),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$endpoint . ' must refuse an anonymous caller'
			);
			$this->assertSame(['success' => false, 'error' => 'Not authenticated'], $response->getData());
		}
	}//end testAllThreeEndpointsRefuseAnAnonymousCallerWith401()

	/**
	 * An appointment id that resolves to no case DENIES with 403 and cancels
	 * nothing. Answering 404 here would turn the route into an existence
	 * oracle; answering 200 would make it an open door.
	 *
	 * @return void
	 */
	public function testCancelDeniesWhenTheOwningCaseCannotBeResolved(): void {
		$this->signIn();
		$this->appointmentService->method('getCaseIdForAppointment')->willReturn(null);
		$this->caseAccessGuard->expects($this->never())->method('hasCaseMutationAccess');
		$this->appointmentService->expects($this->never())->method('cancelAppointment');

		$response = $this->controller->cancel(appointmentId: 'apt-unknown');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Not authorized'], $response->getData());
	}//end testCancelDeniesWhenTheOwningCaseCannotBeResolved()

	/**
	 * The mutation guard is asked about the RESOLVED CASE id — not about the
	 * appointment id the route carries — and its refusal is a 403 that cancels
	 * nothing.
	 *
	 * @return void
	 */
	public function testCancelAsksTheGuardAboutTheResolvedCaseAndRefusesOnDenial(): void {
		$user = $this->signIn();
		$this->appointmentService->method('getCaseIdForAppointment')
			->with('apt-1')
			->willReturn('case-88');

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseMutationAccess')
			->with('case-88', $user)
			->willReturn(false);
		$this->appointmentService->expects($this->never())->method('cancelAppointment');

		$response = $this->controller->cancel(appointmentId: 'apt-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Not authorized'], $response->getData());
	}//end testCancelAsksTheGuardAboutTheResolvedCaseAndRefusesOnDenial()

	/**
	 * A cleared caller's cancel reaches the backend with the appointment id and
	 * gets the updated appointment back under `appointment`.
	 *
	 * @return void
	 */
	public function testCancelReturnsTheCancelledAppointmentForAClearedCaller(): void {
		$this->signIn();
		$this->appointmentService->method('getCaseIdForAppointment')->willReturn('case-88');
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);

		$this->appointmentService->expects($this->once())
			->method('cancelAppointment')
			->with('apt-1')
			->willReturn(['id' => 'apt-1', 'status' => 'cancelled']);
		$this->appointmentService->expects($this->never())->method('markNoShow');

		$response = $this->controller->cancel(appointmentId: 'apt-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['success' => true, 'appointment' => ['id' => 'apt-1', 'status' => 'cancelled']],
			$response->getData()
		);
	}//end testCancelReturnsTheCancelledAppointmentForAClearedCaller()

	/**
	 * noShow takes the SAME resolve-then-guard path as cancel: an unresolvable
	 * appointment denies, and the guard is asked about the resolved case.
	 *
	 * @return void
	 */
	public function testNoShowDeniesWhenTheGuardRefusesTheResolvedCase(): void {
		$user = $this->signIn();
		$this->appointmentService->method('getCaseIdForAppointment')
			->with('apt-2')
			->willReturn('case-99');

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseMutationAccess')
			->with('case-99', $user)
			->willReturn(false);
		$this->appointmentService->expects($this->never())->method('markNoShow');

		$response = $this->controller->noShow(appointmentId: 'apt-2');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Not authorized'], $response->getData());
	}//end testNoShowDeniesWhenTheGuardRefusesTheResolvedCase()

	/**
	 * noShow marks a no-show — it does NOT cancel. The two routes are otherwise
	 * identical, so the delegate each one picks is the thing worth pinning.
	 *
	 * @return void
	 */
	public function testNoShowMarksANoShowRatherThanCancelling(): void {
		$this->signIn();
		$this->appointmentService->method('getCaseIdForAppointment')->willReturn('case-99');
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);

		$this->appointmentService->expects($this->once())
			->method('markNoShow')
			->with('apt-2')
			->willReturn(['id' => 'apt-2', 'status' => 'no_show']);
		$this->appointmentService->expects($this->never())->method('cancelAppointment');

		$response = $this->controller->noShow(appointmentId: 'apt-2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['success' => true, 'appointment' => ['id' => 'apt-2', 'status' => 'no_show']],
			$response->getData()
		);
	}//end testNoShowMarksANoShowRatherThanCancelling()

	/**
	 * timeslots forwards product, location and date to the scheduling backend
	 * in that order and answers the slot list under `timeslots`.
	 *
	 * @return void
	 */
	public function testTimeslotsForwardsTheProductLocationAndDateQuery(): void {
		$this->signIn();
		$this->withRequestParams(
			[
				'productId' => 'prod-7',
				'locationId' => 'loc-3',
				'date' => '2026-09-01',
			]
		);

		$this->appointmentService->expects($this->once())
			->method('getTimeslots')
			->with('prod-7', 'loc-3', '2026-09-01')
			->willReturn([['start' => '09:00'], ['start' => '09:30']]);

		$response = $this->controller->timeslots();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['success' => true, 'timeslots' => [['start' => '09:00'], ['start' => '09:30']]],
			$response->getData()
		);
	}//end testTimeslotsForwardsTheProductLocationAndDateQuery()

	/**
	 * With no `date` in the query the backend is asked about TODAY — an empty
	 * date string would make the scheduling backend answer for no day at all.
	 *
	 * @return void
	 */
	public function testTimeslotsDefaultsTheDateToTodayWhenTheQueryOmitsIt(): void {
		$this->signIn();
		$this->withRequestParams(['productId' => 'prod-7']);

		$this->appointmentService->expects($this->once())
			->method('getTimeslots')
			->with('prod-7', '', date('Y-m-d'))
			->willReturn([]);

		$response = $this->controller->timeslots();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true, 'timeslots' => []], $response->getData());
	}//end testTimeslotsDefaultsTheDateToTodayWhenTheQueryOmitsIt()
}//end class
