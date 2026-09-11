<?php

/**
 * StatusChecklist Unit Tests
 *
 * The reader that turns `statusType.checklist` into `createTask` actions. Two
 * things here can only be asserted in a unit test: that an empty list creates
 * nothing (a browser cannot tell nothing from not yet), and that a second
 * entry into the same status adds no second copy of the work.
 *
 * 🔴 THE STORE MOVED, AND THE FIXTURES HAD TO MOVE WITH IT. These tests used
 * to hand `StatusChecklist` a fake OpenRegister `ObjectService` and assert it
 * searched the `caseTask` schema. That is no longer where a task lives:
 * `CreateTaskHandler` writes the task ENGINE and only the engine (#2363), so a
 * suite that keeps proving the register search works proves something no
 * running instance does. The fixtures are engine inbox rows now, and the ones
 * that pinned the register read say below what replaced them.
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

		$actions = $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: 'jan');

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
	 * 🔴 THIS IS THE ASSERTION THE MIGRATION BROKE. The tasks below are engine
	 * rows, which is where `CreateTaskHandler` has written them since #2363.
	 * While `tasksFor()` still searched the register it found none of them and
	 * every re-entry into a status recreated the entire checklist.
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
				$this->task(title: 'Check the objection is on time', status: 'completed'),
				$this->task(title: 'Confirm receipt to the objector', status: 'available'),
			],
		);

		self::assertSame([], $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: 'jan'));
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
			tasks: [$this->task(title: 'Check the objection is on time', status: 'completed')],
		);

		$actions = $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: 'jan');

		self::assertCount(1, $actions);
		self::assertSame('Confirm receipt to the objector', $actions[0]['title']);
	}//end testOnlyTheMissingItemIsCreatedAgain()

	/**
	 * A task another status put on the case is not this status's task.
	 *
	 * 🔑 THE NARROWING IS DONE HERE, SO IT HAS TO BE ASSERTED HERE.
	 * `TaskInboxCriteria` carries no `workflowStepId` argument, so the engine
	 * hands back everything on the case and `tasksFor()` filters. A filter
	 * that let another phase's task through would skip an item this phase has
	 * never done, and the guard would then read that item as satisfied.
	 *
	 * @return void
	 */
	public function testATaskFromAnotherStatusDoesNotSkipTheItem(): void {
		$checklist = $this->build(
			checklist: [['title' => 'Assemble the case file', 'required' => true]],
			tasks: [
				$this->task(title: 'Assemble the case file', status: 'completed', step: 'st-other'),
			],
		);

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: 'jan'));
		self::assertCount(1, $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: 'jan'));
	}//end testATaskFromAnotherStatusDoesNotSkipTheItem()

	/**
	 * The read asks the engine for this case, as this person, wide enough.
	 *
	 * The limit is asserted because the `workflowStepId` narrowing happens
	 * after the read: a page the engine truncated is a task that reads as
	 * absent, which duplicates the work and holds the case.
	 *
	 * @return void
	 */
	public function testTheReadNamesTheCaseTheActorAndTheWidth(): void {
		$seen = [];
		$inbox = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$inbox->method('lastError')->willReturn('');
		$inbox->method('forCase')->willReturnCallback(
			static function (string $caseId, string $actor, int $limit) use (&$seen): array {
				$seen = ['case' => $caseId, 'actor' => $actor, 'limit' => $limit];

				return [];
			}
		);

		$this->checklistOver(inbox: $inbox, checklist: [['title' => 'Assemble the case file']])
			->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: 'jan');

		self::assertSame(['case' => 'case-1', 'actor' => 'jan', 'limit' => 500], $seen);
	}//end testTheReadNamesTheCaseTheActorAndTheWidth()

	/**
	 * A status with no list, and one with an empty list, create nothing.
	 *
	 * @return void
	 */
	public function testAnAbsentOrEmptyListYieldsNothing(): void {
		self::assertSame(
			[],
			$this->build(checklist: null, tasks: [])->actionsFor(statusTypeId: 'st-1', case: ['id' => 'c'], actor: 'jan')
		);
		self::assertSame(
			[],
			$this->build(checklist: [], tasks: [])->actionsFor(statusTypeId: 'st-1', case: ['id' => 'c'], actor: 'jan')
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
		$checklist = $this->build(
			checklist: [['title' => 'Assemble the case file']],
			tasks: [$this->task(title: 'Assemble the case file')],
		);

		// No case id means no task read is possible, so the item is offered
		// rather than silently skipped by a read that matched nothing.
		$actions = $checklist->actionsFor(statusTypeId: 'st-1', case: [], actor: 'jan');

		self::assertCount(1, $actions);
	}//end testACaseWithoutAnIdReadsNoTasks()

	/**
	 * An engine read that failed is read as no tasks, and logged.
	 *
	 * Replaces the test that made the register search throw. The engine seam
	 * does not throw at all: `EngineInboxQuery` catches and reports through
	 * `lastError()`, so a failure has to be asked for by name. A case with no
	 * tasks answers the same empty list.
	 *
	 * @return void
	 */
	public function testAnEngineReadThatFailedYieldsNoTasks(): void {
		$checklist = $this->build(
			checklist: [['title' => 'Assemble the case file']],
			tasks: [$this->task(title: 'Assemble the case file')],
			error: 'the task engine is not available',
		);

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: 'jan'));
		self::assertCount(1, $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: 'jan'));
	}//end testAnEngineReadThatFailedYieldsNoTasks()

	/**
	 * With no acting identity the engine is not asked at all.
	 *
	 * Replaces the test that pinned "no ObjectService means no tasks".
	 * `TaskInboxService::inbox()` answers an empty page for a blank uid and
	 * reports no failure doing it, so an identity-less path would get exactly
	 * the silent nothing this reader was fixed for.
	 *
	 * @return void
	 */
	public function testWithoutAnActorTheEngineIsNotAsked(): void {
		$inbox = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$inbox->expects($this->never())->method('forCase');

		$checklist = $this->checklistOver(inbox: $inbox, checklist: [['title' => 'Assemble the case file']]);

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: ''));
		self::assertCount(1, $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: ' '));
	}//end testWithoutAnActorTheEngineIsNotAsked()

	/**
	 * One engine inbox row, in the vocabulary `EngineTaskInbox` hands over.
	 *
	 * @param string $title  The task title.
	 * @param string $status The task state.
	 * @param string $step   The status that asked for the task.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function task(string $title, string $status = 'available', string $step = 'st-1'): array {
		return [
			'id' => 'task-' . md5($title . $step),
			'title' => $title,
			'status' => $status,
			'case' => 'case-1',
			'workflowStepId' => $step,
		];
	}//end task()

	/**
	 * Build a StatusChecklist over a fixed status row and engine task list.
	 *
	 * @param mixed                            $checklist The status's checklist value, as stored.
	 * @param array<int, array<string, mixed>> $tasks     The tasks the engine holds for the case.
	 * @param string                           $error     Why the engine read failed, or ''.
	 *
	 * @return StatusChecklist The reader under test.
	 */
	private function build(mixed $checklist, array $tasks, string $error = ''): StatusChecklist {
		$inbox = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$inbox->method('forCase')->willReturn($tasks);
		$inbox->method('lastError')->willReturn($error);

		return $this->checklistOver(inbox: $inbox, checklist: $checklist);
	}//end build()

	/**
	 * The reader, over one inbox double and one status row.
	 *
	 * @param EngineTaskInbox $inbox     The engine's task reader.
	 * @param mixed           $checklist The status's checklist value, as stored.
	 *
	 * @return StatusChecklist The reader under test.
	 */
	private function checklistOver(EngineTaskInbox $inbox, mixed $checklist): StatusChecklist {
		$row = [];
		if ($checklist !== null) {
			$row['checklist'] = $checklist;
		}

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn($row);

		return new StatusChecklist($lookup, $inbox, new NullLogger());
	}//end checklistOver()
}//end class
