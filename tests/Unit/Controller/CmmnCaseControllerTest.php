<?php

/**
 * CmmnCaseController tests.
 *
 * Covers authentication (401), the OR-RBAC group-authorization gate (403 for
 * a plan item's `authorization` list), happy-path pass-through to
 * `CaseModelEngine`, and illegal-transition (409) / not-found (404) error
 * mapping.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\CmmnCaseController;
use OCA\Procest\Service\Cmmn\CaseModelEngine;
use OCA\Procest\Service\Cmmn\IllegalPlanItemTransitionException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Controller\CmmnCaseController
 *
 * @uses \OCA\Procest\Service\Cmmn\IllegalPlanItemTransitionException
 */
final class CmmnCaseControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var CaseModelEngine&MockObject
	 */
	private CaseModelEngine $engine;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The controller under test (authenticated as 'alice', in no groups).
	 *
	 * @var CmmnCaseController
	 */
	private CmmnCaseController $controller;

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->engine = $this->createMock(CaseModelEngine::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		// No default isInGroup() stub here: PHPUnit resolves multiple
		// `method()` configurations on the same mock in matcher-registration
		// order, so a blanket setUp() default would shadow a more specific
		// callback configured inside an individual test. Each test that
		// needs group membership configures `isInGroup()` itself.

		$this->controller = new CmmnCaseController(
			'procest',
			$this->request,
			$this->engine,
			$this->userSession,
			$this->groupManager,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Build an unauthenticated controller.
	 *
	 * @return CmmnCaseController
	 */
	private function unauthenticatedController(): CmmnCaseController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new CmmnCaseController(
			'procest',
			$this->request,
			$this->engine,
			$session,
			$this->groupManager,
			$this->createMock(LoggerInterface::class),
		);
	}//end unauthenticatedController()

	/**
	 * `plan()` returns 401 when no user is authenticated.
	 *
	 * @return void
	 */
	public function testPlanReturns401WhenUnauthenticated(): void {
		$this->engine->expects($this->never())->method('getCasePlan');
		$response = $this->unauthenticatedController()->plan(caseId: 'case-1');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testPlanReturns401WhenUnauthenticated()

	/**
	 * `plan()` happy path returns the engine's plan view.
	 *
	 * @return void
	 */
	public function testPlanReturnsEngineResult(): void {
		$this->engine->method('getCasePlan')->with('case-1')->willReturn(['items' => [], 'enableableDiscretionary' => []]);
		$response = $this->controller->plan(caseId: 'case-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['items' => [], 'enableableDiscretionary' => []], $response->getData());
	}//end testPlanReturnsEngineResult()

	/**
	 * `plan()` maps `case_not_cmmn_managed` to 409.
	 *
	 * @return void
	 */
	public function testPlanMapsNotCmmnManagedTo409(): void {
		$this->engine->method('getCasePlan')->willThrowException(new RuntimeException('case_not_cmmn_managed'));
		$response = $this->controller->plan(caseId: 'case-1');
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testPlanMapsNotCmmnManagedTo409()

	/**
	 * `plan()` maps `case_not_found` to 404.
	 *
	 * @return void
	 */
	public function testPlanMapsNotFoundTo404(): void {
		$this->engine->method('getCasePlan')->willThrowException(new RuntimeException('case_not_found'));
		$response = $this->controller->plan(caseId: 'case-1');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testPlanMapsNotFoundTo404()

	/**
	 * `enable()` returns 401 when no user is authenticated.
	 *
	 * @return void
	 */
	public function testEnableReturns401WhenUnauthenticated(): void {
		$this->request->method('getParams')->willReturn(['itemId' => 'disc-1']);
		$this->engine->expects($this->never())->method('enableDiscretionaryItem');
		$response = $this->unauthenticatedController()->enable(caseId: 'case-1');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testEnableReturns401WhenUnauthenticated()

	/**
	 * `enable()` returns 400 when `itemId` is missing.
	 *
	 * @return void
	 */
	public function testEnableReturns400WhenItemIdMissing(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->engine->expects($this->never())->method('enableDiscretionaryItem');
		$response = $this->controller->enable(caseId: 'case-1');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testEnableReturns400WhenItemIdMissing()

	/**
	 * `enable()` returns 403 and never invokes the engine when the acting
	 * user is not in any of the plan item's authorized groups.
	 *
	 * @return void
	 */
	public function testEnableReturns403WhenGroupUnauthorized(): void {
		$this->request->method('getParams')->willReturn(['itemId' => 'disc-1']);
		$this->engine->method('getPlanItemAuthorization')->with('case-1', 'disc-1')->willReturn(['procest-enforcement']);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->engine->expects($this->never())->method('enableDiscretionaryItem');

		$response = $this->controller->enable(caseId: 'case-1');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testEnableReturns403WhenGroupUnauthorized()

	/**
	 * `enable()` proceeds when the item's authorization list is empty (open).
	 *
	 * @return void
	 */
	public function testEnableProceedsWhenAuthorizationOpen(): void {
		$this->request->method('getParams')->willReturn(['itemId' => 'disc-1']);
		$this->engine->method('getPlanItemAuthorization')->willReturn([]);
		$this->engine->expects($this->once())->method('enableDiscretionaryItem')->with('case-1', 'disc-1')->willReturn(['items' => []]);

		$response = $this->controller->enable(caseId: 'case-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testEnableProceedsWhenAuthorizationOpen()

	/**
	 * `enable()` proceeds when the user belongs to an authorized group.
	 *
	 * @return void
	 */
	public function testEnableProceedsWhenUserInAuthorizedGroup(): void {
		$this->request->method('getParams')->willReturn(['itemId' => 'disc-1']);
		$this->engine->method('getPlanItemAuthorization')->willReturn(['procest-enforcement']);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $gid === 'procest-enforcement',
		);
		$this->engine->expects($this->once())->method('enableDiscretionaryItem')->willReturn(['items' => []]);

		$response = $this->controller->enable(caseId: 'case-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testEnableProceedsWhenUserInAuthorizedGroup()

	/**
	 * An `IllegalPlanItemTransitionException` from the engine maps to 409
	 * with `from`/`to` context.
	 *
	 * @return void
	 */
	public function testEnableMapsIllegalTransitionTo409(): void {
		$this->request->method('getParams')->willReturn(['itemId' => 'disc-1']);
		$this->engine->method('getPlanItemAuthorization')->willReturn([]);
		$this->engine->method('enableDiscretionaryItem')->willThrowException(
			new IllegalPlanItemTransitionException(itemId: 'disc-1', itemType: 'humanTask', fromState: 'available', toState: 'active'),
		);

		$response = $this->controller->enable(caseId: 'case-1');
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('available', $response->getData()['from']);
		self::assertSame('active', $response->getData()['to']);
	}//end testEnableMapsIllegalTransitionTo409()

	/**
	 * `complete()`/`terminate()` follow the same shared mutate() path.
	 *
	 * @return void
	 */
	public function testCompleteAndTerminateProceedWhenAuthorized(): void {
		$this->request->method('getParams')->willReturn(['itemId' => 't1']);
		$this->engine->method('getPlanItemAuthorization')->willReturn([]);
		$this->engine->expects($this->once())->method('completeTask')->with('case-1', 't1')->willReturn(['items' => []]);
		self::assertSame(Http::STATUS_OK, $this->controller->complete(caseId: 'case-1')->getStatus());

		$this->engine->expects($this->once())->method('terminateTask')->with('case-1', 't1')->willReturn(['items' => []]);
		self::assertSame(Http::STATUS_OK, $this->controller->terminate(caseId: 'case-1')->getStatus());
	}//end testCompleteAndTerminateProceedWhenAuthorized()

	/**
	 * `signal()` returns 401 when unauthenticated.
	 *
	 * @return void
	 */
	public function testSignalReturns401WhenUnauthenticated(): void {
		$this->engine->expects($this->never())->method('signalCaseFileEvent');
		$response = $this->unauthenticatedController()->signal(caseId: 'case-1');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testSignalReturns401WhenUnauthenticated()

	/**
	 * `signal()` returns 400 when `updates` is missing/empty.
	 *
	 * @return void
	 */
	public function testSignalReturns400WhenUpdatesMissing(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->engine->expects($this->never())->method('signalCaseFileEvent');
		$response = $this->controller->signal(caseId: 'case-1');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testSignalReturns400WhenUpdatesMissing()

	/**
	 * `signal()` happy path passes `updates` straight through.
	 *
	 * @return void
	 */
	public function testSignalProceedsWithUpdates(): void {
		$this->request->method('getParams')->willReturn(['updates' => ['urgent' => true]]);
		$this->engine->expects($this->once())->method('signalCaseFileEvent')->with('case-1', ['urgent' => true])->willReturn(['items' => []]);
		$response = $this->controller->signal(caseId: 'case-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testSignalProceedsWithUpdates()
}//end class
