<?php

/**
 * KccRoutingController tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\KccRoutingController;
use OCA\Dossiq\Service\Kcc\RoutingRuleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\KccRoutingController
 */
class KccRoutingControllerTest extends TestCase {

	/** @var RoutingRuleService&MockObject */
	private RoutingRuleService $service;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/** @var IRequest&MockObject */
	private IRequest $request;

	protected function setUp(): void {
		$this->service = $this->createMock(RoutingRuleService::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
	}

	/**
	 * Build the controller for a signed-in user, or for nobody.
	 *
	 * @param string|null $uid The signed-in user id, null for none.
	 *
	 * @return KccRoutingController
	 */
	private function controller(?string $uid): KccRoutingController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new KccRoutingController($this->request, $this->service, $session, $this->groups);
	}

	public function testIndexRefusesAnonymousCallerWithoutTouchingTheService(): void {
		$this->service->expects($this->never())->method('listRules');

		$response = $this->controller(null)->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
	}

	public function testIndexReturnsTheRulesForAnySignedInUser(): void {
		$this->service->expects($this->once())->method('listRules')->willReturn([['id' => 'r1']]);
		$this->groups->expects($this->never())->method('isAdmin');

		$response = $this->controller('agent')->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['id' => 'r1']]], $response->getData());
	}

	public function testIndexTurnsAServiceRefusalIntoBadRequest(): void {
		$this->service->method('listRules')->willThrowException(new OCSBadRequestException('no register'));

		$response = $this->controller('agent')->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'no register'], $response->getData());
	}

	public function testCreateRefusesNonAdminWithForbidden(): void {
		$this->groups->method('isAdmin')->with('agent')->willReturn(false);
		$this->service->expects($this->never())->method('createRule');

		$response = $this->controller('agent')->create();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Admin-rechten vereist'], $response->getData());
	}

	public function testCreateRefusesAnonymousCallerWithUnauthorized(): void {
		$this->groups->expects($this->never())->method('isAdmin');
		$this->service->expects($this->never())->method('createRule');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->create()->getStatus());
	}

	public function testCreatePassesTheBodyWithoutRoutingParamsAndReturnsCreated(): void {
		$this->groups->method('isAdmin')->with('admin')->willReturn(true);
		$this->request->method('getParams')->willReturn(
			['id' => 'x', 'uuid' => 'u', '@self' => [], '_route' => 'r', 'name' => 'Rule', 'priority' => 3]
		);
		$this->service->expects($this->once())->method('createRule')
			->with(['name' => 'Rule', 'priority' => 3])
			->willReturn(['id' => 'new', 'name' => 'Rule']);

		$response = $this->controller('admin')->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'new', 'name' => 'Rule'], $response->getData());
	}

	public function testCreateTurnsAValidationErrorIntoBadRequest(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('createRule')->willThrowException(new OCSBadRequestException('name required'));

		$response = $this->controller('admin')->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'name required'], $response->getData());
	}

	public function testUpdateRefusesNonAdmin(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$this->service->expects($this->never())->method('updateRule');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('agent')->update('r1')->getStatus());
	}

	public function testUpdatePassesIdAndBodyAndReturnsTheRule(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->request->method('getParams')->willReturn(['id' => 'r1', 'name' => 'Renamed']);
		$this->service->expects($this->once())->method('updateRule')
			->with('r1', ['name' => 'Renamed'])
			->willReturn(['id' => 'r1', 'name' => 'Renamed']);

		$response = $this->controller('admin')->update('r1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'r1', 'name' => 'Renamed'], $response->getData());
	}

	public function testUpdateTurnsAServiceRefusalIntoBadRequest(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('updateRule')->willThrowException(new OCSBadRequestException('unknown rule'));

		$response = $this->controller('admin')->update('r9');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unknown rule'], $response->getData());
	}

	public function testDestroyRefusesNonAdmin(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$this->service->expects($this->never())->method('deleteRule');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('agent')->destroy('r1')->getStatus());
	}

	public function testDestroyDeletesAndReportsSuccess(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->service->expects($this->once())->method('deleteRule')->with('r1');

		$response = $this->controller('admin')->destroy('r1');

		$this->assertSame(['success' => true], $response->getData());
	}

	public function testDestroyTurnsAServiceRefusalIntoBadRequest(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->service->method('deleteRule')->willThrowException(new OCSBadRequestException('gone'));

		$response = $this->controller('admin')->destroy('r1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'gone'], $response->getData());
	}

	public function testEvaluateRefusesAnonymousCaller(): void {
		$this->service->expects($this->never())->method('route');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->evaluate()->getStatus());
	}

	public function testEvaluateRoutesTheContactMomentForAnAgent(): void {
		$this->request->method('getParams')->willReturn(['_route' => 'x', 'channel' => 'phone']);
		$this->groups->expects($this->never())->method('isAdmin');
		$this->service->expects($this->once())->method('route')
			->with(['channel' => 'phone'])
			->willReturn(['agents' => ['a1']]);

		$response = $this->controller('agent')->evaluate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['agents' => ['a1']], $response->getData());
	}

	public function testEvaluateTurnsAServiceRefusalIntoBadRequest(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('route')->willThrowException(new OCSBadRequestException('no channel'));

		$response = $this->controller('agent')->evaluate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'no channel'], $response->getData());
	}
}
