<?php

/**
 * BelplanController Wire-Contract Tests
 *
 * Contract coverage for `POST /api/kcc/belplannen/route` (gate-25). The
 * endpoint resolves which specialist a dialed number plus keuzemenu selection
 * routes to, and it is the only belplan endpoint a KCC-medewerker calls on
 * every inbound call. These tests pin:
 *
 *  - an unauthenticated caller is refused 401 BEFORE the routing service runs,
 *    so the belplan (which maps phone numbers to named staff) is not readable
 *    without a session;
 *  - both `phoneNumber` and `menuSelection` reach the service — dropping the
 *    menu selection still routes the call, to the wrong desk;
 *  - a domain RuntimeException becomes a 400 carrying the domain message, not
 *    a 500 — the caller has to be able to tell "unknown number" from "server
 *    broken".
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

use OCA\Procest\Controller\BelplanController;
use OCA\Procest\Service\BelplanRoutingService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for BelplanController::route().
 *
 * @covers \OCA\Procest\Controller\BelplanController
 */
class BelplanControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The belplan routing service.
	 *
	 * @var BelplanRoutingService|MockObject
	 */
	private BelplanRoutingService $routingService;

	/**
	 * The settings service (unused by route(), required by the constructor).
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The controller under test.
	 *
	 * @var BelplanController
	 */
	private BelplanController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->routingService = $this->createMock(BelplanRoutingService::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new BelplanController(
			appName: 'procest',
			request: $this->request,
			routingService: $this->routingService,
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
		);
	}//end setUp()

	/**
	 * Put a signed-in, non-admin user on the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kcc-agent');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}//end signIn()

	/**
	 * Drive the two request parameters route() reads.
	 *
	 * @param string $phoneNumber The dialed number.
	 * @param string $menuSelection The keuzemenu selection.
	 *
	 * @return void
	 */
	private function withCall(string $phoneNumber, string $menuSelection): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($phoneNumber, $menuSelection): mixed {
				return match ($key) {
					'phoneNumber' => $phoneNumber,
					'menuSelection' => $menuSelection,
					default => $default,
				};
			}
		);
	}//end withCall()

	/**
	 * An unauthenticated caller is refused before the belplan is consulted.
	 *
	 * @return void
	 */
	public function testRouteRefusesAnUnauthenticatedCallerBeforeResolvingTheBelplan(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->routingService->expects($this->never())->method('routeCall');

		$response = $this->controller->route();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testRouteRefusesAnUnauthenticatedCallerBeforeResolvingTheBelplan()

	/**
	 * Both call attributes reach the routing service and the result is
	 * returned unwrapped.
	 *
	 * @return void
	 */
	public function testRouteForwardsBothThePhoneNumberAndTheMenuSelection(): void {
		$this->signIn();
		$this->withCall(phoneNumber: '+31201234567', menuSelection: '3');

		$resolved = ['specialist' => 'burgerzaken', 'queue' => 'BZ-1'];

		$this->routingService->expects($this->once())
			->method('routeCall')
			->with('+31201234567', '3')
			->willReturn($resolved);

		$response = $this->controller->route();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($resolved, $response->getData());
	}//end testRouteForwardsBothThePhoneNumberAndTheMenuSelection()

	/**
	 * A domain RuntimeException is a 400 with the domain message, not a 500.
	 *
	 * @return void
	 */
	public function testRouteMapsADomainRuntimeExceptionToA400CarryingItsMessage(): void {
		$this->signIn();
		$this->withCall(phoneNumber: '+31200000000', menuSelection: '9');

		$this->routingService->method('routeCall')
			->willThrowException(new \RuntimeException('Geen belplan voor dit nummer'));

		$response = $this->controller->route();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Geen belplan voor dit nummer'], $response->getData());
	}//end testRouteMapsADomainRuntimeExceptionToA400CarryingItsMessage()
}//end class
