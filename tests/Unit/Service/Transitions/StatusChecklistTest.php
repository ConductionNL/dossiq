<?php

/**
 * StatusChecklist Unit Tests
 *
 * The reader that turns `statusType.checklist` into `createTask` actions. Two
 * things here can only be asserted in a unit test: that an empty list creates
 * nothing (a browser cannot tell nothing from not yet), and that a second
 * entry into the same status adds no second copy of the work.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\Transitions\StatusChecklist;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Transitions\StatusChecklist
 */
class StatusChecklistTest extends TestCase {
	/**
	 * The two items of a status become two createTask actions.
	 *
	 * 🔴 EACH ONE NAMES ITS ASSIGNEE. A checklist action used to name nobody,
	 * and `CreateTaskHandler` wrote that nobody straight onto the task as an
	 * empty string. The task schema addresses its `taskAssigned` notification
	 * to that field, so every checklist task appeared with nobody told about
	 * it. The spelling is the one the shipped flow declarations use, so both
	 * paths ask `AssigneeResolver` the same question.
	 *
	 * @return void
	 */
	public function testTwoItemsYieldTwoActions(): void {
		$checklist = $this->build(
			checklist: [
				['title' => 'Check the objection is on time', 'required' => true],
				['title' => 'Confirm receipt to the objector'],
			],
			tasks: [],
		);

		$actions = $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1']);

		self::assertSame(
			[
				[
					'type' => 'createTask',
					'title' => 'Check the objection is on time',
					'workflowStepId' => 'st-1',
					'assignee' => '{{ case.assignee }}',
				],
				[
					'type' => 'createTask',
					'title' => 'Confirm receipt to the objector',
					'workflowStepId' => 'st-1',
					'assignee' => '{{ case.assignee }}',
				],
			],
			$actions
		);
	}//end testTwoItemsYieldTwoActions()

	/**
	 * An item whose task is already on the case is skipped.
	 *
	 * Completed counts as much as open: a case sent back to intake and forward
	 * again keeps the work it did, and gets no second copy of it.
	 *
	 * @return void
	 */
	public function testAnExistingTaskByTitleSkipsItsItem(): void {
		$checklist = $this->build(
			checklist: [
				['title' => 'Check the objection is on time', 'required' => true],
				['title' => 'Confirm receipt to the objector'],
			],
			tasks: [
				['title' => 'Check the objection is on time', 'status' => 'completed'],
				['title' => 'Confirm receipt to the objector', 'status' => 'available'],
			],
		);

		self::assertSame([], $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1']));
	}//end testAnExistingTaskByTitleSkipsItsItem()

	/**
	 * Only the items without a task are created on a partial re-entry.
	 *
	 * @return void
	 */
	public function testOnlyTheMissingItemIsCreatedAgain(): void {
		$checklist = $this->build(
			checklist: [
				['title' => 'Check the objection is on time', 'required' => true],
				['title' => 'Confirm receipt to the objector'],
			],
			tasks: [['title' => 'Check the objection is on time', 'status' => 'completed']],
		);

		$actions = $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1']);

		self::assertCount(1, $actions);
		self::assertSame('Confirm receipt to the objector', $actions[0]['title']);
	}//end testOnlyTheMissingItemIsCreatedAgain()

	/**
	 * A status with no list, and one with an empty list, create nothing.
	 *
	 * @return void
	 */
	public function testAnAbsentOrEmptyListYieldsNothing(): void {
		self::assertSame(
			[],
			$this->build(checklist: null, tasks: [])->actionsFor(statusTypeId: 'st-1', case: ['id' => 'c'])
		);
		self::assertSame(
			[],
			$this->build(checklist: [], tasks: [])->actionsFor(statusTypeId: 'st-1', case: ['id' => 'c'])
		);
	}//end testAnAbsentOrEmptyListYieldsNothing()

	/**
	 * A list that came back as JSON is still a list.
	 *
	 * @return void
	 */
	public function testAJsonEncodedListIsDecoded(): void {
		$checklist = $this->build(
			checklist: json_encode([['title' => 'Assemble the case file', 'required' => true]]),
			tasks: [],
		);

		$items = $checklist->itemsFor(statusTypeId: 'st-1');

		self::assertSame([['title' => 'Assemble the case file', 'required' => true]], $items);
	}//end testAJsonEncodedListIsDecoded()

	/**
	 * An item without a title names no task, so it is dropped.
	 *
	 * A required item with no title could never be completed, so keeping it
	 * would hold every case in the status on nothing.
	 *
	 * @return void
	 */
	public function testAnItemWithoutATitleIsDropped(): void {
		$checklist = $this->build(
			checklist: [['required' => true], ['title' => '   '], ['title' => 'Real item']],
			tasks: [],
		);

		self::assertSame([['title' => 'Real item', 'required' => false]], $checklist->itemsFor(statusTypeId: 'st-1'));
	}//end testAnItemWithoutATitleIsDropped()

	/**
	 * `required` defaults to false, and is read as a boolean.
	 *
	 * @return void
	 */
	public function testRequiredDefaultsToFalse(): void {
		$checklist = $this->build(checklist: [['title' => 'Confirm receipt to the objector']], tasks: []);

		self::assertFalse($checklist->itemsFor(statusTypeId: 'st-1')[0]['required']);
	}//end testRequiredDefaultsToFalse()

	/**
	 * A case with no id gets no tasks read for it, and no crash.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdReadsNoTasks(): void {
		$checklist = $this->build(checklist: [['title' => 'Assemble the case file']], tasks: [['title' => 'Assemble the case file']]);

		// No case id means no task search is possible, so the item is offered
		// rather than silently skipped by a search that matched nothing.
		$actions = $checklist->actionsFor(statusTypeId: 'st-1', case: []);

		self::assertCount(1, $actions);
	}//end testACaseWithoutAnIdReadsNoTasks()

	/**
	 * An engine read that could not answer is read as no tasks.
	 *
	 * The item is offered again rather than silently skipped. That is the safe
	 * direction of the design's trade-off — a duplicate task is cheaper than a
	 * missed one — and it is the reason the failure is logged: an empty list
	 * and an unanswerable read look identical from here.
	 *
	 * @return void
	 */
	public function testAnEngineReadThatFailedYieldsNoTasks(): void {
		$checklist = $this->build(
			checklist: [['title' => 'Assemble the case file']],
			tasks: [],
			failure: 'the task engine is not available',
		);

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1']));
		self::assertCount(1, $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1']));
	}//end testAnEngineReadThatFailedYieldsNoTasks()

	/**
	 * A task another status put on the same case is not this status's.
	 *
	 * 🔴 THE SCOPING IS THE WHOLE POINT OF THE FILTER. The engine has no
	 * per-status predicate, so `forCase()` answers every task the case holds
	 * and `tasksFor()` narrows them. Without that narrowing another status's
	 * task would satisfy this status's item: the checklist would skip work
	 * that was never done, and the guard would open on it.
	 *
	 * @return void
	 */
	public function testATaskFromAnotherStatusIsNotRead(): void {
		$checklist = $this->build(
			checklist: [['title' => 'Assemble the case file']],
			tasks: [
				['title' => 'Assemble the case file', 'status' => 'completed', 'workflowStepId' => 'st-2'],
			],
		);

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1']));

		// And because it was not read, the item is still offered.
		self::assertCount(1, $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1']));
	}//end testATaskFromAnotherStatusIsNotRead()

	/**
	 * The case's own id is what the engine is asked for.
	 *
	 * `forCase()` anchors on the object uuid, so a checklist read on the wrong
	 * case is a read of somebody else's work. Asserted rather than trusted.
	 *
	 * @return void
	 */
	public function testTheEngineIsAskedForThisCase(): void {
		$inbox = $this->createMock(EngineTaskInbox::class);
		$inbox->method('lastError')->willReturn('');
		$inbox->expects($this->once())
			->method('forCase')
			->with('case-42', 'alice')
			->willReturn([]);

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn(['checklist' => [['title' => 'Assemble the case file']]]);

		$checklist = new StatusChecklist($inbox, $lookup, new NullLogger());

		self::assertSame(
			[],
			$checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-42'], actor: 'alice')
		);
	}//end testTheEngineIsAskedForThisCase()

	/**
	 * With no actor named, the engine is read as `admin`.
	 *
	 * The engine is fail-closed on a blank identity, so a read with no actor
	 * would answer nothing and every checklist would duplicate itself. Every
	 * caller passes one; this is what happens when a future one forgets.
	 *
	 * @return void
	 */
	public function testABlankActorReadsAsAdmin(): void {
		$inbox = $this->createMock(EngineTaskInbox::class);
		$inbox->method('lastError')->willReturn('');
		$inbox->expects($this->once())
			->method('forCase')
			->with('case-1', 'admin')
			->willReturn([]);

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn(['checklist' => [['title' => 'Assemble the case file']]]);

		$checklist = new StatusChecklist($inbox, $lookup, new NullLogger());

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1']));
	}//end testABlankActorReadsAsAdmin()

	/**
	 * Build a StatusChecklist over a fixed status row and task list.
	 *
	 * The tasks are what the ENGINE holds for the case, which is the store
	 * `CreateTaskHandler` writes to since dossiq#2363. A fixture that names no
	 * `workflowStepId` is stamped with the status under test, because that is
	 * what a task this status created carries and it keeps each case above
	 * about the thing it is actually asserting.
	 *
	 * @param mixed                            $checklist The status's checklist value, as stored.
	 * @param array<int, array<string, mixed>> $tasks     The tasks the engine holds for the case.
	 * @param string                           $failure   The engine's reason, when the read could not be made.
	 *
	 * @return StatusChecklist The reader under test.
	 */
	private function build(mixed $checklist, array $tasks, string $failure = ''): StatusChecklist {
		$rows = [];
		if ($failure === '') {
			foreach ($tasks as $task) {
				if (isset($task['workflowStepId']) === false) {
					$task['workflowStepId'] = 'st-1';
				}

				$rows[] = $task;
			}
		}

		$inbox = $this->createMock(EngineTaskInbox::class);
		$inbox->method('forCase')->willReturn($rows);
		$inbox->method('lastError')->willReturn($failure);

		$row = [];
		if ($checklist !== null) {
			$row['checklist'] = $checklist;
		}

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn($row);

		return new StatusChecklist($inbox, $lookup, new NullLogger());
	}//end build()
}//end class
