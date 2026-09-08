<?php

/**
 * CaseLifecycleController wire contract.
 *
 * The refusals come first, because they are the whole point of this
 * controller. Four `#[NoAdminRequired]` endpoints that take a case uuid from
 * the URL are four ways for any signed-in user to move any case unless every
 * one of them consults the per-case guard BEFORE the service, and reopen also
 * asks the reopen authority. The excluded e2e scenario of REQ-STE-13 ("a user
 * without the reopen scope is refused") is asserted here: Playwright runs as
 * admin and cannot take a lesser role.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseLifecycleController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Suspend, resume, extend and reopen behind their guards.
 *
 * @covers \OCA\Dossiq\Controller\CaseLifecycleController
 */
class CaseLifecycleControllerTest extends TestCase {

	/**
	 * The lifecycle gestures.
	 *
	 * @var CaseLifecycleService&MockObject
	 */
	private CaseLifecycleService $lifecycle;

	/**
	 * The per-case guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The engine, consulted only for the reopen authority.
	 *
	 * @var StatusTransitionService&MockObject
	 */
	private StatusTransitionService $engine;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The request, carrying the posted reason.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Wire a controller with a signed-in user by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->lifecycle = $this->createMock(CaseLifecycleService::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->engine = $this->createMock(StatusTransitionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'reason' => 'Aanvulling gevraagd',
				'days' => 14,
				default => $default,
			}
		);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return CaseLifecycleController
	 */
	private function controller(): CaseLifecycleController {
		return new CaseLifecycleController(
			'dossiq',
			$this->request,
			$this->lifecycle,
			$this->guard,
			$this->engine,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A user who may not mutate the case is refused with 403, and the
	 * gesture never runs.
	 *
	 * @return void
	 */
	public function testSuspendIsRefusedWithoutCaseAccess(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->lifecycle->expects($this->never())->method('suspend');

		$response = $this->controller()->suspend(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSuspendIsRefusedWithoutCaseAccess()

	/**
	 * The per-case guard is asked with the case from the URL and the session
	 * user, not with anything the caller supplied.
	 *
	 * @return void
	 */
	public function testTheGuardIsAskedWithTheRoutesCaseAndTheSessionUser(): void {
		$seen = [];
		$this->guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId, IUser $user) use (&$seen): bool {
				$seen = [$caseId, $user->getUID()];
				return false;
			}
		);

		$this->controller()->resume(caseId: 'case-42');

		$this->assertSame(['case-42', 'behandelaar'], $seen);
	}//end testTheGuardIsAskedWithTheRoutesCaseAndTheSessionUser()

	/**
	 * REQ-STE-13: reopening needs the reopen authority on top of case access.
	 * A handler who may edit the case may not reopen it.
	 *
	 * @return void
	 */
	public function testReopenIsRefusedWithoutTheReopenAuthority(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->engine->method('isAdmin')->willReturn(false);
		$this->lifecycle->expects($this->never())->method('reopen');

		$response = $this->controller()->reopen(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testReopenIsRefusedWithoutTheReopenAuthority()

	/**
	 * An anonymous caller is 401, before any guard or gesture.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsUnauthenticated(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->userSession = $session;
		$this->guard->expects($this->never())->method('hasCaseMutationAccess');

		$response = $this->controller()->extend(caseId: 'case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnonymousCallerIsUnauthenticated()

	/**
	 * With access and the authority, reopen runs and answers the new state.
	 *
	 * @return void
	 */
	public function testReopenRunsForAnAuthorisedUser(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->engine->method('isAdmin')->willReturn(true);
		$this->lifecycle->expects($this->once())
			->method('reopen')
			->with(caseId: 'case-1', reason: 'Aanvulling gevraagd')
			->willReturn(['suspended' => false, 'isFinalStatus' => false]);

		$response = $this->controller()->reopen(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['isFinalStatus']);
	}//end testReopenRunsForAnAuthorisedUser()

	/**
	 * A case type that forbids the gesture answers 409 with the code the page
	 * turns into a sentence — not the exception's own message.
	 *
	 * @return void
	 */
	public function testARefusedGestureAnswersItsCode(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->lifecycle->method('suspend')
			->willThrowException(new RuntimeException('suspension_not_allowed'));

		$response = $this->controller()->suspend(caseId: 'case-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('suspension_not_allowed', $response->getData()['code']);
	}//end testARefusedGestureAnswersItsCode()

	/**
	 * An unexpected failure is a 500 that says nothing about itself.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureWithholdsItsDetail(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->lifecycle->method('extend')
			->willThrowException(new \LogicException('connection string leaked here'));

		$response = $this->controller()->extend(caseId: 'case-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('connection', json_encode($response->getData()));
	}//end testAnUnexpectedFailureWithholdsItsDetail()

	/**
	 * The state read is guarded too: which gestures a case allows is not
	 * public knowledge either.
	 *
	 * @return void
	 */
	public function testTheStateReadIsGuarded(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->lifecycle->expects($this->never())->method('state');

		$response = $this->controller()->state(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testTheStateReadIsGuarded()
}//end class
