<?php

/**
 * A draft case: no term, its author's, promoted in one act.
 *
 * "A concept-zaak whose Awb clock has not started is a real object in a
 * gemeente, and the alternative is a half-filled case that is already
 * overdue." The overdue half is what these assertions guard: a draft that kept
 * its startDate would be counting down from the moment somebody started
 * typing, and nothing on the screen would say so.
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

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Lifecycle\DraftCaseActs;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Begin a draft, and promote it.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\DraftCaseActs
 */
class DraftCaseTest extends TestCase {

	/**
	 * The case as the store currently holds it.
	 *
	 * @var array<string, mixed>
	 */
	private array $case;

	/**
	 * The store.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $store;

	/**
	 * The acts under test.
	 *
	 * @var DraftCaseActs
	 */
	private DraftCaseActs $drafts;

	/**
	 * A case of a type carrying a statutory term.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->case = [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'startDate' => '2026-09-01',
			'deadline' => '2026-10-13',
			'plannedEndDate' => '2026-10-13',
		];

		$this->store = $this->createMock(originalClassName: CaseStatusStore::class);
		$this->store->method('loadCase')->willReturnCallback(fn (): array => $this->case);
		$this->store->method('saveCase')->willReturnCallback(
			function (array $case): array {
				$this->case = $case;
				return $case;
			}
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('ahmed');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->drafts = new DraftCaseActs(
			store: $this->store,
			journal: new CaseJournal(userSession: $session),
		);
	}//end setUp()

	/**
	 * A draft binds no term, so it cannot already be overdue.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testADraftBindsNoTerm(): void {
		$this->drafts->begin(caseId: 'case-1');

		$this->assertTrue(condition: $this->case['isDraft']);
		$this->assertNull(actual: $this->case['startDate'], message: 'a draft that kept its start date is already counting down');
		$this->assertNull(actual: $this->case['deadline']);
		$this->assertNull(actual: $this->case['plannedEndDate']);
	}//end testADraftBindsNoTerm()

	/**
	 * A draft belongs to its author, which is what makes it reachable by them
	 * and by nobody else through dossiq's own endpoints.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testADraftIsAssignedToItsAuthor(): void {
		$this->drafts->begin(caseId: 'case-1');

		$this->assertSame(expected: 'ahmed', actual: $this->case['assignee']);
	}//end testADraftIsAssignedToItsAuthor()

	/**
	 * A draft that already has a handler keeps them.
	 *
	 * The control for the assertion above: without it, an implementation that
	 * always overwrote the assignee would pass, and it would quietly take a
	 * case away from whoever had it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testADraftWithAHandlerKeepsThem(): void {
		$this->case['assignee'] = 'fatima';

		$this->drafts->begin(caseId: 'case-1');

		$this->assertSame(expected: 'fatima', actual: $this->case['assignee']);
	}//end testADraftWithAHandlerKeepsThem()

	/**
	 * Promoting binds the clock and keeps the moment the draft was begun.
	 *
	 * When the aanvraag arrived is a fact somebody will ask about, and the
	 * promotion moment is not it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testPromotingBindsTheClockAndKeepsTheDraftMoment(): void {
		$this->drafts->begin(caseId: 'case-1');
		$begun = (string)$this->case['draftCreatedAt'];

		$answer = $this->drafts->promote(caseId: 'case-1');

		$this->assertFalse(condition: $this->case['isDraft']);
		$this->assertSame(expected: (new DateTimeImmutable('today'))->format('Y-m-d'), actual: $this->case['startDate']);
		$this->assertSame(expected: $begun, actual: $answer['draftCreatedAt']);
		$this->assertSame(expected: $begun, actual: (string)$this->case['draftCreatedAt']);
	}//end testPromotingBindsTheClockAndKeepsTheDraftMoment()

	/**
	 * Promoting writes no deadline, because the register calculates it.
	 *
	 * `deadline` is declared as a calculation over startDate and the case
	 * type's processingDeadline. A date written here would be a second answer
	 * to a question the register already answers, and the two would disagree
	 * the first time a case type's term changed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testPromotingLeavesTheDeadlineToTheRegister(): void {
		$this->drafts->begin(caseId: 'case-1');
		$this->drafts->promote(caseId: 'case-1');

		$this->assertNull(actual: $this->case['deadline'], message: 'dossiq must not compute the deadline itself');
	}//end testPromotingLeavesTheDeadlineToTheRegister()

	/**
	 * Promoting something that is not a draft is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testPromotingANonDraftIsRefused(): void {
		$this->expectException(exception: RefusedException::class);
		$this->expectExceptionMessage(message: 'case_not_a_draft');

		$this->drafts->promote(caseId: 'case-1');
	}//end testPromotingANonDraftIsRefused()

	/**
	 * The draft flag is read the way every JSON boolean in this app is read.
	 *
	 * @param mixed $value The stored value.
	 * @param bool $expected Whether it means draft.
	 *
	 * @return void
	 *
	 * @dataProvider flags
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testTheDraftFlagIsCoerced(mixed $value, bool $expected): void {
		$this->assertSame(expected: $expected, actual: $this->drafts->isDraft(case: ['isDraft' => $value]));
	}//end testTheDraftFlagIsCoerced()

	/**
	 * The shapes a JSON boolean arrives in.
	 *
	 * @return array<string, array{0: mixed, 1: bool}> The rows.
	 */
	public static function flags(): array {
		return [
			'true' => [true, true],
			'one' => [1, true],
			'the string one' => ['1', true],
			'the string true' => ['true', true],
			'false' => [false, false],
			'zero' => [0, false],
			'the empty string' => ['', false],
			'null' => [null, false],
		];
	}//end flags()
}//end class
