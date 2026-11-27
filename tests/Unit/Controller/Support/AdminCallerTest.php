<?php

/**
 * AdminCaller Unit Tests
 *
 * The caller's admin standing: false without a session (the group manager is
 * not asked), and otherwise what the group manager answers for the caller's uid.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\AdminCaller;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminCaller.
 *
 * @covers \OCA\Dossiq\Controller\Support\AdminCaller
 */
final class AdminCallerTest extends TestCase {

	/**
	 * A session holding the given user, or nobody.
	 *
	 * @param string|null $uid The signed-in uid, or null for no session.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * Without a session the caller is no admin, and the group manager is not asked.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsNoAdmin(): void {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->expects($this->never())->method('isAdmin');

		$caller = new AdminCaller(userSession: $this->session(uid: null), groupManager: $groups);

		$this->assertFalse(condition: $caller->isAdmin());
	}//end testNobodySignedInIsNoAdmin()

	/**
	 * A signed-in administrator is asked about by uid and answered true.
	 *
	 * @return void
	 */
	public function testASignedInAdministratorIsAnAdmin(): void {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->expects($this->once())->method('isAdmin')->with('beheerder')->willReturn(true);

		$caller = new AdminCaller(userSession: $this->session(uid: 'beheerder'), groupManager: $groups);

		$this->assertTrue(condition: $caller->isAdmin());
	}//end testASignedInAdministratorIsAnAdmin()

	/**
	 * A signed-in user the group manager does not know as an admin is none.
	 *
	 * @return void
	 */
	public function testASignedInUserWhoIsNoAdministratorIsNoAdmin(): void {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->with('behandelaar')->willReturn(false);

		$caller = new AdminCaller(userSession: $this->session(uid: 'behandelaar'), groupManager: $groups);

		$this->assertFalse(condition: $caller->isAdmin());
	}//end testASignedInUserWhoIsNoAdministratorIsNoAdmin()
}//end class
