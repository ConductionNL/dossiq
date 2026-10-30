<?php

/**
 * The staff group is seeded once with today's handlers and assignees.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Repair\SeedStaffGroup;
use OCA\Dossiq\Service\Access\CaseTeamAccessProjector;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Repair\SeedStaffGroup
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class SeedStaffGroupTest extends TestCase {

	/**
	 * Who is in the staff group, by uid.
	 *
	 * @var array<string, true>
	 */
	private array $members = [];

	/**
	 * The marker as the app config holds it.
	 *
	 * @var bool
	 */
	private bool $marker = false;

	/**
	 * An account.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser The account.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * The step over one case type handled by vergunningen and one case assigned to piet.
	 *
	 * @param bool $openRegister Whether OpenRegister answers.
	 *
	 * @return SeedStaffGroup The step.
	 */
	private function step(bool $openRegister = true): SeedStaffGroup {
		$objectService = new class {
			/**
			 * Answers the cases only to a read that lifts the access rules.
			 *
			 * @param array<string, mixed> $query
			 * @param bool                 $_rbac
			 * @param bool                 $_multitenancy
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($_rbac === true || $_multitenancy === true || (int)($query['_offset'] ?? 0) > 0) {
					return [];
				}

				return [['id' => 'case-1', 'assignee' => 'piet'], ['id' => 'case-2', 'assignee' => ''], ['id' => 'case-3']];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($openRegister === true) ? $objectService : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => '20', 'case_schema' => '35'][$key] ?? ''
		);

		$teamAccess = $this->createMock(CaseTeamAccessProjector::class);
		$teamAccess->method('caseTypeTeams')->willReturn([['id' => 'ct-permit', 'teams' => ['vergunningen']]]);

		$staff = $this->createMock(IGroup::class);
		$staff->method('inGroup')->willReturnCallback(fn (IUser $user): bool => isset($this->members[$user->getUID()]));
		$staff->method('addUser')->willReturnCallback(function (IUser $user): void {
			$this->members[$user->getUID()] = true;
		});

		$team = $this->createMock(IGroup::class);
		$team->method('getUsers')->willReturn([$this->user(uid: 'bea'), $this->user(uid: 'jan')]);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			static fn (string $gid): ?IGroup => ['dossiq-medewerkers' => $staff, 'vergunningen' => $team][$gid] ?? null
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $this->user(uid: $uid));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(fn (): bool => $this->marker);
		$appConfig->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->marker = $value;
			return true;
		});

		return new SeedStaffGroup(
			settingsService: $settings,
			teamAccess: $teamAccess,
			groupManager: $groups,
			userManager: $users,
			appConfig: $appConfig,
			logger: new NullLogger(),
		);
	}//end step()

	/**
	 * The handling team's members and the assignee are seeded, once.
	 *
	 * @return void
	 */
	public function testTheCurrentHandlersAndAssigneesAreSeededOnce(): void {
		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame(['bea', 'jan', 'piet'], $this->sortedMembers());
		$this->assertTrue($this->marker);

		// Somebody an administrator takes out stays out on the next upgrade.
		unset($this->members['jan']);
		$this->step()->run($this->createMock(IOutput::class));
		$this->assertSame(['bea', 'piet'], $this->sortedMembers());
	}//end testTheCurrentHandlersAndAssigneesAreSeededOnce()

	/**
	 * A run that could not read records nothing, so the next one tries again.
	 *
	 * @return void
	 */
	public function testARunWithoutOpenRegisterIsNotRecorded(): void {
		$this->step(openRegister: false)->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->members);
		$this->assertFalse($this->marker);
	}//end testARunWithoutOpenRegisterIsNotRecorded()

	/**
	 * The group the register grants is one the instance provisions.
	 *
	 * A register closed to a group the server does not have is closed to
	 * everybody but administrators.
	 *
	 * @return void
	 */
	public function testTheStaffGroupIsProvisioned(): void {
		$this->assertContains(SeedStaffGroup::STAFF_GROUP, ProvisionAssignedGroups::ASSIGNED_GROUPS);
	}//end testTheStaffGroupIsProvisioned()

	/**
	 * The members, sorted.
	 *
	 * @return array<int, string> The uids.
	 */
	private function sortedMembers(): array {
		$uids = array_keys($this->members);
		sort($uids);

		return $uids;
	}//end sortedMembers()
}//end class
