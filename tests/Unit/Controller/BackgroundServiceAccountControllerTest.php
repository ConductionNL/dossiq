<?php

/**
 * BackgroundServiceAccountController Unit Tests.
 *
 * Pins the admin-only guard on both actions, the group added to every body, and the 400 for a refused assignment.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\BackgroundServiceAccountController;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BackgroundServiceAccountController.
 *
 * @covers \OCA\Dossiq\Controller\BackgroundServiceAccountController
 */
class BackgroundServiceAccountControllerTest extends TestCase {

	/**
	 * The service account double.
	 *
	 * @var BackgroundServiceAccount&MockObject
	 */
	private BackgroundServiceAccount&MockObject $account;

	/**
	 * Set up the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->account = $this->createMock(BackgroundServiceAccount::class);
	}//end setUp()

	/**
	 * Build the controller for the given caller.
	 *
	 * @param string|null $uid     The signed-in UID, or null.
	 * @param bool        $isAdmin Whether that user is an admin.
	 * @param string      $userId  The userId request parameter.
	 *
	 * @return BackgroundServiceAccountController
	 */
	private function controller(?string $uid, bool $isAdmin, string $userId = ''): BackgroundServiceAccountController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('userId', '')->willReturn($userId);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => ($id === $uid && $isAdmin === true));

		return new BackgroundServiceAccountController($request, $this->account, $session, $groups);
	}//end controller()

	/**
	 * Anonymous callers and non-admins get 403 on both actions; nothing is read or assigned.
	 *
	 * @return void
	 */
	public function testBothActionsAreAdminOnly(): void {
		$this->account->expects($this->never())->method('status');
		$this->account->expects($this->never())->method('assign');

		foreach ([[null, false], ['bob', false]] as [$uid, $admin]) {
			$controller = $this->controller($uid, $admin, 'svc');
			foreach ([$controller->show(), $controller->save()] as $response) {
				$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
				$this->assertSame(['errorCode' => 'forbidden'], $response->getData());
			}
		}
	}//end testBothActionsAreAdminOnly()

	/**
	 * An admin sees the status with the service group added.
	 *
	 * @return void
	 */
	public function testShowAddsTheGroupToTheStatus(): void {
		$this->account->method('status')->willReturn(['userId' => 'svc', 'group' => 'stale']);

		$response = $this->controller('admin', true)->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['userId' => 'svc', 'group' => 'dossiq-background-service'], $response->getData());
	}//end testShowAddsTheGroupToTheStatus()

	/**
	 * An admin assigns the requested user and gets the new status with the group.
	 *
	 * @return void
	 */
	public function testSaveAssignsTheRequestedUser(): void {
		$this->account->expects($this->once())->method('assign')->with('svc')->willReturn(['userId' => 'svc']);

		$response = $this->controller('admin', true, 'svc')->save();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['userId' => 'svc', 'group' => 'dossiq-background-service'], $response->getData());
	}//end testSaveAssignsTheRequestedUser()

	/**
	 * A refused assignment is a 400 with the service's message.
	 *
	 * @return void
	 */
	public function testARefusedAssignmentIs400(): void {
		$this->account->method('assign')->willThrowException(new InvalidArgumentException('Unknown user'));

		$response = $this->controller('admin', true, 'ghost')->save();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['errorCode' => 'badRequest', 'message' => 'Unknown user'], $response->getData());
	}//end testARefusedAssignmentIs400()
}//end class
