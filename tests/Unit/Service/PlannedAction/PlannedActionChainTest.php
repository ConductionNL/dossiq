<?php

/**
 * A planned next action is typed, owned and chained (gap register row 3.28).
 *
 * Before this, a case answered "what happens next" only by being read from the
 * top. A reminder fires at a date and chains nothing; this is the record that
 * says what the work is, who does it, and what follows when it is done.
 *
 * Every test here is a way the chain could be wrong while producing a
 * plausible-looking plan:
 *
 *  - the whole chain created up front, so the plan is a list of future work
 *    that is wrong the moment one step is skipped and that somebody reads and
 *    believes;
 *  - a cancelled action planning its successor, which makes cancelling
 *    indistinguishable from completing;
 *  - an owner guessed from whoever completed the previous step, which reads
 *    exactly like a decision;
 *  - a successor naming a type that does not exist, planned anyway as an
 *    action no list can label;
 *  - a cycle in the successor declarations, which does not hang anything and
 *    therefore nobody notices: it simply plans forever, one completion at a
 *    time.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\PlannedAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\PlannedAction;

use DateTimeImmutable;
use OCA\Dossiq\Service\PlannedAction\PlannedActionChain;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the planned action chain.
 *
 * @covers \OCA\Dossiq\Service\PlannedAction\PlannedActionChain
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 */
class PlannedActionChainTest extends TestCase {

	private PlannedActionChain $chain;

	private WorkingDayCalculator $workingDays;

	/**
	 * Set up the chain over the real working-day calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->workingDays = new WorkingDayCalculator();
		$this->chain = new PlannedActionChain(workingDays: $this->workingDays);
	}//end setUp()

	/**
	 * The three-step chain the requirement describes.
	 *
	 * @return array<string, array<string, mixed>> The types, by identifier.
	 */
	private function types(): array {
		return [
			'confirm' => [
				'identifier' => 'confirm',
				'label' => 'Send the confirmation',
				'successor' => 'follow-up-call',
				'successorOffsetWorkingDays' => 10,
				'ownerRole' => 'behandelaar',
			],
			'follow-up-call' => [
				'identifier' => 'follow-up-call',
				'label' => 'Call after ten days',
				'successor' => 'close',
				'successorOffsetWorkingDays' => 5,
				'ownerRole' => 'behandelaar',
			],
			'close' => [
				'identifier' => 'close',
				'label' => 'Close the case',
				'successor' => '',
				'ownerRole' => 'behandelaar',
			],
		];
	}//end types()

	/**
	 * Completing one action plans the one its type declares.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testCompletingOneActionPlansTheNext(): void {
		$next = $this->chain->next(
			completed: [
				'id' => 'action-1',
				'case' => 'case-1',
				'actionType' => 'confirm',
				'state' => 'planned',
			],
			types: $this->types(),
			completedOn: new DateTimeImmutable('2026-03-02'),
			roleHolders: ['behandelaar' => 'alice']
		);

		$this->assertNotNull($next);
		$this->assertSame('follow-up-call', $next['actionType']);
		$this->assertSame('Call after ten days', $next['label']);
		$this->assertSame('alice', $next['owner']);
		$this->assertSame('case-1', $next['case']);
		$this->assertSame('planned', $next['state']);
		$this->assertSame('action-1', $next['plannedFrom']);
		$this->assertSame(
			$this->workingDays
				->addWorkingDays(start: new DateTimeImmutable('2026-03-02'), days: 10)
				->format('Y-m-d'),
			$next['plannedFor'],
			'ten WORKING days, not ten calendar days'
		);
	}//end testCompletingOneActionPlansTheNext()

	/**
	 * 🔴 One step is planned, never the whole chain.
	 *
	 * D-6. The answer to "what happens next" is one action, and a chain
	 * created in advance is a list of future work that is wrong as soon as
	 * anything is skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testOnlyOneStepIsPlanned(): void {
		$next = $this->chain->next(
			completed: ['id' => 'a1', 'case' => 'c1', 'actionType' => 'confirm'],
			types: $this->types(),
			completedOn: new DateTimeImmutable('2026-03-02')
		);

		// `next()` answers ONE action and not a list, which is what makes
		// planning the whole chain impossible rather than merely unwise.
		$this->assertIsArray($next);
		$this->assertArrayNotHasKey(0, $next);
		$this->assertSame('follow-up-call', $next['actionType']);
		$this->assertArrayNotHasKey('successor', $next);
	}//end testOnlyOneStepIsPlanned()

	/**
	 * A type declaring no successor ends the chain.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testAChainEndsWhereTheTypeSaysSo(): void {
		$this->assertNull(
			$this->chain->next(
				completed: ['id' => 'a3', 'case' => 'c1', 'actionType' => 'close'],
				types: $this->types(),
				completedOn: new DateTimeImmutable('2026-03-02')
			)
		);
	}//end testAChainEndsWhereTheTypeSaysSo()

	/**
	 * A cancelled action plans nothing.
	 *
	 * The difference between cancelling and completing, and the reason the
	 * state is an enum rather than a boolean.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testACancelledActionPlansNothing(): void {
		$this->assertNull(
			$this->chain->next(
				completed: [
					'id' => 'a1',
					'case' => 'c1',
					'actionType' => 'confirm',
					'state' => 'cancelled',
				],
				types: $this->types(),
				completedOn: new DateTimeImmutable('2026-03-02')
			)
		);
	}//end testACancelledActionPlansNothing()

	/**
	 * A successor naming a type that does not exist plans nothing.
	 *
	 * Planning it anyway would create an action of an unknown type: no label,
	 * no owner rule, and no successor, sitting on the case as the answer to
	 * what happens next.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testABrokenSuccessorDeclarationPlansNothing(): void {
		$this->assertNull(
			$this->chain->next(
				completed: ['id' => 'a1', 'case' => 'c1', 'actionType' => 'confirm'],
				types: [
					'confirm' => [
						'identifier' => 'confirm',
						'successor' => 'a-type-somebody-deleted',
					],
				],
				completedOn: new DateTimeImmutable('2026-03-02')
			)
		);
	}//end testABrokenSuccessorDeclarationPlansNothing()

	/**
	 * An action whose own type is gone plans nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testAnActionOfADeletedTypePlansNothing(): void {
		$this->assertNull(
			$this->chain->next(
				completed: ['id' => 'a1', 'case' => 'c1', 'actionType' => 'gone'],
				types: $this->types(),
				completedOn: new DateTimeImmutable('2026-03-02')
			)
		);
	}//end testAnActionOfADeletedTypePlansNothing()

	/**
	 * 🔴 An owner is resolved from a role, or there is none.
	 *
	 * Falling back to whoever completed the previous step would put a name on
	 * the plan that nobody chose and that nobody can tell was not chosen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testAnOwnerIsNeverGuessed(): void {
		$types = $this->types();
		unset($types['follow-up-call']['ownerRole']);

		$next = $this->chain->next(
			completed: [
				'id' => 'a1',
				'case' => 'c1',
				'actionType' => 'confirm',
				'completedBy' => 'alice',
			],
			types: $types,
			completedOn: new DateTimeImmutable('2026-03-02'),
			roleHolders: ['behandelaar' => 'alice']
		);

		$this->assertSame('', $next['owner']);
	}//end testAnOwnerIsNeverGuessed()

	/**
	 * A role nobody holds on this case leaves the action unowned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testARoleNobodyHoldsLeavesTheActionUnowned(): void {
		$next = $this->chain->next(
			completed: ['id' => 'a1', 'case' => 'c1', 'actionType' => 'confirm'],
			types: $this->types(),
			completedOn: new DateTimeImmutable('2026-03-02'),
			roleHolders: []
		);

		$this->assertSame('', $next['owner']);
	}//end testARoleNobodyHoldsLeavesTheActionUnowned()

	/**
	 * The label is copied onto the action, so a rename does not rewrite history.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testTheLabelIsCopiedAtPlanningTime(): void {
		$next = $this->chain->next(
			completed: ['id' => 'a1', 'case' => 'c1', 'actionType' => 'confirm'],
			types: $this->types(),
			completedOn: new DateTimeImmutable('2026-03-02')
		);

		$this->assertSame('Call after ten days', $next['label']);
	}//end testTheLabelIsCopiedAtPlanningTime()

	/**
	 * A loop in the successor declarations is reported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testALoopInTheSuccessorsIsReported(): void {
		$loop = $this->chain->isCyclic(
			[
				'a' => ['identifier' => 'a', 'successor' => 'b'],
				'b' => ['identifier' => 'b', 'successor' => 'a'],
			]
		);

		sort($loop);
		$this->assertSame(['a', 'b'], $loop);
	}//end testALoopInTheSuccessorsIsReported()

	/**
	 * A chain that ends is not reported as a loop.
	 *
	 * The control. A `isCyclic()` that answered every identifier it walked
	 * would satisfy the test above and refuse every legitimate chain.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testAChainThatEndsIsNotALoop(): void {
		$this->assertSame([], $this->chain->isCyclic($this->types()));
	}//end testAChainThatEndsIsNotALoop()
}//end class
