<?php

/**
 * ReadsGroupMembership — in one of the groups, or an administrator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ReadsGroupMembership;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Membership, administration, no uid and a failing check.
 *
 * @covers \OCA\Dossiq\Service\Support\ReadsGroupMembership
 */
final class ReadsGroupMembershipTest extends TestCase {

	/**
	 * A class using the trait over the given group manager.
	 *
	 * @param IGroupManager $groupManager The group manager.
	 *
	 * @return object
	 */
	private function subject(IGroupManager $groupManager): object {
		return new class($groupManager) {
			use ReadsGroupMembership {
				inGroupsOrAdmin as public;
			}

			/**
			 * Constructor.
			 *
			 * @param IGroupManager $groupManager The group manager.
			 */
			public function __construct(private readonly IGroupManager $groupManager) {
			}
		};
	}//end subject()

	/**
	 * A member of a listed group is in, an admin is in, anyone else is out.
	 *
	 * @return void
	 */
	public function testMembershipAndAdministration(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'kcc' && $group === 'kcc');
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');

		$subject = $this->subject($groups);
		self::assertTrue($subject->inGroupsOrAdmin('kcc', ['other', 'kcc']));
		self::assertTrue($subject->inGroupsOrAdmin('root', ['kcc']));
		self::assertFalse($subject->inGroupsOrAdmin('jan', ['kcc']));
	}//end testMembershipAndAdministration()

	/**
	 * No uid asks nothing; a failing check is not an authorization.
	 *
	 * @return void
	 */
	public function testNoUidAndAFailingCheck(): void {
		$silent = $this->createMock(IGroupManager::class);
		$silent->expects(self::never())->method('isInGroup');
		self::assertFalse($this->subject($silent)->inGroupsOrAdmin('', ['kcc']));

		$failing = $this->createMock(IGroupManager::class);
		$failing->method('isInGroup')->willThrowException(new RuntimeException('ldap down'));
		self::assertFalse($this->subject($failing)->inGroupsOrAdmin('jan', ['kcc']));
	}//end testNoUidAndAFailingCheck()
}//end class
