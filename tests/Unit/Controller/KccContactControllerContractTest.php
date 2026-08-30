<?php

/**
 * KccContactController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the three callback endpoints on
 * KccContactController that had no automated proof of their wire behaviour.
 *
 * This controller performs NO per-object authorization itself. It resolves two
 * values and hands them to the service, which does the scoping:
 *
 *  - `agentId` — the SESSION's uid. Reading it from the request instead would
 *    let any agent act as, and list, another agent's callbacks;
 *  - `isPrivileged` — the answer IGroupManager gives about THAT uid. Passing a
 *    hardcoded `true`, or omitting the argument so the service's own default
 *    applies, is the realistic defect: it would silently widen every listing
 *    from "my callbacks" to "the whole KCC queue" and let any agent cancel any
 *    callback. Both the false and the true case are pinned.
 *
 * The status codes are pinned too, because the controller deliberately maps the
 * SAME exception type to different codes per route: a rejected schedule or list
 * filter is a 400, while a callback the caller may not touch is a 404 — the KCC
 * service refuses ownership violations by treating them as absent, so a 403
 * here would leak the existence of another agent's callback.
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

use OCA\Dossiq\Controller\KccContactController;
use OCA\Dossiq\Service\Kcc\CallbackService;
use OCA\Dossiq\Service\Kcc\ContactMomentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for the KccContactController callback endpoints.
 *
 * @covers \OCA\Dossiq\Controller\KccContactController
 */
class KccContactControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The contact-moment service (untouched by the callback routes).
	 *
	 * @var ContactMomentService|MockObject
	 */
	private ContactMomentService $contactMomentService;

	/**
	 * The callback service.
	 *
	 * @var CallbackService|MockObject
	 */
	private CallbackService $callbackService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager consulted for the privileged (cross-agent) flag.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The controller under test.
	 *
	 * @var KccContactController
	 */
	private KccContactController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->contactMomentService = $this->createMock(ContactMomentService::class);
		$this->callbackService = $this->createMock(CallbackService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new KccContactController(
			request: $this->request,
			contactMomentService: $this->contactMomentService,
			callbackService: $this->callbackService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
		);
	}//end setUp()

	/**
	 * Put an agent in the session.
	 *
	 * @param string $uid The uid the session user reports.
	 * @param bool $isAdmin Whether the group manager considers them privileged.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'agent-a', bool $isAdmin = false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
	}//end signIn()

	/**
	 * Make `getParam()` behave like the real request.
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
	 * All three callback endpoints refuse an anonymous caller with 401 and
	 * reach the callback service for nothing.
	 *
	 * @return void
	 */
	public function testAllThreeCallbackEndpointsRefuseAnAnonymousCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->callbackService->expects($this->never())->method('schedule');
		$this->callbackService->expects($this->never())->method('list');
		$this->callbackService->expects($this->never())->method('cancel');

		$responses = [
			'scheduleCallback' => $this->controller->scheduleCallback(),
			'indexCallbacks' => $this->controller->indexCallbacks(),
			'cancelCallback' => $this->controller->cancelCallback(id: 'cb-1'),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$endpoint . ' must refuse an anonymous caller'
			);
			$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
		}
	}//end testAllThreeCallbackEndpointsRefuseAnAnonymousCallerWith401()

	/**
	 * A scheduled callback is attributed to the SESSION's agent and answers 201
	 * Created. The routing params are stripped from the payload so a caller
	 * cannot smuggle `id` in and overwrite an existing record.
	 *
	 * @return void
	 */
	public function testScheduleCallbackAttributesTheCallbackToTheSessionAgentAndAnswers201(): void {
		$this->signIn(uid: 'agent-a');
		$this->request->method('getParams')->willReturn(
			[
				'id' => 'cb-smuggled',
				'_route' => 'dossiq.kcccontact.scheduleCallback',
				'telefoonnummer' => '0612345678',
			]
		);

		$this->callbackService->expects($this->once())
			->method('schedule')
			->with(['telefoonnummer' => '0612345678'], 'agent-a')
			->willReturn(['id' => 'cb-9', 'status' => 'open']);

		$response = $this->controller->scheduleCallback();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'cb-9', 'status' => 'open'], $response->getData());
	}//end testScheduleCallbackAttributesTheCallbackToTheSessionAgentAndAnswers201()

	/**
	 * A rejected schedule answers 400 carrying the service's reason.
	 *
	 * @return void
	 */
	public function testScheduleCallbackAnswers400WhenTheServiceRejectsThePayload(): void {
		$this->signIn(uid: 'agent-a');
		$this->request->method('getParams')->willReturn([]);
		$this->callbackService->method('schedule')
			->willThrowException(new OCSBadRequestException('telefoonnummer is verplicht'));

		$response = $this->controller->scheduleCallback();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'telefoonnummer is verplicht'], $response->getData());
	}//end testScheduleCallbackAnswers400WhenTheServiceRejectsThePayload()

	/**
	 * An ordinary agent's listing is scoped: `isPrivileged` is FALSE, so the
	 * service narrows the query to that agent's own callbacks.
	 *
	 * @return void
	 */
	public function testIndexCallbacksScopesAnOrdinaryAgentToTheirOwnQueue(): void {
		$this->signIn(uid: 'agent-a', isAdmin: false);
		$this->withRequestParams(['status' => 'open']);

		$this->callbackService->expects($this->once())
			->method('list')
			->with(['status' => 'open'], 'agent-a', false)
			->willReturn([['id' => 'cb-1']]);

		$response = $this->controller->indexCallbacks();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['id' => 'cb-1']]], $response->getData());
	}//end testIndexCallbacksScopesAnOrdinaryAgentToTheirOwnQueue()

	/**
	 * A team-lead / admin listing passes `isPrivileged` TRUE — the cross-agent
	 * view — proving the flag tracks the group manager's answer rather than
	 * being pinned at one value.
	 *
	 * @return void
	 */
	public function testIndexCallbacksGivesAPrivilegedAgentTheCrossAgentView(): void {
		$this->signIn(uid: 'teamlead', isAdmin: true);
		$this->withRequestParams([]);

		$this->callbackService->expects($this->once())
			->method('list')
			->with(['status' => ''], 'teamlead', true)
			->willReturn([]);

		$response = $this->controller->indexCallbacks();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => []], $response->getData());
	}//end testIndexCallbacksGivesAPrivilegedAgentTheCrossAgentView()

	/**
	 * Cancelling passes the route id together with the session agent and the
	 * UNPRIVILEGED flag, so the service can refuse a callback that is not the
	 * caller's own.
	 *
	 * @return void
	 */
	public function testCancelCallbackPassesTheSessionAgentAndTheUnprivilegedFlag(): void {
		$this->signIn(uid: 'agent-a', isAdmin: false);

		$this->callbackService->expects($this->once())
			->method('cancel')
			->with('cb-1', 'agent-a', false)
			->willReturn(['id' => 'cb-1', 'status' => 'cancelled']);

		$response = $this->controller->cancelCallback(id: 'cb-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'cb-1', 'status' => 'cancelled'], $response->getData());
	}//end testCancelCallbackPassesTheSessionAgentAndTheUnprivilegedFlag()

	/**
	 * A callback the agent may not touch comes back as 404, not 400 and not
	 * 403: the ownership refusal must be indistinguishable from "no such
	 * callback", or the route becomes an existence oracle over the KCC queue.
	 *
	 * @return void
	 */
	public function testCancelCallbackAnswers404WhenTheCallbackIsNotTheAgentsOwn(): void {
		$this->signIn(uid: 'agent-a', isAdmin: false);
		$this->callbackService->method('cancel')
			->willThrowException(new OCSBadRequestException('Callback niet gevonden'));

		$response = $this->controller->cancelCallback(id: 'cb-of-another-agent');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Callback niet gevonden'], $response->getData());
	}//end testCancelCallbackAnswers404WhenTheCallbackIsNotTheAgentsOwn()
}//end class
