<?php

/**
 * Dossiq TermStatusClock test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\TermStatusClock;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The clock runs while the case is ours to move, and stops while it is not.
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */
class TermStatusClockTest extends TestCase {
	/**
	 * The term store.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $terms;

	/**
	 * The engine's suspend and resume.
	 *
	 * @var TermijnTimerService&MockObject
	 */
	private TermijnTimerService $timers;

	/**
	 * The patches written on the instance.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->terms = $this->createMock(TermijnService::class);
		$this->timers = $this->createMock(TermijnTimerService::class);
		$this->patches = [];

		$this->terms->method('updateTermijnInstance')->willReturnCallback(
			function (string $termInstanceId, array $patch): ?array {
				$this->patches[] = $patch;
				return $patch;
			}
		);
	}//end setUp()

	/**
	 * Scenario: The clock stops while the case sits with an adviser.
	 *
	 * @return void
	 */
	public function testLeavingTheRunningStatusesStopsTheClock(): void {
		$this->given(instance: ['id' => 'term-1', 'status' => 'lopend'], runsIn: ['in-behandeling']);
		$this->timers->expects($this->once())->method('suspendBeslistermijn');
		$this->timers->expects($this->never())->method('resumeBeslistermijn');

		$this->assertSame(
			TermStatusClock::SUSPENDED,
			$this->clock()->reconcile(caseId: 'case-1', statusId: 'bij-adviseur', caseType: 'vergunning')
		);
		$this->assertSame([['clockStoppedByStatus' => true]], $this->patches);
	}//end testLeavingTheRunningStatusesStopsTheClock()

	/**
	 * Coming back starts it again, and clears the marker.
	 *
	 * @return void
	 */
	public function testComingBackStartsTheClockAgain(): void {
		$this->given(
			instance: ['id' => 'term-1', 'status' => 'lopend', 'clockStoppedByStatus' => true],
			runsIn: ['in-behandeling']
		);
		$this->timers->expects($this->once())->method('resumeBeslistermijn');

		$this->assertSame(
			TermStatusClock::RESUMED,
			$this->clock()->reconcile(caseId: 'case-1', statusId: 'in-behandeling', caseType: 'vergunning')
		);
		$this->assertSame([['clockStoppedByStatus' => false]], $this->patches);
	}//end testComingBackStartsTheClockAgain()

	/**
	 * A term that declares nothing runs everywhere, which is how every term
	 * behaved before this was declarable.
	 *
	 * @return void
	 */
	public function testATermThatDeclaresNoStatusesRunsEverywhere(): void {
		$this->given(instance: ['id' => 'term-1', 'status' => 'lopend'], runsIn: []);
		$this->timers->expects($this->never())->method('suspendBeslistermijn');

		$this->assertSame(
			TermStatusClock::UNCHANGED,
			$this->clock()->reconcile(caseId: 'case-1', statusId: 'bij-adviseur', caseType: 'vergunning')
		);
	}//end testATermThatDeclaresNoStatusesRunsEverywhere()

	/**
	 * 🔴 An opschorting is a different suspension. A case leaving an adviser's
	 * status must not restart a clock a hersteltermijn stopped, which is why
	 * the marker is read and not the term's own paused state.
	 *
	 * @return void
	 */
	public function testAnOpschortingIsNotResumedByComingBack(): void {
		$this->given(
			instance: ['id' => 'term-1', 'status' => 'paused', 'clockStoppedByStatus' => false],
			runsIn: ['in-behandeling']
		);
		$this->timers->expects($this->never())->method('resumeBeslistermijn');

		$this->assertSame(
			TermStatusClock::UNCHANGED,
			$this->clock()->reconcile(caseId: 'case-1', statusId: 'in-behandeling', caseType: 'vergunning')
		);
		$this->assertSame([], $this->patches);
	}//end testAnOpschortingIsNotResumedByComingBack()

	/**
	 * A term already paused for another reason is not paused a second time.
	 *
	 * @return void
	 */
	public function testAnAlreadyPausedTermIsNotStoppedAgain(): void {
		$this->given(instance: ['id' => 'term-1', 'status' => 'paused'], runsIn: ['in-behandeling']);
		$this->timers->expects($this->never())->method('suspendBeslistermijn');

		$this->assertSame(
			TermStatusClock::UNCHANGED,
			$this->clock()->reconcile(caseId: 'case-1', statusId: 'bij-adviseur', caseType: 'vergunning')
		);
	}//end testAnAlreadyPausedTermIsNotStoppedAgain()

	/**
	 * Staying inside the running statuses does nothing at all, so a save that
	 * changed something else does not touch the clock.
	 *
	 * @return void
	 */
	public function testStayingInsideTheRunningStatusesChangesNothing(): void {
		$this->given(instance: ['id' => 'term-1', 'status' => 'lopend'], runsIn: ['in-behandeling']);
		$this->timers->expects($this->never())->method('suspendBeslistermijn');
		$this->timers->expects($this->never())->method('resumeBeslistermijn');

		$this->assertSame(
			TermStatusClock::UNCHANGED,
			$this->clock()->reconcile(caseId: 'case-1', statusId: 'in-behandeling', caseType: 'vergunning')
		);
	}//end testStayingInsideTheRunningStatusesChangesNothing()

	/**
	 * A case with no term has no clock to reconcile.
	 *
	 * @return void
	 */
	public function testACaseWithNoTermIsLeftAlone(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturn(null);
		$this->timers->expects($this->never())->method('suspendBeslistermijn');

		$this->assertSame(
			TermStatusClock::UNCHANGED,
			$this->clock()->reconcile(caseId: 'case-1', statusId: 'bij-adviseur', caseType: 'vergunning')
		);
	}//end testACaseWithNoTermIsLeftAlone()

	/**
	 * One instance and one declaration.
	 *
	 * @param array<string, mixed> $instance The term instance.
	 * @param array<int, string>   $runsIn   The declared running statuses.
	 *
	 * @return void
	 */
	private function given(array $instance, array $runsIn): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturn($instance);
		$this->terms->method('getTermijnDefinitie')->willReturn(['runsInStatuses' => $runsIn]);
	}//end given()

	/**
	 * The clock over the doubles.
	 *
	 * @return TermStatusClock The service under test.
	 */
	private function clock(): TermStatusClock {
		return new TermStatusClock(terms: $this->terms, timers: $this->timers, logger: new NullLogger());
	}//end clock()
}//end class
