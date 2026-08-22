<?php

/**
 * ParafeerRouteController Wire-Contract Tests
 *
 * Contract coverage for the two `@NoAdminRequired` parafering-engine actions
 * (gate-25): `POST /api/parafeer-route/voorstel/{voorstelId}/complete-step`
 * and `.../add-step`. Their sibling `skipStep()` is admin-gated; these two are
 * not, so the controller's own `requireUser()` is the ONLY barrier between an
 * anonymous POST and a signed-off parafering step. These tests pin:
 *
 *  - both endpoints refuse an unauthenticated caller with 401 BEFORE
 *    ParafeerRouteService is entered — a completed step is an approval
 *    signature, so a missing session check is a forged sign-off;
 *  - the voorstel id from the URL is what the engine acts on;
 *  - `addStep` defaults an absent `afterStep` to 0 and an absent/non-array
 *    `stepData` to an empty array rather than forwarding null into a typed
 *    `int`/`array` parameter (a TypeError would surface as an opaque 500);
 *  - an engine failure is a 500 carrying a GENERIC Dutch message, never the
 *    exception text.
 *
 * Both endpoints read their body through `file_get_contents('php://input')`,
 * which is empty under PHPUnit — so the body-derived arguments asserted here
 * are the documented defaults, which is exactly the path a client that posts
 * an empty body takes.
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

use OCA\Dossiq\Controller\ParafeerRouteController;
use OCA\Dossiq\Service\ParafeerRouteService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for ParafeerRouteController.
 *
 * @covers \OCA\Dossiq\Controller\ParafeerRouteController
 */
class ParafeerRouteControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The parafering engine service.
	 *
	 * @var ParafeerRouteService|MockObject
	 */
	private ParafeerRouteService $routeService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager (admin check, used only by skipStep()).
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var ParafeerRouteController
	 */
	private ParafeerRouteController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->routeService = $this->createMock(ParafeerRouteService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ParafeerRouteController(
			appName: 'dossiq',
			request: $this->request,
			routeService: $this->routeService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Put a signed-in, non-admin user on the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('paraferende-ambtenaar');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}//end signIn()

	/**
	 * Neither engine action may run without a session.
	 *
	 * @return void
	 */
	public function testBothEngineActionsRefuseAnUnauthenticatedCallerBeforeTouchingTheRoute(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->routeService->expects($this->never())->method('completeStep');
		$this->routeService->expects($this->never())->method('addAdhocStep');

		$complete = $this->controller->completeStep(proposalId: 'voorstel-1');
		$add = $this->controller->addStep(proposalId: 'voorstel-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $complete->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $complete->getData());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $add->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $add->getData());
	}//end testBothEngineActionsRefuseAnUnauthenticatedCallerBeforeTouchingTheRoute()

	/**
	 * completeStep acts on the voorstel from the URL and returns the engine's
	 * result untouched.
	 *
	 * @return void
	 */
	public function testCompleteStepCompletesTheStepOnTheVoorstelFromTheUrl(): void {
		$this->signIn();
		$result = ['step' => 2, 'status' => 'completed'];

		$this->routeService->expects($this->once())
			->method('completeStep')
			->with('voorstel-77', [])
			->willReturn($result);

		$response = $this->controller->completeStep(proposalId: 'voorstel-77');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}//end testCompleteStepCompletesTheStepOnTheVoorstelFromTheUrl()

	/**
	 * An engine failure on completeStep is a 500 with a generic message that
	 * does not leak the internal exception text.
	 *
	 * @return void
	 */
	public function testCompleteStepReturns500WithoutLeakingTheEngineFailure(): void {
		$this->signIn();

		$this->routeService->method('completeStep')
			->willThrowException(new \RuntimeException('SQLSTATE[23000] duplicate key parafeerroute_pk'));

		$response = $this->controller->completeStep(proposalId: 'voorstel-77');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Stap kon niet worden voltooid'], $response->getData());
	}//end testCompleteStepReturns500WithoutLeakingTheEngineFailure()

	/**
	 * addStep forwards typed defaults, never nulls, into the engine's
	 * `int $afterStep` / `array $stepData` parameters.
	 *
	 * @return void
	 */
	public function testAddStepForwardsTypedDefaultsForAnEmptyBody(): void {
		$this->signIn();
		$snapshot = ['steps' => [['order' => 1], ['order' => 2]]];

		$this->routeService->expects($this->once())
			->method('addAdhocStep')
			->with('voorstel-77', $this->identicalTo(0), $this->identicalTo([]))
			->willReturn($snapshot);

		$response = $this->controller->addStep(proposalId: 'voorstel-77');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($snapshot, $response->getData());
	}//end testAddStepForwardsTypedDefaultsForAnEmptyBody()

	/**
	 * An engine failure on addStep is a 500 with its own generic message,
	 * distinct from the completeStep one.
	 *
	 * @return void
	 */
	public function testAddStepReturns500WithItsOwnGenericMessage(): void {
		$this->signIn();

		$this->routeService->method('addAdhocStep')
			->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->addStep(proposalId: 'voorstel-77');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Stap toevoegen mislukt'], $response->getData());
	}//end testAddStepReturns500WithItsOwnGenericMessage()
}//end class
