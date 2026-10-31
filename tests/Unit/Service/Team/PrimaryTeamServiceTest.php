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
 * @spec openspec/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
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

		$this->assertSame(expected: ['groupId' => 'zuiddrecht-woo-en-informatie', 'name' => 'Informatie en Woo'], actual: $team);
	}//end testTheTeamIsTheDeepestRoleThatBindsAGroupOfTheUser()

	/**
	 * Two equally deep teams: the first by role name, the same every time.
	 *
	 * @return void
	 */
	public function testTwoEquallyDeepTeamsAreOrderedByName(): void {
		$groups = ['zuiddrecht-vergunningen', 'zuiddrecht-woo-en-informatie'];

		$inOrder = PrimaryTeamService::primaryTeam(roles: self::roles(), userGroupIds: $groups);
		$this->assertSame(expected: 'zuiddrecht-woo-en-informatie', actual: $inOrder['groupId']);
		$reversed = PrimaryTeamService::primaryTeam(roles: array_reverse(self::roles()), userGroupIds: array_reverse($groups));
		$this->assertSame(expected: 'zuiddrecht-woo-en-informatie', actual: $reversed['groupId']);
	}//end testTwoEquallyDeepTeamsAreOrderedByName()

	/**
	 * A user whose groups no role binds has no team, whatever groups they are in.
	 *
	 * @return void
	 */
	public function testAUserInNoBoundGroupHasNoTeam(): void {
		$this->assertNull(actual: PrimaryTeamService::primaryTeam(roles: self::roles(), userGroupIds: ['admin', 'Unbound']));
		$this->assertNull(actual: PrimaryTeamService::primaryTeam(roles: [], userGroupIds: ['zuiddrecht-medewerkers']));
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

		$this->assertNotNull(actual: PrimaryTeamService::primaryTeam(roles: $roles, userGroupIds: ['ga']));
	}//end testAParentCycleEnds()

	/**
	 * A user in no group is not looked up at all.
	 *
	 * @return void
	 */
	public function testAUserInNoGroupHasNoTeamWithoutAReadOfTheRoles(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);

		$service = new PrimaryTeamService(settingsService: $settings, groupManager: $groups, logger: new NullLogger());

		$this->assertNull(actual: $service->teamOf(user: $this->createMock(originalClassName: IUser::class)));
	}//end testAUserInNoGroupHasNoTeamWithoutAReadOfTheRoles()
	/**
	 * A user in two teams belongs to both; the municipality above them and the
	 * admin group are no team; the primary team comes first.
	 *
	 * @return void
	 */
	public function testTheTeamsAreEveryBoundLeafRoleOfTheUserPrimaryFirst(): void {
		$teams = PrimaryTeamService::teams(
			roles: self::roles(),
			userGroupIds: ['admin', 'zuiddrecht-medewerkers', 'zuiddrecht-woo-en-informatie', 'zuiddrecht-vergunningen']
		);

		$this->assertSame(
			expected: [
				['groupId' => 'zuiddrecht-woo-en-informatie', 'name' => 'Informatie en Woo'],
				['groupId' => 'zuiddrecht-vergunningen', 'name' => 'Vergunningen'],
			],
			actual: $teams
		);
		$this->assertSame(
			expected: $teams[0],
			actual: PrimaryTeamService::primaryTeam(roles: self::roles(), userGroupIds: ['zuiddrecht-medewerkers', 'zuiddrecht-woo-en-informatie', 'zuiddrecht-vergunningen'])
		);
	}//end testTheTeamsAreEveryBoundLeafRoleOfTheUserPrimaryFirst()

	/**
	 * A user only in the municipality's group has that one role as team; the
	 * admin group alone is none.
	 *
	 * @return void
	 */
	public function testAnAncestorIsATeamOnlyWithoutADeeperOne(): void {
		$this->assertSame(
			expected: [['groupId' => 'zuiddrecht-medewerkers', 'name' => 'Gemeente Zuiddrecht']],
			actual: PrimaryTeamService::teams(roles: self::roles(), userGroupIds: ['zuiddrecht-medewerkers'])
		);
		$roles = [...self::roles(), ['id' => 'beheer', 'roleName' => 'Beheer', 'ncGroupId' => 'admin']];
		$this->assertSame(expected: [], actual: PrimaryTeamService::teams(roles: $roles, userGroupIds: ['admin']));
	}//end testAnAncestorIsATeamOnlyWithoutADeeperOne()
}//end class
