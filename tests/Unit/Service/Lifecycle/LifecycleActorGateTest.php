<?php

/**
 * Who may finish, abort or archive a case: the background account's single
 * permitted act, the admin bypass, the declared role, the closed default for
 * archive, a membership check that cannot be made, and the refusal sentence.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\LifecycleActorGate;
use OCA\Dossiq\Service\Lifecycle\LifecycleCaseTypeRules;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Lifecycle\LifecycleActorGate
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class LifecycleActorGateTest extends TestCase {

	/**
	 * The case every test acts on.
	 *
	 * @var array<string, string>
	 */
	private const CASE = ['id' => 'c-1', 'caseType' => 'ct-1'];

	/**
	 * Case type rules.
	 *
	 * @var LifecycleCaseTypeRules&MockObject
	 */
	private LifecycleCaseTypeRules&MockObject $rules;

	/**
	 * Group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * User session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Background service account.
	 *
	 * @var BackgroundServiceAccount&MockObject
	 */
	private BackgroundServiceAccount&MockObject $account;

	/**
	 * Set up the collaborators; the background account is "bot".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rules   = $this->createMock(LifecycleCaseTypeRules::class);
		$this->groups  = $this->createMock(IGroupManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->account = $this->createMock(BackgroundServiceAccount::class);
		$this->account->method('configuredUserId')->willReturn('bot');
	}//end setUp()

	/**
	 * The gate, with a translator that substitutes its parameters.
	 *
	 * @return LifecycleActorGate
	 */
	private function gate(): LifecycleActorGate {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params=[]): string => vsprintf($text, $params)
		);

		return new LifecycleActorGate(
			rules: $this->rules,
			groupManager: $this->groups,
			userSession: $this->session,
			logger: $this->logger,
			serviceAccount: $this->account,
			l10n: $l10n
		);
	}//end gate()

	/**
	 * Sign this uid in.
	 *
	 * @param string|null $uid The uid, null for no session.
	 *
	 * @return void
	 */
	private function as(?string $uid): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->session->method('getUser')->willReturn($user);
	}//end as()

	/**
	 * Without a session nobody may act.
	 *
	 * @return void
	 */
	public function testNoSessionMayNotAct(): void {
		$this->as(uid: null);
		$this->groups->expects($this->never())->method('isAdmin');

		$this->assertFalse($this->gate()->may(act: 'finish', case: self::CASE));
	}//end testNoSessionMayNotAct()

	/**
	 * The background account may abort only an opted-in case type, and is
	 * never treated as admin.
	 *
	 * @return void
	 */
	public function testTheBackgroundAccountMayOnlyAbortAnOptedInCaseType(): void {
		$this->as(uid: 'bot');
		$this->groups->expects($this->never())->method('isAdmin');
		$this->rules->method('autoCloseOptedIn')->willReturnMap([['ct-1', true], ['ct-2', false]]);
		$gate = $this->gate();

		$this->assertTrue($gate->may(act: 'abort', case: self::CASE));
		$this->assertFalse($gate->may(act: 'abort', case: ['caseType' => 'ct-2']));
		$this->assertFalse($gate->may(act: 'finish', case: self::CASE));
		$this->assertFalse($gate->may(act: 'archive', case: self::CASE));
	}//end testTheBackgroundAccountMayOnlyAbortAnOptedInCaseType()

	/**
	 * An unconfigured background account matches nobody.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredBackgroundAccountMatchesNobody(): void {
		$this->account = $this->createMock(BackgroundServiceAccount::class);
		$this->account->method('configuredUserId')->willReturn('');
		$this->as(uid: '');
		$this->groups->method('isAdmin')->willReturn(true);
		$this->rules->expects($this->never())->method('autoCloseOptedIn');

		$this->assertTrue($this->gate()->may(act: 'archive', case: self::CASE));
	}//end testAnUnconfiguredBackgroundAccountMatchesNobody()

	/**
	 * An admin may do every act without a role check.
	 *
	 * @return void
	 */
	public function testAnAdminMayDoEveryAct(): void {
		$this->as(uid: 'root');
		$this->groups->method('isAdmin')->with('root')->willReturn(true);
		$this->groups->expects($this->never())->method('isInGroup');
		$gate = $this->gate();

		foreach (['finish', 'abort', 'archive'] as $act) {
			$this->assertTrue($gate->may(act: $act, case: self::CASE), $act);
		}
	}//end testAnAdminMayDoEveryAct()

	/**
	 * Without a declared role finish and abort are open and archive is closed.
	 *
	 * @return void
	 */
	public function testWithoutARoleOnlyArchiveIsClosed(): void {
		$this->as(uid: 'anna');
		$this->groups->method('isAdmin')->willReturn(false);
		$this->rules->method('roleFor')->willReturn('');
		$gate = $this->gate();

		$this->assertTrue($gate->may(act: 'finish', case: self::CASE));
		$this->assertTrue($gate->may(act: 'abort', case: self::CASE));
		$this->assertFalse($gate->may(act: 'archive', case: self::CASE));
	}//end testWithoutARoleOnlyArchiveIsClosed()

	/**
	 * A declared role is answered by group membership.
	 *
	 * @return void
	 */
	public function testADeclaredRoleIsAnsweredByMembership(): void {
		$this->as(uid: 'anna');
		$this->groups->method('isAdmin')->willReturn(false);
		$this->rules->method('roleFor')->willReturnMap([['ct-1', 'archive', 'archivaris'], ['ct-1', 'finish', 'behandelaar']]);
		$this->groups->method('isInGroup')->willReturnMap([['anna', 'archivaris', false], ['anna', 'behandelaar', true]]);
		$gate = $this->gate();

		$this->assertTrue($gate->may(act: 'finish', case: self::CASE));
		$this->assertFalse($gate->may(act: 'archive', case: self::CASE));
	}//end testADeclaredRoleIsAnsweredByMembership()

	/**
	 * A membership check that throws refuses and is logged.
	 *
	 * @return void
	 */
	public function testAFailingMembershipCheckRefuses(): void {
		$this->as(uid: 'anna');
		$this->groups->method('isAdmin')->willReturn(false);
		$this->rules->method('roleFor')->willReturn('behandelaar');
		$this->groups->method('isInGroup')->willThrowException(new \RuntimeException('ldap down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'LifecycleActorGate: the role check could not be made',
			['act' => 'finish', 'role' => 'behandelaar', 'exception' => 'ldap down']
		);

		$this->assertFalse($this->gate()->may(act: 'finish', case: self::CASE));
	}//end testAFailingMembershipCheckRefuses()

	/**
	 * An admin check that throws does not grant admin, and is logged.
	 *
	 * @return void
	 */
	public function testAFailingAdminCheckFallsBackToTheRole(): void {
		$this->as(uid: 'anna');
		$this->groups->method('isAdmin')->willThrowException(new \RuntimeException('db down'));
		$this->rules->method('roleFor')->willReturn('');
		$this->logger->expects($this->once())->method('warning')->with(
			'LifecycleActorGate: the admin check could not be made',
			['exception' => 'db down']
		);

		$this->assertFalse($this->gate()->may(act: 'archive', case: self::CASE));
	}//end testAFailingAdminCheckFallsBackToTheRole()

	/**
	 * require passes silently when the act is permitted.
	 *
	 * @return void
	 */
	public function testRequirePassesWhenPermitted(): void {
		$this->as(uid: 'root');
		$this->groups->method('isAdmin')->willReturn(true);

		$this->gate()->require(act: 'archive', case: self::CASE);
		$this->addToAssertionCount(1);
	}//end testRequirePassesWhenPermitted()

	/**
	 * require refuses with a 403 naming the act and the declared group.
	 *
	 * @return void
	 */
	public function testRequireRefusesNamingTheGroup(): void {
		$this->as(uid: 'anna');
		$this->groups->method('isAdmin')->willReturn(false);
		$this->rules->method('roleFor')->willReturn('archivaris');
		$this->groups->method('isInGroup')->willReturn(false);

		try {
			$this->gate()->require(act: 'archive', case: self::CASE);
			$this->fail('Expected a refusal.');
		} catch (RefusedException $e) {
			$this->assertSame('archive-role-required', $e->getRule());
			$this->assertSame('This act needs the archivaris group.', $e->getSentence());
			$this->assertSame(RefusedException::STATUS_FORBIDDEN, $e->getStatus());
		}
	}//end testRequireRefusesNamingTheGroup()

	/**
	 * The refusal names an administrator without a role, and the background
	 * account's own rule for the background account.
	 *
	 * @return void
	 */
	public function testRefusalSentenceForAdminOnlyAndBackgroundAccount(): void {
		$this->rules->method('roleFor')->willReturn('');
		$this->as(uid: 'anna');
		$this->assertSame('This act needs an administrator.', $this->gate()->refusalSentence(act: 'archive', case: self::CASE));

		$this->session = $this->createMock(IUserSession::class);
		$this->as(uid: 'bot');
		$this->assertSame(
			'The background account may only close a silent case of a case type that opted in to it.',
			$this->gate()->refusalSentence(act: 'finish', case: self::CASE)
		);
	}//end testRefusalSentenceForAdminOnlyAndBackgroundAccount()
}//end class
