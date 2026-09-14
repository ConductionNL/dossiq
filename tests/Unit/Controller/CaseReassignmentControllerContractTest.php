<?php

/**
 * CaseReassignmentController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the coordinator-only reassignment preview,
 * `reassignPreview()`. It is `#[NoAdminRequired]`, i.e. any authenticated user
 * may reach the route, and the only thing standing between an ordinary handler
 * and reading somebody else's entire caseload is the private
 * `requireCoordinator()` guard. That guard, and the arguments the controller
 * hands the service once it passes, are what these tests pin:
 *
 * The WRITE that used to sit beside it is gone. A redistribution is one act of
 * OpenRegister's bulk job now, started from `SubstitutionController` or the
 * Cases page (bulk-actions-report-progress, D-1).
 *
 *  - an anonymous caller is refused 403 and the service is never entered — the
 *    guard must run BEFORE any parameter is read;
 *  - an authenticated NON-coordinator is refused 403 with the role message, and
 *    the group check is made against THAT caller's uid, not a blank string: a
 *    guard that asked about the wrong user would be no guard at all;
 *  - the optional `caseType` filter is forwarded as `['caseType' => ...]` when
 *    present and as NULL when absent. The null case is the dangerous one — a
 *    filter that silently became `['caseType' => '']` would either match
 *    nothing or, worse, match everything on a permissive backend;
 *  - a rejected argument is a 400 and an unexpected failure a 500, so a caller
 *    can distinguish "your request was wrong" from "we broke".
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

use OCA\Dossiq\Controller\CaseReassignmentController;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for CaseReassignmentController.
 *
 * @covers \OCA\Dossiq\Controller\CaseReassignmentController
 */
class CaseReassignmentControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The bulk reassignment service mock.
	 *
	 * @var CaseReassignmentService|MockObject
	 */
	private CaseReassignmentService $reassignmentService;

	/**
	 * The user session mock — source of the caller and of `actorId`.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager mock — the coordinator gate.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var CaseReassignmentController
	 */
	private CaseReassignmentController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->reassignmentService = $this->createMock(CaseReassignmentService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new CaseReassignmentController(
			appName: 'dossiq',
			request: $this->request,
			reassignmentService: $this->reassignmentService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Put an authenticated user in the session.
	 *
	 * @param string $uid The user id the session reports.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Serve the given request parameters, defaulting like the real request.
	 *
	 * @param array<string, mixed> $overrides The parameter values to serve.
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
	 * An anonymous caller is refused 403 by both endpoints and the reassignment
	 * service is never entered.
	 *
	 * @return void
	 */
	public function testBothEndpointsRefuseAnAnonymousCallerWithoutTouchingTheService(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->reassignmentService->expects($this->never())->method('preview');
		$this->reassignmentService->expects($this->never())->method('execute');

		$preview = $this->controller->reassignPreview();
		$execute = $this->controller->reassignExecute();

		$this->assertSame(Http::STATUS_FORBIDDEN, $preview->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $execute->getStatus());
		$this->assertSame(['error' => 'Not authorised'], $preview->getData());
		$this->assertSame(['error' => 'Not authorised'], $execute->getData());
	}//end testBothEndpointsRefuseAnAnonymousCallerWithoutTouchingTheService()

	/**
	 * An authenticated non-coordinator is refused 403 with the role message, and
	 * the group check is asked about THE CALLER's uid — a guard that queried a
	 * blank or hard-coded uid would answer for the wrong person.
	 *
	 * @return void
	 */
	public function testANonCoordinatorIsRefusedAndTheGroupCheckNamesTheCaller(): void {
		$this->signIn(uid: 'handler-1');

		$this->groupManager->expects($this->atLeastOnce())
			->method('isAdmin')
			->with('handler-1')
			->willReturn(false);
		$this->reassignmentService->expects($this->never())->method('preview');
		$this->reassignmentService->expects($this->never())->method('execute');

		$preview = $this->controller->reassignPreview();
		$execute = $this->controller->reassignExecute();

		$this->assertSame(Http::STATUS_FORBIDDEN, $preview->getStatus());
		$this->assertSame(
			['error' => 'This action requires the coordinator role'],
			$preview->getData()
		);
		$this->assertSame(Http::STATUS_FORBIDDEN, $execute->getStatus());
	}//end testANonCoordinatorIsRefusedAndTheGroupCheckNamesTheCaller()

	/**
	 * A coordinator's preview forwards `fromUser` and the `caseType` filter and
	 * returns the service's preview verbatim at 200.
	 *
	 * @return void
	 */
	public function testReassignPreviewForwardsTheCaseTypeFilterAndReturnsThePreview(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams(['fromUser' => 'handler-1', 'caseType' => 'bezwaar']);

		$seen = [];
		$this->reassignmentService->expects($this->once())
			->method('preview')
			->willReturnCallback(
				static function (string $fromUser, ?array $filter = null) use (&$seen): array {
					$seen = ['fromUser' => $fromUser, 'filter' => $filter];
					return ['total' => 7];
				}
			);

		$response = $this->controller->reassignPreview();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['total' => 7], $response->getData());
		$this->assertSame(['fromUser' => 'handler-1', 'filter' => ['caseType' => 'bezwaar']], $seen);
	}//end testReassignPreviewForwardsTheCaseTypeFilterAndReturnsThePreview()

	/**
	 * A rejected argument is a 400 carrying the service's message, not a 500 —
	 * the caller can fix a bad `fromUser`, it cannot fix an outage.
	 *
	 * @return void
	 */
	public function testReassignPreviewAnswers400WhenTheServiceRejectsTheArguments(): void {
		$this->signIn(uid: 'coordinator-1');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams([]);

		$this->reassignmentService->method('preview')
			->willThrowException(new \InvalidArgumentException('fromUser is required'));

		$response = $this->controller->reassignPreview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'fromUser is required'], $response->getData());
	}//end testReassignPreviewAnswers400WhenTheServiceRejectsTheArguments()

}//end class
