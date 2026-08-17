<?php

/**
 * ConsultationController Wire-Contract Tests
 *
 * Contract coverage for `GET /api/consultations/overdue` (gate-25). Unlike its
 * sibling endpoints this one takes NO id: it is a cross-case listing of every
 * adviesaanvraag past its Awb 3:6 deadline, so the authentication branch is
 * the only thing between an anonymous caller and a list of overdue advisory
 * requests across the whole organisation. These tests pin:
 *
 *  - the refusal is the guard's real 401 and it happens BEFORE
 *    ConsultationService is consulted (the guard is instantiated for real, so
 *    a stubbed-away status cannot manufacture the pass);
 *  - the payload is nested under `results`, not returned bare — the sibling
 *    `show()` returns its object bare, so the two shapes are easy to confuse.
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

use OCA\Procest\Controller\ConsultationController;
use OCA\Procest\Service\Consultation\ConsultationAccessGuard;
use OCA\Procest\Service\ConsultationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for ConsultationController::overdue().
 *
 * @covers \OCA\Procest\Controller\ConsultationController
 *
 * @uses \OCA\Procest\Service\Consultation\ConsultationAccessGuard
 */
class ConsultationControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The consultation domain service.
	 *
	 * @var ConsultationService|MockObject
	 */
	private ConsultationService $consultationService;

	/**
	 * The user session driving the REAL access guard.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager driving the REAL access guard.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->consultationService = $this->createMock(ConsultationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * Build the controller behind a REAL ConsultationAccessGuard, so the 401
	 * asserted below is the guard's own response and not a test stub.
	 *
	 * @return ConsultationController
	 */
	private function controller(): ConsultationController {
		return new ConsultationController(
			appName: 'procest',
			request: $this->request,
			consultationService: $this->consultationService,
			accessGuard: new ConsultationAccessGuard(
				request: $this->request,
				consultationService: $this->consultationService,
				userSession: $this->userSession,
				groupManager: $this->groupManager,
			),
		);
	}//end controller()

	/**
	 * An unauthenticated caller gets 401 and no overdue list is assembled.
	 *
	 * @return void
	 */
	public function testOverdueRefusesAnUnauthenticatedCallerBeforeListingAnyConsultation(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->consultationService->expects($this->never())->method('getOverdueConsultations');

		$response = $this->controller()->overdue();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testOverdueRefusesAnUnauthenticatedCallerBeforeListingAnyConsultation()

	/**
	 * An authenticated caller gets the overdue set wrapped under `results`.
	 *
	 * @return void
	 */
	public function testOverdueWrapsTheOverdueConsultationsUnderResults(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('adviseur');
		$this->userSession->method('getUser')->willReturn($user);

		$overdue = [
			['id' => 'cn-1', 'deadline' => '2026-01-01'],
			['id' => 'cn-2', 'deadline' => '2026-02-01'],
		];

		$this->consultationService->expects($this->once())
			->method('getOverdueConsultations')
			->willReturn($overdue);

		$response = $this->controller()->overdue();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $overdue], $response->getData());
	}//end testOverdueWrapsTheOverdueConsultationsUnderResults()

	/**
	 * An empty overdue set is still the `results` envelope, never a bare array
	 * or a 404 — the frontend reads `.results` unconditionally.
	 *
	 * @return void
	 */
	public function testOverdueReturnsAnEmptyResultsEnvelopeWhenNothingIsOverdue(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('adviseur');
		$this->userSession->method('getUser')->willReturn($user);

		$this->consultationService->method('getOverdueConsultations')->willReturn([]);

		$response = $this->controller()->overdue();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => []], $response->getData());
	}//end testOverdueReturnsAnEmptyResultsEnvelopeWhenNothingIsOverdue()
}//end class
