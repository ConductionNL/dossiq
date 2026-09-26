<?php

/**
 * What a handler may do to a case, and who the case is waiting on.
 *
 * The menu is the half of the act read that answers permission questions,
 * and it was a private method on the act facade until it moved here. A
 * refused act that is simply absent from the menu teaches nobody why they
 * cannot press the button, so the shape these tests pin is "listed and
 * disabled with a reason", not "present or not".
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Service\CaseType\AlwaysAvailableActs;
use OCA\Dossiq\Service\Cases\ApprovalGate;
use OCA\Dossiq\Service\Lifecycle\CaseActsMenu;
use OCA\Dossiq\Service\Lifecycle\LifecycleActorGate;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The permission half of the act menu.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseActsMenu
 */
class CaseActsMenuTest extends TestCase {

	/**
	 * The case every test here asks about.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE = ['id' => 'case-1', 'caseType' => 'ct-1'];

	/**
	 * A gate that permits everything except archiving.
	 *
	 * @return LifecycleActorGate The gate.
	 */
	private function gate(): LifecycleActorGate {
		$gate = $this->createMock(originalClassName: LifecycleActorGate::class);
		$gate->method('may')->willReturnCallback(
			static fn (string $act): bool => ($act !== 'archive')
		);
		$gate->method('roleFor')->willReturnCallback(
			static fn (string $act): string => ($act === 'archive' ? 'archivaris' : '')
		);
		$gate->method('refusalSentence')->willReturn('This act needs the archivaris group.');

		return $gate;
	}//end gate()

	/**
	 * A session that names one handler.
	 *
	 * @return IUserSession The session.
	 */
	private function session(): IUserSession {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('ahmed');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * Every role-gated act is listed, and a refused one carries the reason
	 * and the role that would lift the refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testARefusedActIsListedWithItsReasonAndRole(): void {
		$menu = new CaseActsMenu(gate: $this->gate());

		$acts = array_column(
			array: $menu->forCase(caseId: 'case-1', case: self::CASE)['acts'],
			column_key: null,
			index_key: 'act'
		);

		self::assertSame(['finish', 'abort', 'archive'], array_keys($acts));
		self::assertFalse($acts['archive']['allowed']);
		self::assertSame('archivaris', $acts['archive']['role']);
		self::assertSame('This act needs the archivaris group.', $acts['archive']['reason']);
	}//end testARefusedActIsListedWithItsReasonAndRole()

	/**
	 * A permitted act carries no reason, so the menu renders nothing beside
	 * it. Only a refusal explains itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAPermittedActCarriesNoReason(): void {
		$menu = new CaseActsMenu(gate: $this->gate());

		$acts = array_column(
			array: $menu->forCase(caseId: 'case-1', case: self::CASE)['acts'],
			column_key: null,
			index_key: 'act'
		);

		self::assertTrue($acts['finish']['allowed']);
		self::assertSame('', $acts['finish']['reason']);
	}//end testAPermittedActCarriesNoReason()

	/**
	 * The always-available acts and the outstanding approvals are asked for
	 * the SIGNED-IN handler, not for the case. Asking for the wrong principal
	 * answers a menu that is plausible and belongs to somebody else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public function testTheHalvesAreAskedForTheSignedInHandler(): void {
		$always = $this->createMock(originalClassName: AlwaysAvailableActs::class);
		$always->expects($this->once())
			->method('forCase')
			->with(caseId: 'case-1', userId: 'ahmed')
			->willReturn([['act' => 'note']]);

		$approvals = $this->createMock(originalClassName: ApprovalGate::class);
		$approvals->expects($this->once())
			->method('awaiting')
			->with(case: self::CASE, userId: 'ahmed')
			->willReturn([['on' => 'fatima']]);

		$menu = new CaseActsMenu(
			gate: $this->gate(),
			alwaysAvailable: $always,
			userSession: $this->session(),
			approvals: $approvals,
		);

		$answer = $menu->forCase(caseId: 'case-1', case: self::CASE);

		self::assertSame([['act' => 'note']], $answer['alwaysAvailable']);
		self::assertSame([['on' => 'fatima']], $answer['awaitingApproval']);
	}//end testTheHalvesAreAskedForTheSignedInHandler()

	/**
	 * With neither half wired the menu answers empty lists rather than
	 * failing, because both arrived after the menu did and an instance that
	 * predates them still has to answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
	 */
	public function testAnUnwiredHalfAnswersAnEmptyListRatherThanFailing(): void {
		$menu = new CaseActsMenu(gate: $this->gate());

		$answer = $menu->forCase(caseId: 'case-1', case: self::CASE);

		self::assertSame([], $answer['alwaysAvailable']);
		self::assertSame([], $answer['awaitingApproval']);
	}//end testAnUnwiredHalfAnswersAnEmptyListRatherThanFailing()

	/**
	 * With nobody signed in the halves are still asked, for the empty user.
	 * Answering nothing at all would hide an always-available act from a
	 * background caller that legitimately has no session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public function testNoSessionAsksForTheEmptyUser(): void {
		$always = $this->createMock(originalClassName: AlwaysAvailableActs::class);
		$always->expects($this->once())
			->method('forCase')
			->with(caseId: 'case-1', userId: '')
			->willReturn([]);

		$menu = new CaseActsMenu(gate: $this->gate(), alwaysAvailable: $always);

		self::assertSame([], $menu->forCase(caseId: 'case-1', case: self::CASE)['alwaysAvailable']);
	}//end testNoSessionAsksForTheEmptyUser()
}//end class
