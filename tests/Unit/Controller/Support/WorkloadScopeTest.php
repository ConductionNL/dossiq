<?php

/**
 * Workload Scope Test
 *
 * Pins what the workload scope answers on its own: the coordinator check
 * (never for an empty uid, else Nextcloud admin), every handler when there are
 * no teams, the zero row of a member without open cases, and the display name
 * of a handler, the id itself for a handler who is no longer a user.
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

use OCA\Dossiq\Controller\Support\WorkloadScope;
use OCA\Dossiq\Kernel\Contracts\PrimaryTeamServiceInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for WorkloadScope.
 */
#[CoversClass(WorkloadScope::class)]
final class WorkloadScopeTest extends TestCase {
	/**
	 * A scope over a group manager with these members and admins, and a user manager with these names.
	 *
	 * @param array<string, array<string, string>> $groups The members per group.
	 * @param array<int, string>                   $admins The admin uids.
	 * @param array<string, string>                $names  The display names per uid.
	 *
	 * @return WorkloadScope
	 */
	private function scope(array $groups = [], array $admins = [], array $names = []): WorkloadScope {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('displayNamesInGroup')->willReturnCallback(
			static fn (string $gid): array => ($groups[$gid] ?? [])
		);
		$groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => in_array(needle: $uid, haystack: $admins, strict: true)
		);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => ($names[$uid] ?? null)
		);

		return new WorkloadScope(
			groupManager: $groupManager,
			userManager: $userManager,
			teams: $this->createMock(originalClassName: PrimaryTeamServiceInterface::class),
			logger: new NullLogger(),
		);
	}//end scope()

	/**
	 * An empty uid is never a coordinator, not even when the group manager would say so.
	 *
	 * @return void
	 */
	public function testTheCoordinatorIsAnAdminAndNeverAnEmptyUid(): void {
		$scope = $this->scope(admins: ['', 'root']);

		self::assertFalse(condition: $scope->isCoordinator(userId: ''));
		self::assertTrue(condition: $scope->isCoordinator(userId: 'root'));
		self::assertFalse(condition: $scope->isCoordinator(userId: 'fatma'));
	}//end testTheCoordinatorIsAnAdminAndNeverAnEmptyUid()

	/**
	 * Without teams every handler stays; with a team only its members, a member without cases at 0.
	 *
	 * @return void
	 */
	public function testTeamsKeepTheirMembersAndAddTheIdleOnes(): void {
		$handlers = [['handler' => 'alice', 'openCaseCount' => 4], ['handler' => 'bob', 'openCaseCount' => 2]];
		$scope    = $this->scope(groups: ['team-a' => ['alice' => 'Alice', 'dave' => 'Dave']]);

		self::assertSame(expected: $handlers, actual: $scope->ofTeams(teamGroupIds: null, handlers: $handlers));
		self::assertSame(expected: $handlers, actual: $scope->ofTeams(teamGroupIds: [], handlers: $handlers));
		self::assertSame(
			expected: [['handler' => 'alice', 'openCaseCount' => 4], ['handler' => 'dave', 'openCaseCount' => 0]],
			actual: $scope->ofTeams(teamGroupIds: ['team-a'], handlers: $handlers),
		);
	}//end testTeamsKeepTheirMembersAndAddTheIdleOnes()

	/**
	 * A handler gets their display name; one who is no longer a user keeps the id.
	 *
	 * @return void
	 */
	public function testAHandlerNoLongerAUserKeepsTheIdAsName(): void {
		$rows = $this->scope(names: ['alice' => 'Alice de Vries'])->withDisplayNames(
			handlers: [['handler' => 'alice', 'openCaseCount' => 1], ['handler' => 'gone', 'openCaseCount' => 3]]
		);

		self::assertSame(expected: 'Alice de Vries', actual: $rows[0]['displayName']);
		self::assertSame(expected: 'gone', actual: $rows[1]['displayName']);
		self::assertSame(expected: 3, actual: $rows[1]['openCaseCount']);
	}//end testAHandlerNoLongerAUserKeepsTheIdAsName()
}//end class
