<?php

/**
 * KccContactController::related() Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the one KCC endpoint left without executed
 * proof of its wire behaviour: `related()`, which lists the OTHER contact
 * moments recorded for the same customer as a given contact moment. It is
 * `@NoAdminRequired`, so any authenticated user reaches it, and the IDOR
 * protection lives entirely in the two arguments the controller computes and
 * hands the service. Those arguments are what these tests pin:
 *
 *  - an anonymous caller is refused 401 and the service is never entered;
 *  - the `agentId` passed is the SESSION user's uid — the scoping key the
 *    service uses to decide which contact moments the caller may see. A
 *    hard-coded or request-supplied agentId here would hand any caller anybody
 *    else's customer history;
 *  - `isPrivileged` is derived from the group manager for THAT uid, and is
 *    false for an ordinary agent and true for an admin/team-lead. `related()`
 *    fans out from ONE record to a whole customer history, so a privileged flag
 *    that defaulted to true would widen the blast radius further than any other
 *    endpoint on this controller;
 *  - a rejected id is answered 404, not 400 — the read endpoints on this
 *    controller map the service's rejection to "not found" precisely so a
 *    caller cannot tell "this id is not yours" apart from "this id does not
 *    exist"; a 400 here would restore that oracle.
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

use OCA\Procest\Controller\KccContactController;
use OCA\Procest\Service\Kcc\CallbackService;
use OCA\Procest\Service\Kcc\ContactMomentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for KccContactController::related().
 *
 * @covers \OCA\Procest\Controller\KccContactController
 */
class KccContactRelatedContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The contact-moment service mock.
	 *
	 * @var ContactMomentService|MockObject
	 */
	private ContactMomentService $contactMomentService;

	/**
	 * The callback service mock (unused by `related()`, required to construct).
	 *
	 * @var CallbackService|MockObject
	 */
	private CallbackService $callbackService;

	/**
	 * The user session mock — source of the scoping `agentId`.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager mock — source of the `isPrivileged` flag.
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
	 * Put an authenticated agent in the session.
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
	 * Record the arguments `related()` hands the service and answer with a list.
	 *
	 * @param array<int, array<string, mixed>> $results The related moments to return.
	 * @param array<string, mixed>|null $seen Captured call arguments (by reference).
	 *
	 * @return void
	 */
	private function captureRelatedCall(array $results, ?array &$seen): void {
		$this->contactMomentService->expects($this->once())
			->method('related')
			->willReturnCallback(
				static function (
					string $id,
					string $agentId,
					bool $isPrivileged = false,
				) use (&$seen, $results): array {
					$seen = ['id' => $id, 'agentId' => $agentId, 'isPrivileged' => $isPrivileged];
					return $results;
				}
			);
	}//end captureRelatedCall()

	/**
	 * An anonymous caller is refused 401 and the customer history is never read.
	 *
	 * @return void
	 */
	public function testRelatedRefusesAnAnonymousCallerWithoutReadingAnyHistory(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->contactMomentService->expects($this->never())->method('related');

		$response = $this->controller->related(id: 'moment-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
	}//end testRelatedRefusesAnAnonymousCallerWithoutReadingAnyHistory()

	/**
	 * An ordinary agent's request is scoped to their own uid with the privileged
	 * flag OFF, and the related moments come back under `results`.
	 *
	 * @return void
	 */
	public function testRelatedScopesAnOrdinaryAgentToTheirOwnUidWithoutPrivilege(): void {
		$this->signIn(uid: 'agent-1');
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('agent-1')
			->willReturn(false);

		$seen = null;
		$this->captureRelatedCall(results: [['id' => 'moment-2']], seen: $seen);

		$response = $this->controller->related(id: 'moment-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['id' => 'moment-2']]], $response->getData());
		$this->assertSame(
			['id' => 'moment-1', 'agentId' => 'agent-1', 'isPrivileged' => false],
			$seen
		);
	}//end testRelatedScopesAnOrdinaryAgentToTheirOwnUidWithoutPrivilege()

	/**
	 * A team-lead / admin gets the cross-agent view — the privileged flag is
	 * derived from the group manager, not from anything the caller sent.
	 *
	 * @return void
	 */
	public function testRelatedGivesAPrivilegedAgentTheCrossAgentView(): void {
		$this->signIn(uid: 'teamlead-1');
		$this->groupManager->method('isAdmin')->with('teamlead-1')->willReturn(true);

		$seen = null;
		$this->captureRelatedCall(results: [], seen: $seen);

		$response = $this->controller->related(id: 'moment-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['id' => 'moment-1', 'agentId' => 'teamlead-1', 'isPrivileged' => true],
			$seen
		);
	}//end testRelatedGivesAPrivilegedAgentTheCrossAgentView()

	/**
	 * A moment the agent may not see is answered 404, NOT 400 — so "not yours"
	 * and "does not exist" are indistinguishable to the caller.
	 *
	 * @return void
	 */
	public function testRelatedAnswers404WhenTheMomentIsNotTheAgentsOwn(): void {
		$this->signIn(uid: 'agent-1');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->contactMomentService->method('related')
			->willThrowException(new OCSBadRequestException('Contactmoment niet gevonden'));

		$response = $this->controller->related(id: 'someone-elses-moment');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Contactmoment niet gevonden'], $response->getData());
	}//end testRelatedAnswers404WhenTheMomentIsNotTheAgentsOwn()
}//end class
