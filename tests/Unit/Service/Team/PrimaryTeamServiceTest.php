<?php

/**
 * PrimaryTeamService: a user's team is the deepest organisation role that binds one of their groups.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Team
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Team;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Team\PrimaryTeamService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Zuiddrecht tree: the municipality, a domain under it, a team under that.
 *
 * @covers \OCA\Dossiq\Service\Team\PrimaryTeamService
 */
class PrimaryTeamServiceTest extends TestCase {

	/**
	 * The organisation roles.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function roles(): array {
		return [
			['id' => 'gemeente', 'roleName' => 'Gemeente Zuiddrecht', 'ncGroupId' => 'zuiddrecht-medewerkers'],
			['id' => 'bedrijfsvoering', 'roleName' => 'Bedrijfsvoering', 'ncGroupId' => 'zuiddrecht-bedrijfsvoering', 'parentRoleId' => 'gemeente'],
			['id' => 'woo', 'roleName' => 'Informatie en Woo', 'ncGroupId' => 'zuiddrecht-woo-en-informatie', 'parentRoleId' => 'bedrijfsvoering'],
			['id' => 'fysiek', 'roleName' => 'Fysiek domein', 'ncGroupId' => 'zuiddrecht-fysiek-domein', 'parentRoleId' => 'gemeente'],
			['id' => 'vergunningen', 'roleName' => 'Vergunningen', 'ncGroupId' => 'zuiddrecht-vergunningen', 'parentRoleId' => 'fysiek'],
			['id' => 'unbound', 'roleName' => 'Unbound', 'parentRoleId' => 'gemeente'],
		];
	}//end roles()

	/**
	 * The team is the deepest bound role, not the municipality above it.
	 *
	 * @return void
	 */
	public function testTheTeamIsTheDeepestRoleThatBindsAGroupOfTheUser(): void {
		$team = PrimaryTeamService::primaryTeam(
			roles: self::roles(),
			userGroupIds: ['zuiddrecht-medewerkers', 'zuiddrecht-woo-en-informatie', 'admin'],
		);

		$this->assertSame(['groupId' => 'zuiddrecht-woo-en-informatie', 'name' => 'Informatie en Woo'], $team);
	}//end testTheTeamIsTheDeepestRoleThatBindsAGroupOfTheUser()

	/**
	 * Two equally deep teams: the first by role name, the same every time.
	 *
	 * @return void
	 */
	public function testTwoEquallyDeepTeamsAreOrderedByName(): void {
		$groups = ['zuiddrecht-vergunningen', 'zuiddrecht-woo-en-informatie'];

		$this->assertSame('zuiddrecht-woo-en-informatie', PrimaryTeamService::primaryTeam(roles: self::roles(), userGroupIds: $groups)['groupId']);
		$this->assertSame('zuiddrecht-woo-en-informatie', PrimaryTeamService::primaryTeam(roles: array_reverse(self::roles()), userGroupIds: array_reverse($groups))['groupId']);
	}//end testTwoEquallyDeepTeamsAreOrderedByName()

	/**
	 * A user whose groups no role binds has no team, whatever groups they are in.
	 *
	 * @return void
	 */
	public function testAUserInNoBoundGroupHasNoTeam(): void {
		$this->assertNull(PrimaryTeamService::primaryTeam(roles: self::roles(), userGroupIds: ['admin', 'Unbound']));
		$this->assertNull(PrimaryTeamService::primaryTeam(roles: [], userGroupIds: ['zuiddrecht-medewerkers']));
	}//end testAUserInNoBoundGroupHasNoTeam()

	/**
	 * A parent cycle does not loop.
	 *
	 * @return void
	 */
	public function testAParentCycleEnds(): void {
		$roles = [
			['id' => 'a', 'roleName' => 'A', 'ncGroupId' => 'ga', 'parentRoleId' => 'b'],
			['id' => 'b', 'roleName' => 'B', 'ncGroupId' => 'gb', 'parentRoleId' => 'a'],
		];

		$this->assertNotNull(PrimaryTeamService::primaryTeam(roles: $roles, userGroupIds: ['ga']));
	}//end testAParentCycleEnds()

	/**
	 * A user in no group is not looked up at all.
	 *
	 * @return void
	 */
	public function testAUserInNoGroupHasNoTeamWithoutAReadOfTheRoles(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);

		$service = new PrimaryTeamService(settingsService: $settings, groupManager: $groups, logger: new NullLogger());

		$this->assertNull($service->teamOf(user: $this->createMock(IUser::class)));
	}//end testAUserInNoGroupHasNoTeamWithoutAReadOfTheRoles()
}//end class
