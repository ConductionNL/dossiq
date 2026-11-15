<?php

/**
 * TransitionAuthorizer Unit Tests
 *
 * Verifies the OR-RBAC group gate on transitions: an open transition admits
 * everyone, an anonymous caller never passes a group gate, either admin group
 * bypasses, a listed group admits its members, and a failing membership
 * lookup is logged and fails closed.
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
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\TransitionAuthorizer
 */
class TransitionAuthorizerTest extends TestCase {

	/**
	 * An empty uid is never an admin, and the group manager is not asked.
	 *
	 * @return void
	 */
	public function testAnEmptyUserIsNeverAnAdmin(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('isInGroup');

		self::assertFalse((new TransitionAuthorizer($groups, new NullLogger()))->isAdmin(userId: ''));
	}//end testAnEmptyUserIsNeverAnAdmin()

	/**
	 * Membership of the dedicated admin group is enough; the instance group is not asked.
	 *
	 * @return void
	 */
	public function testTheDedicatedAdminGroupIsAnAdmin(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())
			->method('isInGroup')
			->with('alice', TransitionAuthorizer::ADMIN_GROUP_ID)
			->willReturn(true);

		self::assertTrue((new TransitionAuthorizer($groups, new NullLogger()))->isAdmin(userId: 'alice'));
	}//end testTheDedicatedAdminGroupIsAnAdmin()

	/**
	 * The instance admin group answers when the dedicated group does not.
	 *
	 * @return void
	 */
	public function testTheInstanceAdminGroupIsAnAdmin(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->exactly(2))
			->method('isInGroup')
			->willReturnMap([
				['bob', 'procest-admin', false],
				['bob', 'admin', true],
			]);

		self::assertTrue((new TransitionAuthorizer($groups, new NullLogger()))->isAdmin(userId: 'bob'));
	}//end testTheInstanceAdminGroupIsAnAdmin()

	/**
	 * A failing admin lookup is logged and fails closed.
	 *
	 * @return void
	 */
	public function testAFailingAdminLookupFailsClosed(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willThrowException(new RuntimeException('ldap down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('StatusTransitionService: admin check failed', ['exception' => 'ldap down']);

		self::assertFalse((new TransitionAuthorizer($groups, $logger))->isAdmin(userId: 'carol'));
	}//end testAFailingAdminLookupFailsClosed()

	/**
	 * A transition with no authorization list, or a non-list, is open to everyone.
	 *
	 * @return void
	 */
	public function testAnOpenTransitionAdmitsEveryone(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('isInGroup');
		$authorizer = new TransitionAuthorizer($groups, new NullLogger());

		self::assertTrue($authorizer->isTransitionGroupAuthorized(transition: [], userId: ''));
		self::assertTrue($authorizer->isTransitionGroupAuthorized(transition: ['authorization' => []], userId: 'x'));
		self::assertTrue($authorizer->isTransitionGroupAuthorized(transition: ['authorization' => 'grp'], userId: 'x'));
	}//end testAnOpenTransitionAdmitsEveryone()

	/**
	 * An anonymous caller never satisfies a group gate.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('isInGroup');

		$authorizer = new TransitionAuthorizer($groups, new NullLogger());

		self::assertFalse($authorizer->isTransitionGroupAuthorized(transition: ['authorization' => ['grp']], userId: ''));
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * An admin bypasses the group list without the listed groups being asked.
	 *
	 * @return void
	 */
	public function testAnAdminBypassesTheList(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())
			->method('isInGroup')
			->with('root', 'procest-admin')
			->willReturn(true);

		$authorizer = new TransitionAuthorizer($groups, new NullLogger());

		self::assertTrue($authorizer->isTransitionGroupAuthorized(transition: ['authorization' => ['juristen']], userId: 'root'));
	}//end testAnAdminBypassesTheList()

	/**
	 * A member of a listed group passes; empty group ids are skipped, a failing
	 * lookup on one group is logged and the next group is still asked.
	 *
	 * @return void
	 */
	public function testAMemberOfALaterGroupPassesPastAFailingLookup(): void {
		$groups = $this->createMock(IGroupManager::class);
		$asked = [];
		$groups->method('isInGroup')->willReturnCallback(
			static function (string $uid, string $gid) use (&$asked): bool {
				$asked[] = $gid;
				if ($gid === 'broken') {
					throw new RuntimeException('lookup failed');
				}

				return $gid === 'juristen';
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'StatusTransitionService: group membership check failed',
				['exception' => 'lookup failed', 'groupId' => 'broken']
			);

		$authorizer = new TransitionAuthorizer($groups, $logger);

		self::assertTrue(
			$authorizer->isTransitionGroupAuthorized(
				transition: ['authorization' => ['', 'broken', 'juristen']],
				userId: 'dave',
			)
		);
		self::assertSame(['procest-admin', 'admin', 'broken', 'juristen'], $asked);
	}//end testAMemberOfALaterGroupPassesPastAFailingLookup()

	/**
	 * A caller in none of the listed groups is refused.
	 *
	 * @return void
	 */
	public function testANonMemberIsRefused(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(false);

		$authorizer = new TransitionAuthorizer($groups, new NullLogger());

		self::assertFalse(
			$authorizer->isTransitionGroupAuthorized(transition: ['authorization' => ['juristen', 'welstand']], userId: 'eve')
		);
	}//end testANonMemberIsRefused()
}//end class
