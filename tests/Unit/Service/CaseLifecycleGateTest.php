<?php

/**
 * An act a handler may not perform is disabled and names the role.
 *
 * Not hidden. A hidden act teaches nobody why, and the handler's next move
 * depends entirely on the answer: "you may not archive this case" ends the
 * conversation, and "archiving this case type needs the archivaris group"
 * starts one with whoever grants it.
 *
 * The asymmetry between the acts is deliberate and is asserted as such.
 * Finishing and aborting fall OPEN when the case type names no group, because
 * that is today's behaviour and silently locking it out on upgrade would
 * strand every case in every gemeente running this app. Archiving falls
 * CLOSED, because it commits a retention rule.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\LifecycleActorGate;
use OCA\Dossiq\Service\Lifecycle\LifecycleCaseTypeRules;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The per-act, per-case-type role gate.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\LifecycleActorGate
 */
class CaseLifecycleGateTest extends TestCase {

	/**
	 * The case every row in this file is about.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE = ['id' => 'case-1', 'caseType' => 'ct-1'];

	/**
	 * A gate over a case type declaring the given roles.
	 *
	 * @param array<string, string> $roles The declared group per act.
	 * @param list<string> $memberOf The groups the caller is in.
	 * @param bool $admin Whether the caller is an administrator.
	 *
	 * @return LifecycleActorGate The gate under test.
	 */
	private function gate(array $roles, array $memberOf = [], bool $admin = false): LifecycleActorGate {
		$rules = $this->createMock(originalClassName: LifecycleCaseTypeRules::class);
		$rules->method('roleFor')->willReturnCallback(
			static fn (string $caseTypeId, string $act): string => ($roles[$act] ?? '')
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('ahmed');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, $memberOf, true)
		);

		return new LifecycleActorGate(
			rules: $rules,
			groupManager: $groups,
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end gate()

	/**
	 * An act the handler's role forbids is refused, and the refusal names it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testARefusedActNamesTheRole(): void {
		$gate = $this->gate(roles: ['archive' => 'archivaris']);

		$this->assertFalse(condition: $gate->may(act: 'archive', case: self::CASE));
		$this->assertSame(expected: 'archivaris', actual: $gate->roleFor(act: 'archive', case: self::CASE));
		$this->assertStringContainsString(
			needle: 'archivaris',
			haystack: $gate->refusalSentence(act: 'archive', case: self::CASE),
			message: 'a refusal that does not name the group cannot be acted on');

		try {
			$gate->require(act: 'archive', case: self::CASE);
			$this->fail(message: 'the act must be refused');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'archive-role-required', actual: $e->getRule());
			$this->assertSame(expected: RefusedException::STATUS_FORBIDDEN, actual: $e->getStatus());
		}
	}//end testARefusedActNamesTheRole()

	/**
	 * A handler in the declared group may perform the act.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAHandlerInTheGroupMayAct(): void {
		$gate = $this->gate(roles: ['archive' => 'archivaris'], memberOf: ['archivaris']);

		$this->assertTrue(condition: $gate->may(act: 'archive', case: self::CASE));
		$gate->require(act: 'archive', case: self::CASE);
	}//end testAHandlerInTheGroupMayAct()

	/**
	 * Being in the archiving group does not grant finishing.
	 *
	 * Each act carries its OWN permission, which is the half of REQ-LIFE-11
	 * that a single shared gate would quietly lose.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testARoleIsPerAct(): void {
		$gate = $this->gate(
			roles: ['archive' => 'archivaris', 'finish' => 'behandelaars'],
			memberOf: ['archivaris'],
		);

		$this->assertTrue(condition: $gate->may(act: 'archive', case: self::CASE));
		$this->assertFalse(condition: $gate->may(act: 'finish', case: self::CASE));
	}//end testARoleIsPerAct()

	/**
	 * An undeclared role opens finishing and aborting, and closes archiving.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUndeclaredRoleFallsTwoDifferentWays(): void {
		$gate = $this->gate(roles: []);

		$this->assertTrue(condition: $gate->may(act: 'finish', case: self::CASE), message: 'finishing keeps today\'s behaviour');
		$this->assertTrue(condition: $gate->may(act: 'abort', case: self::CASE), message: 'aborting keeps today\'s behaviour');
		$this->assertFalse(condition: $gate->may(act: 'archive', case: self::CASE), message: 'archiving commits retention');
		$this->assertStringContainsString(
			needle: 'administrator',
			haystack: $gate->refusalSentence(act: 'archive', case: self::CASE),
		);
	}//end testAnUndeclaredRoleFallsTwoDifferentWays()

	/**
	 * An administrator may do all three whatever the case type declares.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnAdministratorMayActWhateverIsDeclared(): void {
		$gate = $this->gate(
			roles: ['finish' => 'nobody', 'abort' => 'nobody', 'archive' => 'nobody'],
			admin: true,
		);

		foreach (['finish', 'abort', 'archive'] as $act) {
			$this->assertTrue(condition: $gate->may(act: $act, case: self::CASE), message: $act.' must be open to an administrator');
		}
	}//end testAnAdministratorMayActWhateverIsDeclared()

	/**
	 * With nobody signed in, nothing is permitted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testNoSessionMeansNoAct(): void {
		$rules = $this->createMock(originalClassName: LifecycleCaseTypeRules::class);
		$rules->method('roleFor')->willReturn('');

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$gate = new LifecycleActorGate(
			rules: $rules,
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertFalse(condition: $gate->may(act: 'finish', case: self::CASE));
	}//end testNoSessionMeansNoAct()

	/**
	 * A membership check that throws refuses rather than granting.
	 *
	 * An unresolvable authority is not permission, and the alternative shape
	 * (catch, return true) is the fail-open this fleet has been bitten by.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnresolvableCheckRefuses(): void {
		$rules = $this->createMock(originalClassName: LifecycleCaseTypeRules::class);
		$rules->method('roleFor')->willReturn('archivaris');

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('ahmed');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willThrowException(new \RuntimeException('LDAP is down'));

		$gate = new LifecycleActorGate(
			rules: $rules,
			groupManager: $groups,
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertFalse(condition: $gate->may(act: 'archive', case: self::CASE));
	}//end testAnUnresolvableCheckRefuses()
}//end class
