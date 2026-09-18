<?php

/**
 * InspectController: whether the reader is offered the raw surfaces.
 *
 * 🔴 WHAT THIS IS AND IS NOT ASSERTING. The endpoint is a VISIBILITY
 * predicate, not a control. Nothing here claims that a false answer keeps
 * anybody out of anything: both Inspect entries open OpenRegister, and
 * OpenRegister refuses a non-admin on its own. What these tests hold is that
 * the predicate answers about the person asking, and that it answers at all
 * for the least privileged caller there is.
 *
 * 🔴 THE ANONYMOUS CASE IS THE ONE THAT MATTERS. `#[NoAdminRequired]` lets a
 * signed-out request through to the method, and `IUserSession::getUser()`
 * answers null for it. An unguarded `$user->getUID()` would fatal there, and
 * the surface would show a 500 in the network log of every page carrying the
 * action rather than hiding one menu entry. So the null is asked for by name.
 *
 * The group manager is doubled with `onlyMethods`, so a test cannot invent
 * `isAdmin` on a class that does not have it.
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
 * @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\InspectController;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Three readers, three answers.
 *
 * @covers \OCA\Dossiq\Controller\InspectController
 */
class InspectControllerTest extends TestCase {

	/**
	 * Build the controller over a session and a group manager.
	 *
	 * @param string|null $uid     The signed-in account, or null for nobody.
	 * @param bool        $isAdmin What the group manager says about them.
	 *
	 * @return InspectController The controller under test.
	 */
	private function controller(?string $uid, bool $isAdmin): InspectController {
		$session = $this->createMock(IUserSession::class);
		$groupManager = $this->getMockBuilder(IGroupManager::class)
			->disableOriginalConstructor()
			->onlyMethods(['isAdmin'])
			->getMockForAbstractClass();

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			// 🔴 NOBODY IS ASKED ABOUT. A group lookup on a signed-out request
			// would be a lookup on an empty uid, which some backends answer
			// truthfully and some answer for the first account they find.
			$groupManager->expects($this->never())->method('isAdmin');
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
			$groupManager->expects($this->once())
				->method('isAdmin')
				->with($uid)
				->willReturn($isAdmin);
		}

		return new InspectController(
			'dossiq',
			$this->createMock(IRequest::class),
			$session,
			$groupManager
		);
	}//end controller()

	/**
	 * An administrator is offered the entry.
	 *
	 * @return void
	 */
	public function testAnAdministratorIsAvailable(): void {
		$response = $this->controller('admin', true)->availability();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['isAdmin' => true], $response->getData());
	}//end testAnAdministratorIsAvailable()

	/**
	 * A handler is not, and is told so rather than refused.
	 *
	 * A 403 here would hide the entry too, by the fail-safe rule, so the
	 * status is asserted alongside the payload: the two readings are not the
	 * same to anybody reading a network log.
	 *
	 * @return void
	 */
	public function testAHandlerIsNotAvailable(): void {
		$response = $this->controller('behandelaar', false)->availability();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['isAdmin' => false], $response->getData());
	}//end testAHandlerIsNotAvailable()

	/**
	 * A signed-out reader answers false, and never fatals.
	 *
	 * @return void
	 */
	public function testAnAnonymousReaderIsNotAvailable(): void {
		$response = $this->controller(null, false)->availability();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['isAdmin' => false], $response->getData());
	}//end testAnAnonymousReaderIsNotAvailable()

	/**
	 * The route is reachable by anybody signed in, or it answers for nobody.
	 *
	 * The predicate is fetched by every reader of a case page, admin or not,
	 * and an `#[AdminRequired]` here would make it a 403 for exactly the
	 * readers whose answer is "no". Fail-safe would still hide the entry, so
	 * nothing on screen would look wrong while every handler's case page
	 * carried a 403.
	 *
	 * @return void
	 */
	public function testTheRouteIsOpenToAnySignedInReader(): void {
		$method = new \ReflectionMethod(InspectController::class, 'availability');
		$names = array_map(
			static fn ($attribute) => $attribute->getName(),
			$method->getAttributes()
		);

		$this->assertContains(
			\OCP\AppFramework\Http\Attribute\NoAdminRequired::class,
			$names,
			'availability() must carry #[NoAdminRequired], or it answers 403 for every non-admin'
		);
	}//end testTheRouteIsOpenToAnySignedInReader()
}//end class
