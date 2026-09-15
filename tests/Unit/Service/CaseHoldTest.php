<?php

/**
 * Hold and park: a reason, a wake date, and the clock left alone.
 *
 * The last of those is the reason this file exists. A hold that quietly
 * stopped a statutory term would look identical to the handler who set it and
 * would be indefensible afterwards, so the deadline is asserted UNCHANGED
 * rather than left unmentioned. An absence nobody asserts is an absence
 * nobody notices going away.
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
use OCA\Dossiq\Service\Lifecycle\CaseHoldActs;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;

/**
 * A case parked until a date, and the term that keeps running.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseHoldActs
 */
class CaseHoldTest extends TestCase {

	// The one normaliser, on a stated zone. Real and not a double: the
	// assertions here are about which DAY a hold lands on, and a stubbed
	// parser would be answering the question under test.
	use MakesCaseDateNormaliser;

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
	 * @var CaseHoldActs
	 */
	private CaseHoldActs $holds;

	/**
	 * An open case with a statutory term already running.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->case = [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'status' => 'st-2',
			'deadline' => '2026-11-01',
			'plannedEndDate' => '2026-11-01',
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

		$this->holds = new CaseHoldActs(
			store: $this->store,
			journal: new CaseJournal(userSession: $session),
			dates: $this->caseDates(),
		);
	}//end setUp()

	/**
	 * A date some days from today, so the test does not expire.
	 *
	 * @param string $offset A relative date expression.
	 *
	 * @return string The date as Y-m-d.
	 */
	private function day(string $offset): string {
		return (new DateTimeImmutable('today'))->modify($offset)->format('Y-m-d');
	}//end day()

	/**
	 * A case is parked until a date, with the reason recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testACaseIsParkedUntilADate(): void {
		$wake = $this->day(offset: '+30 days');

		$answer = $this->holds->hold(caseId: 'case-1', reason: 'Wacht op de aanvrager', until: $wake);

		$this->assertTrue(condition: $answer['held']);
		$this->assertSame(expected: $wake, actual: $this->case['heldUntil']);
		$this->assertSame(expected: 'Wacht op de aanvrager', actual: $this->case['holdReason']);
		$this->assertTrue(condition: $this->holds->isHeld(case: $this->case));
	}//end testACaseIsParkedUntilADate()

	/**
	 * A hold does not touch any statutory term.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAHoldDoesNotStopTheClock(): void {
		$this->holds->hold(caseId: 'case-1', reason: 'Wacht op de aanvrager', until: $this->day(offset: '+30 days'));

		$this->assertSame(expected: '2026-11-01', actual: $this->case['deadline'], message: 'a hold must never move the deadline');
		$this->assertSame(expected: '2026-11-01', actual: $this->case['plannedEndDate']);
		$this->assertArrayNotHasKey(key: 'extensionCount', array: $this->case, message: 'a hold is not an extension');
	}//end testAHoldDoesNotStopTheClock()

	/**
	 * A hold whose date has passed is no longer a hold.
	 *
	 * This is what "it comes back on its date" means mechanically: nothing
	 * runs overnight, because a date that has passed simply stops being in the
	 * future. A flag would need something to clear it, and that something is
	 * exactly what would fail silently.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAHoldUntilYesterdayIsOver(): void {
		$this->assertFalse(
			condition: $this->holds->isHeld(case: ['heldUntil' => $this->day(offset: '-1 day')]),
			message: 'a case held until yesterday is back in the queue');
		$this->assertTrue(
			condition: $this->holds->isHeld(case: ['heldUntil' => $this->day(offset: '+1 day')]),
			message: 'the control: a hold that is still ahead reads held');
	}//end testAHoldUntilYesterdayIsOver()

	/**
	 * A hold with no reason, no date, or a date in the past is refused.
	 *
	 * @param string $reason The reason given.
	 * @param string $until The date given, as a relative expression or empty.
	 * @param string $rule The rule slug expected in `error`.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnImpossibleHoldIsRefused(string $reason, string $until, string $rule): void {
		$named = '';
		if ($until !== '') {
			$named = $this->day(offset: $until);
		}

		try {
			$this->holds->hold(caseId: 'case-1', reason: $reason, until: $named);
			$this->fail(message: 'this hold should have been refused');
		} catch (RefusedException $e) {
			$this->assertSame(expected: $rule, actual: $e->getRule());
			$this->assertArrayNotHasKey(key: 'heldUntil', array: $this->case, message: 'a refused hold must write nothing');
		}
	}//end testAnImpossibleHoldIsRefused()

	/**
	 * The holds that are not holds.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}> The rows.
	 */
	public static function refusals(): array {
		return [
			'no reason' => ['   ', '+30 days', 'reason-required'],
			'no date' => ['Wacht', '', 'wake-date-required'],
			'a date already past' => ['Wacht', '-1 day', 'wake-date-not-ahead'],
			'today' => ['Wacht', '+0 days', 'wake-date-not-ahead'],
		];
	}//end refusals()

	/**
	 * Releasing a case that is not held is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testReleasingACaseThatIsNotHeldIsRefused(): void {
		$this->expectException(exception: RefusedException::class);
		$this->expectExceptionMessage(message: 'case_not_held');

		$this->holds->release(caseId: 'case-1', reason: 'Toch oppakken');
	}//end testReleasingACaseThatIsNotHeldIsRefused()

	/**
	 * Releasing a held case clears the marker and records why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testReleasingClearsTheHold(): void {
		$this->holds->hold(caseId: 'case-1', reason: 'Wacht', until: $this->day(offset: '+30 days'));

		$answer = $this->holds->release(caseId: 'case-1', reason: 'Aanvulling binnen');

		$this->assertFalse(condition: $answer['held']);
		$this->assertFalse(condition: $this->holds->isHeld(case: $this->case));
		$entries = (array)json_decode((string)$this->case['activity'], true);
		$last = (array)end($entries);
		$this->assertSame(expected: 'release', actual: $last['type']);
		$this->assertSame(expected: 'ahmed', actual: $last['by']);
	}//end testReleasingClearsTheHold()
}//end class
