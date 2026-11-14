<?php

/**
 * RoleGuard edge-case tests.
 *
 * The malformed role lists a stored case can carry, an empty allowed role,
 * and a group lookup that throws: each must leave the guard closed rather than
 * open or crashing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\RoleGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\RoleGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class RoleGuardEdgesTest extends TestCase {

	/**
	 * A guard whose user exists and whose group membership is given.
	 *
	 * @param IGroupManager        $groups The group manager.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return RoleGuard The guard.
	 */
	private function guard(IGroupManager $groups, ?LoggerInterface $logger = null): RoleGuard {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		return new RoleGuard(
			groupManager: $groups,
			userManager: $users,
			logger: $logger ?? new NullLogger(),
			l10n: new SourceL10n(),
		);
	}//end guard()

	/**
	 * A case whose roles field is not a list falls through to the group check.
	 *
	 * @return void
	 */
	public function testANonListRolesFieldFallsThroughToGroups(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())->method('isInGroup')->with('jan', 'behandelaar')->willReturn(true);

		$result = $this->guard($groups)->evaluate(['allowedRoles' => ['Behandelaar']], ['roles' => 'corrupt'], 'jan');

		$this->assertTrue($result->passed);
		$this->assertSame(['matchedRole' => 'Behandelaar', 'via' => 'group'], $result->details);
	}//end testANonListRolesFieldFallsThroughToGroups()

	/**
	 * Scalar entries and other users' entries are skipped; the user's own entry matches.
	 *
	 * @return void
	 */
	public function testMalformedAndForeignEntriesAreSkipped(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('isInGroup');

		$result = $this->guard($groups)->evaluate(
			['allowedRoles' => ['behandelaar']],
			['participants' => ['junk', ['userId' => 'piet', 'role' => 'behandelaar'], ['user' => 'jan', 'roleType' => 'behandelaar']]],
			'jan'
		);

		$this->assertTrue($result->passed);
		$this->assertSame(['matchedRole' => 'behandelaar'], $result->details);
	}//end testMalformedAndForeignEntriesAreSkipped()

	/**
	 * Another user's matching role does not let this user through.
	 *
	 * @return void
	 */
	public function testAnotherUsersRoleDoesNotPass(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(false);

		$result = $this->guard($groups)->evaluate(
			['allowedRoles' => ['behandelaar']],
			['roles' => [['userId' => 'piet', 'role' => 'behandelaar']]],
			'jan'
		);

		$this->assertFalse($result->passed);
		$this->assertSame('Insufficient permissions', $result->failureMessage);
		$this->assertSame(['silent' => true, 'allowedRoles' => ['behandelaar']], $result->details);
	}//end testAnotherUsersRoleDoesNotPass()

	/**
	 * An empty allowed role is never looked up as a group.
	 *
	 * @return void
	 */
	public function testAnEmptyAllowedRoleIsNotAGroup(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())->method('isInGroup')->with('jan', 'admin')->willReturn(true);

		$result = $this->guard($groups)->evaluate(['allowedRoles' => ['', 'Admin']], [], 'jan');

		$this->assertTrue($result->passed);
		$this->assertSame(['matchedRole' => 'Admin', 'via' => 'group'], $result->details);
	}//end testAnEmptyAllowedRoleIsNotAGroup()

	/**
	 * A group lookup that throws is logged and keeps the guard closed.
	 *
	 * @return void
	 */
	public function testAThrowingGroupLookupFailsClosed(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willThrowException(new RuntimeException('ldap down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('RoleGuard: group lookup failed', ['exception' => 'ldap down']);

		$result = $this->guard($groups, $logger)->evaluate(['allowedRoles' => ['behandelaar']], [], 'jan');

		$this->assertFalse($result->passed);
		$this->assertSame('Insufficient permissions', $result->failureMessage);
		$this->assertTrue($result->details['silent']);
	}//end testAThrowingGroupLookupFailsClosed()
}//end class
