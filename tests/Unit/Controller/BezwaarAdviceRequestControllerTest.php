<?php

/**
 * The advisory committee's endpoint for moving an advice request.
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
 *
 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\BezwaarAdviceRequestController;
use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\BezwaarAdviceRequestController
 */
class BezwaarAdviceRequestControllerTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var AdvisoryCommitteeService&MockObject
	 */
	private AdvisoryCommitteeService&MockObject $service;

	/**
	 * Every transition the service was asked for.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $asked = [];

	/**
	 * A fresh service double that records its calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->asked = [];
		$this->service = $this->createMock(originalClassName: AdvisoryCommitteeService::class);
		$this->service->method('transitionAdviceStatus')->willReturnCallback(
			function (string $requestId, string $newStatus, array $payload = []): array {
				$this->asked[] = [$requestId, $newStatus, $payload];
				return ['id' => $requestId, 'status' => $newStatus, '@self' => ['id' => $requestId, 'schema' => 'bacAdviceRequest']];
			}
		);
	}//end setUp()

	/**
	 * Without a session the call is refused and nothing is moved.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallIsRefused(): void {
		$response = $this->controller(uid: null, params: ['status' => 'advice-issued'])->transition(id: 'req-1');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $this->asked);
	}//end testAnAnonymousCallIsRefused()

	/**
	 * A case handler outside the committee is refused before anything is moved.
	 *
	 * @return void
	 */
	public function testACaseHandlerOutsideTheCommitteeIsRefused(): void {
		$response = $this->controller(uid: 'handler-1', params: ['status' => 'advice-issued'])->transition(id: 'req-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $this->asked);
	}//end testACaseHandlerOutsideTheCommitteeIsRefused()

	/**
	 * A forged user id in the body changes nothing: the session's user is the one checked.
	 *
	 * @return void
	 */
	public function testTheSessionUserIsTheOneChecked(): void {
		$forged = ['status' => 'advice-issued', 'userId' => 'chair-1', 'uid' => 'chair-1'];
		$response = $this->controller(uid: 'handler-1', params: $forged)->transition(id: 'req-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $this->asked);
	}//end testTheSessionUserIsTheOneChecked()

	/**
	 * A committee member moves the request; the answer is the updated request with @self, and only advice fields pass.
	 *
	 * @return void
	 */
	public function testACommitteeMemberIssuesTheAdvice(): void {
		$params = ['status' => 'advice-issued', 'conclusion' => 'gegrond', 'recommendation' => 'herroepen', 'panel' => ['x'], 'bezwaar' => 'other'];

		$response = $this->controller(uid: 'chair-1', params: $params)->transition(id: 'req-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: [['req-1', 'advice-issued', ['conclusion' => 'gegrond', 'recommendation' => 'herroepen']]], actual: $this->asked);
		$this->assertSame(expected: 'req-1', actual: $response->getData()['@self']['id']);
	}//end testACommitteeMemberIssuesTheAdvice()

	/**
	 * An administrator outside the committee may move the request.
	 *
	 * @return void
	 */
	public function testAnAdministratorMayMoveTheRequest(): void {
		$response = $this->controller(uid: 'admin-1', params: ['status' => 'inadmissible'])->transition(id: 'req-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: [['req-1', 'inadmissible', []]], actual: $this->asked);
	}//end testAnAdministratorMayMoveTheRequest()

	/**
	 * A call without a status is refused.
	 *
	 * @return void
	 */
	public function testACallWithoutAStatusIsRefused(): void {
		foreach ([[], ['status' => ''], ['status' => ['advice-issued']]] as $params) {
			$response = $this->controller(uid: 'chair-1', params: $params)->transition(id: 'req-1');
			$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus(), message: json_encode($params));
		}

		$this->assertSame(expected: [], actual: $this->asked);
	}//end testACallWithoutAStatusIsRefused()

	/**
	 * A refused move answers 400 with the reason; a missing request answers 404; a signature by someone other than the chair answers 403.
	 *
	 * @return void
	 */
	public function testRefusalsCarryTheirReason(): void {
		$service = $this->createMock(originalClassName: AdvisoryCommitteeService::class);
		$service->method('transitionAdviceStatus')->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new RuntimeException(message: 'Transition from assigned to advice-issued is not permitted')),
			$this->throwException(exception: new RuntimeException(message: 'Advice request not found', code: AdvisoryCommitteeService::NOT_FOUND)),
			$this->throwException(exception: new RuntimeException(message: 'Only the chair of the committee can sign the advice', code: AdvisoryCommitteeService::NOT_CHAIR)),
		);
		$this->service = $service;

		$refused = $this->controller(uid: 'chair-1', params: ['status' => 'advice-issued'])->transition(id: 'req-1');
		$missing = $this->controller(uid: 'chair-1', params: ['status' => 'advice-issued'])->transition(id: 'req-404');
		$notChair = $this->controller(uid: 'member-1', params: ['status' => 'advice-issued'])->transition(id: 'req-1');

		$this->assertSame(
			expected: [Http::STATUS_BAD_REQUEST, 'Transition from assigned to advice-issued is not permitted'],
			actual: [$refused->getStatus(), $refused->getData()['error']]
		);
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $missing->getStatus());
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $notChair->getStatus());
	}//end testRefusalsCarryTheirReason()

	/**
	 * The committee group is the provisioned bezwaarcommissie, not the handlers' group.
	 *
	 * @return void
	 */
	public function testTheCommitteeGroupIsProvisioned(): void {
		$this->assertSame(expected: 'bezwaarcommissie', actual: BezwaarAdviceRequestController::COMMITTEE_GROUP);
		$this->assertContains(needle: BezwaarAdviceRequestController::COMMITTEE_GROUP, haystack: ProvisionAssignedGroups::ASSIGNED_GROUPS);
	}//end testTheCommitteeGroupIsProvisioned()

	/**
	 * The route reaches the action by POST.
	 *
	 * @return void
	 */
	public function testTheRouteReachesTheAction(): void {
		$routes = (require __DIR__.'/../../../appinfo/routes.php')['routes'];
		$found = array_values(array_filter($routes, static fn (array $route): bool => $route['name'] === 'bezwaarAdviceRequest#transition'));

		$expected = ['name' => 'bezwaarAdviceRequest#transition', 'url' => '/api/bezwaar/advice-requests/{id}/transition', 'verb' => 'POST'];
		$this->assertSame(expected: [$expected], actual: $found);
	}//end testTheRouteReachesTheAction()

	/**
	 * The controller for a caller and a request body.
	 *
	 * `chair-1` is in the committee group and `admin-1` is an administrator; the
	 * group manager answers true only for those exact users and groups.
	 *
	 * @param string|null          $uid    The session user, or null for none.
	 * @param array<string, mixed> $params The request body.
	 *
	 * @return BezwaarAdviceRequestController The controller.
	 */
	private function controller(?string $uid, array $params): BezwaarAdviceRequestController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $member, string $group): bool => $member === 'chair-1' && $group === 'bezwaarcommissie'
		);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $member): bool => $member === 'admin-1');

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		return new BezwaarAdviceRequestController(
			appName: 'dossiq',
			request: $request,
			advisoryCommittee: $this->service,
			userSession: $session,
			groupManager: $groups,
		);
	}//end controller()
}//end class
