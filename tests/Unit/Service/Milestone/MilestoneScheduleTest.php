<?php

/**
 * The milestone timeline reads `dependsOn` (gap register row 3.27).
 *
 * `milestoneDefinition.dependsOn` had been declared for as long as the schema
 * existed and NOTHING READ IT, which is the failure shape this file is written
 * against: a field an administrator fills in, a form that accepts it, and no
 * behaviour anywhere. Every test here is a way the reading could be absent or
 * wrong while the timeline still renders a plausible set of dates:
 *
 *  - the offset counted from the case start instead of from the predecessor,
 *    which is right exactly once, on the day the case opens;
 *  - a predecessor that has actually been REACHED being projected over, so the
 *    timeline disagrees with the record of what happened;
 *  - the first of several predecessors winning, which dates a milestone before
 *    work it is declared to wait on;
 *  - a cycle resolving to a date instead of being refused, which is either a
 *    loop or a date that silently never arrives;
 *  - an item with no predecessor changing behaviour, which would rewrite every
 *    existing case type on the day this shipped.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Milestone
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Milestone;

use DateTimeImmutable;
use OCA\Dossiq\Service\Milestone\MilestoneSchedule;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the milestone timeline.
 *
 * @covers \OCA\Dossiq\Service\Milestone\MilestoneSchedule
 */
class MilestoneScheduleTest extends TestCase {

	private MilestoneSchedule $schedule;

	private WorkingDayCalculator $workingDays;

	/**
	 * Set up the schedule over the real working-day calculator.
	 *
	 * The calculator is REAL and not a double, deliberately. Half of what this
	 * class does is working-day arithmetic, and a double that adds calendar
	 * days would make every assertion below pass against a projection nobody
	 * would recognise.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->workingDays = new WorkingDayCalculator();
		$this->schedule = new MilestoneSchedule(workingDays: $this->workingDays);
	}//end setUp()

	/**
	 * One definition.
	 *
	 * @param string $identifier The identifier.
	 * @param int $order Its place in the sequence.
	 * @param int $days Working days it takes.
	 * @param string[] $dependsOn The identifiers it waits on.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private function definition(string $identifier, int $order, int $days, array $dependsOn = []): array {
		return [
			'identifier' => $identifier,
			'label' => ucfirst($identifier),
			'order' => $order,
			'expectedDurationWorkingDays' => $days,
			'dependsOn' => $dependsOn,
		];
	}//end definition()

	/**
	 * The date `days` working days after `from`.
	 *
	 * @param string $from The start date.
	 * @param int $days The working days.
	 *
	 * @return string The date as Y-m-d.
	 */
	private function after(string $from, int $days): string {
		return $this->workingDays
			->addWorkingDays(start: new DateTimeImmutable($from), days: $days)
			->format('Y-m-d');
	}//end after()

	/**
	 * A milestone naming a predecessor is dated from it, not from the start.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testAMilestoneIsOffsetFromItsPredecessor(): void {
		$start = new DateTimeImmutable('2026-03-02');
		$dates = $this->schedule->project(
			definitions: [
				$this->definition('hearing', 1, 20),
				$this->definition('decision', 2, 10, ['hearing']),
			],
			caseStart: $start
		);

		$hearing = $this->after('2026-03-02', 20);
		$this->assertSame($hearing, $dates['hearing']->format('Y-m-d'));
		$this->assertSame($this->after($hearing, 10), $dates['decision']->format('Y-m-d'));
	}//end testAMilestoneIsOffsetFromItsPredecessor()

	/**
	 * 🔴 The predecessor moves and everything downstream moves with it.
	 *
	 * The assertion the whole row turns on. Without it the decision would be
	 * ten days after an ESTIMATE of the hearing, and the estimate would never
	 * be corrected by the hearing actually happening.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testMovingThePredecessorMovesEverythingDownstream(): void {
		$definitions = [
			$this->definition('hearing', 1, 20),
			$this->definition('decision', 2, 10, ['hearing']),
			$this->definition('publication', 3, 5, ['decision']),
		];
		$start = new DateTimeImmutable('2026-03-02');

		$projected = $this->schedule->project(definitions: $definitions, caseStart: $start);

		// The hearing happened nine working days later than projected.
		$actualHearing = $this->workingDays->addWorkingDays(
			start: $projected['hearing'],
			days: 9
		);
		$moved = $this->schedule->project(
			definitions: $definitions,
			caseStart: $start,
			reached: ['hearing' => $actualHearing]
		);

		$this->assertSame(
			$actualHearing->format('Y-m-d'),
			$moved['hearing']->format('Y-m-d'),
			'a reached milestone IS its date'
		);
		$this->assertSame(
			$this->after($actualHearing->format('Y-m-d'), 10),
			$moved['decision']->format('Y-m-d')
		);
		$this->assertSame(
			$this->after($this->after($actualHearing->format('Y-m-d'), 10), 5),
			$moved['publication']->format('Y-m-d'),
			'the item two links downstream moves too'
		);
		$this->assertNotSame(
			$projected['publication']->format('Y-m-d'),
			$moved['publication']->format('Y-m-d')
		);
	}//end testMovingThePredecessorMovesEverythingDownstream()

	/**
	 * An item naming no predecessor keeps counting from the case start.
	 *
	 * D-2: nothing is rewritten on the day this ships.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testAnItemWithNoPredecessorIsCumulativeFromTheStart(): void {
		$dates = $this->schedule->project(
			definitions: [
				$this->definition('received', 1, 2),
				$this->definition('complete', 2, 5),
				$this->definition('assessed', 3, 10),
			],
			caseStart: new DateTimeImmutable('2026-03-02')
		);

		$this->assertSame($this->after('2026-03-02', 2), $dates['received']->format('Y-m-d'));
		$this->assertSame($this->after('2026-03-02', 7), $dates['complete']->format('Y-m-d'));
		$this->assertSame($this->after('2026-03-02', 17), $dates['assessed']->format('Y-m-d'));
	}//end testAnItemWithNoPredecessorIsCumulativeFromTheStart()

	/**
	 * A dependent item does not push the independent ones along.
	 *
	 * The running total is over the predecessor-less items only. Counting a
	 * dependent item into it would move every later independent milestone by
	 * work hanging off a different branch entirely, which looks like a
	 * plausible timeline and is not one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testABranchDoesNotPushTheTrunk(): void {
		$dates = $this->schedule->project(
			definitions: [
				$this->definition('received', 1, 2),
				$this->definition('siteVisit', 2, 30, ['received']),
				$this->definition('assessed', 3, 5),
			],
			caseStart: new DateTimeImmutable('2026-03-02')
		);

		$this->assertSame(
			$this->after('2026-03-02', 7),
			$dates['assessed']->format('Y-m-d'),
			'assessed is 2 + 5 from the start, untouched by the 30-day branch'
		);
	}//end testABranchDoesNotPushTheTrunk()

	/**
	 * With several predecessors, the LATEST one is what it waits for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testTheLatestPredecessorWins(): void {
		$dates = $this->schedule->project(
			definitions: [
				$this->definition('advice', 1, 5),
				$this->definition('inspection', 2, 25),
				$this->definition('decision', 3, 3, ['advice', 'inspection']),
			],
			caseStart: new DateTimeImmutable('2026-03-02')
		);

		$this->assertSame(
			$this->after($dates['inspection']->format('Y-m-d'), 3),
			$dates['decision']->format('Y-m-d')
		);
		$this->assertGreaterThan($dates['advice'], $dates['decision']);
	}//end testTheLatestPredecessorWins()

	/**
	 * `dependsOn` arriving as a JSON string is still read.
	 *
	 * OpenRegister answers an array property as a JSON string on some read
	 * paths, and a reader that does not decode it sees no predecessors at all
	 * and falls back to the old behaviour without a word.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testDependsOnEncodedAsJsonIsRead(): void {
		$dates = $this->schedule->project(
			definitions: [
				$this->definition('hearing', 1, 20),
				[
					'identifier' => 'decision',
					'order' => 2,
					'expectedDurationWorkingDays' => 10,
					'dependsOn' => '["hearing"]',
				],
			],
			caseStart: new DateTimeImmutable('2026-03-02')
		);

		$this->assertSame(
			$this->after($dates['hearing']->format('Y-m-d'), 10),
			$dates['decision']->format('Y-m-d')
		);
	}//end testDependsOnEncodedAsJsonIsRead()

	/**
	 * A cycle is found and its items are named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testACycleIsFoundAndNamed(): void {
		$cycle = $this->schedule->cycle(
			[
				$this->definition('a', 1, 1, ['c']),
				$this->definition('b', 2, 1, ['a']),
				$this->definition('c', 3, 1, ['b']),
			]
		);

		sort($cycle);
		$this->assertSame(['a', 'b', 'c'], $cycle);
	}//end testACycleIsFoundAndNamed()

	/**
	 * A milestone depending on itself is a cycle too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testASelfDependencyIsACycle(): void {
		$cycle = $this->schedule->cycle([$this->definition('a', 1, 1, ['a'])]);

		$this->assertSame(['a'], $cycle);
	}//end testASelfDependencyIsACycle()

	/**
	 * A legitimate chain is not reported as a cycle.
	 *
	 * The control. Without it a `cycle()` that returned every identifier it saw
	 * would satisfy the two tests above and refuse every case type on save.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testAChainAndADiamondAreNotCycles(): void {
		$this->assertSame(
			[],
			$this->schedule->cycle(
				[
					$this->definition('received', 1, 2),
					$this->definition('advice', 2, 5, ['received']),
					$this->definition('inspection', 3, 5, ['received']),
					$this->definition('decision', 4, 3, ['advice', 'inspection']),
				]
			)
		);
	}//end testAChainAndADiamondAreNotCycles()

	/**
	 * A cycle that survived into the data still terminates a projection.
	 *
	 * The cycle is refused at authoring time, but a definition set imported
	 * before that check existed can carry one, and a projection that recursed
	 * forever would be a request that never returns.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testAProjectionOverACycleTerminates(): void {
		$dates = $this->schedule->project(
			definitions: [
				$this->definition('a', 1, 1, ['b']),
				$this->definition('b', 2, 1, ['a']),
			],
			caseStart: new DateTimeImmutable('2026-03-02')
		);

		$this->assertCount(2, $dates);
	}//end testAProjectionOverACycleTerminates()

	/**
	 * A predecessor that is not in this case type falls back to the start.
	 *
	 * Dropping the milestone off the timeline instead would hide a definition
	 * an administrator is looking at.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testAMissingPredecessorFallsBackToTheCaseStart(): void {
		$dates = $this->schedule->project(
			definitions: [$this->definition('decision', 1, 4, ['a-type-that-was-deleted'])],
			caseStart: new DateTimeImmutable('2026-03-02')
		);

		$this->assertSame($this->after('2026-03-02', 4), $dates['decision']->format('Y-m-d'));
	}//end testAMissingPredecessorFallsBackToTheCaseStart()

	/**
	 * The owner is resolved from the role, on this case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testTheOwnerIsResolvedFromTheRole(): void {
		$definition = $this->definition('decision', 1, 4);
		$definition['ownerRole'] = 'vergunningverlener';

		$this->assertSame(
			'alice',
			$this->schedule->ownerOf(
				definition: $definition,
				case: ['assignee' => 'bob'],
				roleHolders: ['vergunningverlener' => 'alice']
			)
		);
	}//end testTheOwnerIsResolvedFromTheRole()

	/**
	 * The owner follows a change of handler, because it is never stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testTheOwnerFollowsAChangeOfHandler(): void {
		$definition = $this->definition('decision', 1, 4);
		$definition['ownerRole'] = 'assignee';

		$this->assertSame(
			'bob',
			$this->schedule->ownerOf(definition: $definition, case: ['assignee' => 'bob'])
		);
		$this->assertSame(
			'carol',
			$this->schedule->ownerOf(definition: $definition, case: ['assignee' => 'carol'])
		);
	}//end testTheOwnerFollowsAChangeOfHandler()

	/**
	 * 🔴 A milestone naming no role has NO owner, not a guessed one.
	 *
	 * The case handler is standing right there and would be the obvious thing
	 * to fall back to. A guessed owner reads exactly like a decision, and
	 * nobody looking at the timeline can tell the two apart.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testAMilestoneWithNoRoleHasNoOwner(): void {
		$this->assertSame(
			'',
			$this->schedule->ownerOf(
				definition: $this->definition('decision', 1, 4),
				case: ['assignee' => 'bob'],
				roleHolders: ['vergunningverlener' => 'alice']
			)
		);
	}//end testAMilestoneWithNoRoleHasNoOwner()

	/**
	 * A role nobody holds on this case leaves the milestone unowned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function testARoleNobodyHoldsLeavesItUnowned(): void {
		$definition = $this->definition('decision', 1, 4);
		$definition['ownerRole'] = 'juridisch-adviseur';

		$this->assertSame(
			'',
			$this->schedule->ownerOf(
				definition: $definition,
				case: ['assignee' => 'bob'],
				roleHolders: ['vergunningverlener' => 'alice']
			)
		);
	}//end testARoleNobodyHoldsLeavesItUnowned()
}//end class
