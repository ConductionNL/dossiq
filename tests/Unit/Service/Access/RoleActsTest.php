<?php

/**
 * Dossiq Role Acts Test
 *
 * Every role dossiq provisions states what its members may do, the admin
 * screen words every act, and a member who holds only `auditors` is refused a
 * status move.
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
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md#requirement-each-application-role-states-what-its-members-may-do-req-crs-14
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\Access\RoleActs;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The role acts catalogue against the provisioned groups, the admin screen's wording and the shipped gates.
 *
 * @covers \OCA\Dossiq\Service\Access\RoleActs
 * @uses   \OCA\Dossiq\Service\Transitions\TransitionAuthorizer
 */
final class RoleActsTest extends TestCase {

	/**
	 * Every provisioned group states its acts, and every group stated is provisioned.
	 *
	 * @return void
	 */
	public function testEveryProvisionedRoleStatesItsActs(): void {
		self::assertSame(ProvisionAssignedGroups::ASSIGNED_GROUPS, array_keys(RoleActs::ACTS));
		foreach (RoleActs::ACTS as $group => $acts) {
			self::assertNotSame([], $acts, $group);
			self::assertSame(array_values(array_unique($acts)), $acts, $group);
		}
	}//end testEveryProvisionedRoleStatesItsActs()

	/**
	 * The admin screen has words for every act, and for nothing else.
	 *
	 * @return void
	 */
	public function testTheAdminScreenWordsEveryAct(): void {
		$source = (string)file_get_contents(dirname(__DIR__, 4) . '/src/views/settings/roleActs.js');
		preg_match_all("/^\\t\\t'([a-z-]+)': t\\('dossiq'/m", $source, $matches);

		$acts = array_values(array_unique(array_merge(...array_values(RoleActs::ACTS))));
		sort($acts);
		$worded = $matches[1];
		sort($worded);

		self::assertSame($acts, $worded);
	}//end testTheAdminScreenWordsEveryAct()

	/**
	 * The table follows the order asked for; an unknown group is listed with nothing.
	 *
	 * @return void
	 */
	public function testTheTableFollowsTheGivenOrder(): void {
		$roles = new RoleActs();

		self::assertSame(
			[
				['group' => 'auditors', 'acts' => ['export-ai-audit-log']],
				['group' => 'nobody', 'acts' => []],
			],
			$roles->table(groups: ['auditors', 'nobody'])
		);
		self::assertSame(array_keys(RoleActs::ACTS), array_column($roles->table(), 'group'));
	}//end testTheTableFollowsTheGivenOrder()

	/**
	 * GIVEN a user whose only role is auditors WHEN they try to move a case THEN the move is refused,
	 * and reading the audit log is what auditors may do.
	 *
	 * @return void
	 */
	public function testAnAuditorReadsButDoesNotChange(): void {
		$roles = new RoleActs();
		self::assertTrue($roles->allows(group: 'auditors', act: 'export-ai-audit-log'));
		self::assertFalse($roles->allows(group: 'auditors', act: 'handle-assigned-cases'));
		self::assertFalse($roles->allows(group: 'auditors', act: 'make-any-transition'));

		// A transition authorised to the handlers refuses a member of auditors only.
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $user, string $group): bool => $group === 'auditors');
		$authorizer = new TransitionAuthorizer(groupManager: $groups, logger: $this->createMock(LoggerInterface::class));
		self::assertFalse($authorizer->isTransitionGroupAuthorized(transition: ['authorization' => ['behandelaars']], userId: 'auditor'));

		// And the case itself is written only by its assignee and the background service:
		// no shipped update rule names auditors, so an auditor who is not the assignee cannot move it.
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/register.d/66-notification-routing.json'), true, flags: JSON_THROW_ON_ERROR);
		$update   = $fragment['components']['schemas']['case']['authorization']['update'];
		self::assertSame([['group' => 'authenticated', 'match' => ['assignee' => '$userId']], 'dossiq-background-service'], $update);
	}//end testAnAuditorReadsButDoesNotChange()
}//end class
