<?php

/**
 * A member of an allowed group or an admin is allowed, anyone else is not.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\AllowsGroupMembers;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\AllowsGroupMembers
 */
class AllowsGroupMembersTest extends TestCase {

	/**
	 * A controller over the given group answers.
	 *
	 * @param string|null $memberOf The one group the user is in.
	 * @param bool        $admin    Whether the user is an admin.
	 *
	 * @return object
	 */
	private function controller(?string $memberOf, bool $admin): object {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $group === $memberOf);
		$groups->method('isAdmin')->willReturn($admin);

		return new class ($groups) {
			use AllowsGroupMembers;

			private const ALLOWED_GROUPS = ['auditors', 'beheerders'];

			public function __construct(private readonly IGroupManager $groupManager) {
			}

			public function allowed(string $uid): bool {
				return $this->isAllowed(uid: $uid);
			}
		};
	}

	public function testMemberOfAnAllowedGroupIsAllowed(): void {
		$this->assertTrue($this->controller(memberOf: 'beheerders', admin: false)->allowed('alice'));
	}

	public function testAdminIsAllowed(): void {
		$this->assertTrue($this->controller(memberOf: null, admin: true)->allowed('root'));
	}

	public function testOtherUserIsNot(): void {
		$this->assertFalse($this->controller(memberOf: 'staff', admin: false)->allowed('bob'));
	}
}
