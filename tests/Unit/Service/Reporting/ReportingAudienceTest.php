<?php

/**
 * Who may read a figure about every case, and who is refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use OCA\Dossiq\Controller\ProcessMiningController;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The gate a fleet-wide report asks before it answers.
 *
 * @covers \OCA\Dossiq\Service\Reporting\ReportingAudience
 */
class ReportingAudienceTest extends TestCase {

	/**
	 * A group manager answering a fixed world.
	 *
	 * @param array<int, string> $memberOf The groups the caller is in.
	 * @param bool               $isAdmin  Whether the caller is an administrator.
	 *
	 * @return IGroupManager The double.
	 */
	private function manager(array $memberOf, bool $isAdmin = false): IGroupManager {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($gid, $memberOf, true)
		);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return $groups;
	}

	/**
	 * A caller.
	 *
	 * @param string $uid The account.
	 *
	 * @return IUser The user.
	 */
	private function caller(string $uid = 'sanne'): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	/**
	 * A controller reads the report.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function testAControllerMayRead(): void {
		$audience = new ReportingAudience(groupManager: $this->manager(memberOf: ['controllers']));

		$this->assertTrue($audience->mayRead(user: $this->caller()));
	}//end testAControllerMayRead()

	/**
	 * A case handler does not.
	 *
	 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED.
	 * `behandelaars` is the group the shipped flow assigns work to, so this is
	 * the account that was reading the annual dwangsom statement before this
	 * change and is the one the fix is about.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function testACaseHandlerIsRefused(): void {
		$audience = new ReportingAudience(groupManager: $this->manager(memberOf: ['behandelaars']));

		$this->assertFalse($audience->mayRead(user: $this->caller()));
	}//end testACaseHandlerIsRefused()

	/**
	 * A Nextcloud administrator reads it without joining a group.
	 *
	 * The defensive default every sibling gate keeps: an instance that has not
	 * yet named its controllers still has somebody who can read the report and
	 * fix the configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function testAnAdministratorMayRead(): void {
		$audience = new ReportingAudience(groupManager: $this->manager(memberOf: [], isAdmin: true));

		$this->assertTrue($audience->mayRead(user: $this->caller()));
	}//end testAnAdministratorMayRead()

	/**
	 * No session, and an account with no id, read nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function testNoSessionReadsNothing(): void {
		$audience = new ReportingAudience(groupManager: $this->manager(memberOf: ['controllers'], isAdmin: true));

		$this->assertFalse($audience->mayRead(user: null));
		$this->assertFalse($audience->mayRead(user: $this->caller(uid: '')));
	}//end testNoSessionReadsNothing()

	/**
	 * The vocabulary is the one this app already gates on.
	 *
	 * 🔑 THIS IS THE ANTI-DUPLICATION ASSERTION. The groups are not new: they
	 * are `ProcessMiningController::ALLOWED_GROUPS` verbatim, and this class
	 * exists because the SHAPE was copied into one controller and not the
	 * others. Two lists of group names is two answers to one question, and they
	 * disagree the first week somebody edits one. This fails when they drift.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function testTheGroupsAreTheOnesTheAppAlreadyGatesOn(): void {
		$mining = new ReflectionClass(ProcessMiningController::class);
		$existing = $mining->getConstant('ALLOWED_GROUPS');

		$this->assertIsArray($existing, 'ProcessMiningController must still declare its groups');
		$this->assertSame(
			$existing,
			ReportingAudience::ALLOWED_GROUPS,
			'the reporting audience drifted from the gate this app already had'
		);
	}//end testTheGroupsAreTheOnesTheAppAlreadyGatesOn()
}//end class
