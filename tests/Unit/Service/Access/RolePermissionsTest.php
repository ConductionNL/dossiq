<?php

/**
 * Dossiq Role Permissions Test
 *
 * The role permissions follow the user's groups: a privacy officer holds
 * `privacy`, social-domain staff hold `sociaal-domein`, admins and beheerders
 * hold both, and everybody else holds none.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
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
 * @spec openspec/specs/admin-data-access/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\Access\RolePermissions;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Role permissions from group membership.
 */
final class RolePermissionsTest extends TestCase {

	/**
	 * Group memberships and the permissions they give.
	 *
	 * @return array<string, array{0: bool, 1: array<int, string>, 2: array<int, string>}>
	 */
	public static function memberships(): array {
		return [
			'privacy officer' => [false, ['dossiq-privacy'], ['privacy']],
			'social-domain staff' => [false, ['dossiq-sociaal-domein'], ['sociaal-domein']],
			'both' => [false, ['dossiq-sociaal-domein', 'dossiq-privacy'], ['privacy', 'sociaal-domein']],
			'beheerder' => [false, ['beheerders'], ['privacy', 'sociaal-domein']],
			'admin' => [true, [], ['privacy', 'sociaal-domein']],
			'ordinary staff' => [false, ['dossiq-medewerkers'], []],
		];
	}//end memberships()

	/**
	 * The permissions follow the groups.
	 *
	 * @param boolean            $isAdmin  Whether the user is a Nextcloud admin.
	 * @param array<int, string> $groups   The user's groups.
	 * @param array<int, string> $expected The permissions.
	 *
	 * @return void
	 */
	#[DataProvider('memberships')]
	public function testThePermissionsFollowTheGroups(bool $isAdmin, array $groups, array $expected): void {
		$this->assertSame($expected, $this->roles(isAdmin: $isAdmin, groups: $groups)->forCurrentUser());
	}//end testThePermissionsFollowTheGroups()

	/**
	 * Nobody signed in holds nothing.
	 *
	 * @return void
	 */
	public function testNobodySignedInHoldsNothing(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$this->assertSame([], (new RolePermissions($session, $this->createMock(IGroupManager::class)))->forCurrentUser());
	}//end testNobodySignedInHoldsNothing()

	/**
	 * Both role groups are provisioned, so membership can be granted.
	 *
	 * @return void
	 */
	public function testTheRoleGroupsAreProvisioned(): void {
		foreach (array_keys(RolePermissions::ROLE_PERMISSIONS) as $group) {
			$this->assertContains($group, ProvisionAssignedGroups::ASSIGNED_GROUPS);
		}
	}//end testTheRoleGroupsAreProvisioned()

	/**
	 * Build the service over a user with the given groups.
	 *
	 * @param boolean            $isAdmin Whether the user is a Nextcloud admin.
	 * @param array<int, string> $groups  The user's groups.
	 *
	 * @return RolePermissions The service.
	 */
	private function roles(bool $isAdmin, array $groups): RolePermissions {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ilse');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, $groups, true)
		);

		return new RolePermissions($session, $groupManager);
	}//end roles()
}//end class
