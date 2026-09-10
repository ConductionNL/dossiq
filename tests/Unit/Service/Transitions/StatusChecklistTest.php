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
use RuntimeException;

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

		$actions = $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], userId: 'admin');

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
			// `workflowStepId` is on every fixture because the reader narrows
			// on it: these are THIS status's tasks, and the double also hands
			// back one belonging to another status.
			tasks: [
				['title' => 'Check the objection is on time', 'status' => 'completed', 'workflowStepId' => 'st-1'],
				['title' => 'Confirm receipt to the objector', 'status' => 'available', 'workflowStepId' => 'st-1'],
			],
		);

		self::assertSame([], $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], userId: 'admin'));
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
			tasks: [['title' => 'Check the objection is on time', 'status' => 'completed', 'workflowStepId' => 'st-1']],
		);

		$actions = $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], userId: 'admin');

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
			$this->build(checklist: null, tasks: [])->actionsFor(statusTypeId: 'st-1', case: ['id' => 'c'], userId: 'admin')
		);
		self::assertSame(
			[],
			$this->build(checklist: [], tasks: [])->actionsFor(statusTypeId: 'st-1', case: ['id' => 'c'], userId: 'admin')
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
		$actions = $checklist->actionsFor(statusTypeId: 'st-1', case: [], userId: 'admin');

		self::assertCount(1, $actions);
	}//end testACaseWithoutAnIdReadsNoTasks()

	/**
	 * A task search that throws is logged and read as no tasks.
	 *
	 * @return void
	 */
	public function testASearchThatThrowsYieldsNoTasks(): void {
		$checklist = $this->build(checklist: [['title' => 'Assemble the case file']], tasks: [], throws: true);

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], userId: 'admin'));
		self::assertCount(1, $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], userId: 'admin'));
	}//end testASearchThatThrowsYieldsNoTasks()

	/**
	 * With no engine there is nothing to read and nothing to skip.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineThereAreNoTasks(): void {
		$engineTasks = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$engineTasks->method('forCase')->willReturn([]);
		$engineTasks->method('lastError')->willReturn('the task engine is not available');

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn(['checklist' => [['title' => 'Assemble the case file']]]);

		$checklist = new StatusChecklist($engineTasks, $lookup, new NullLogger());

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], userId: 'admin'));
	}//end testWithoutTheEngineThereAreNoTasks()

	/**
	 * 🔴 THE READ AND THE WRITE NAME THE SAME STORE.
	 *
	 * `actionsFor()` emits `createTask`, `CreateTaskHandler` writes it to the
	 * engine, and this read used to query `caseTask` objects. When the write
	 * moved and the read did not, both callers broke at once and neither
	 * said so: `existingTitles()` saw nothing, so every re-entry into a
	 * status raised the whole checklist again as duplicates, and
	 * StatusChecklistGuard saw nothing completed, so a status with a
	 * required item could never be left.
	 *
	 * This asserts the read goes to the engine and nowhere else.
	 *
	 * @return void
	 */
	public function testTheChecklistReadsTheSameStoreItsActionsWriteTo(): void {
		$engineTasks = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$engineTasks->expects($this->once())
			->method('forCase')
			->with('case-1', 'admin')
			->willReturn([
				['id' => 't-1', 'title' => 'Assemble the case file', 'workflowStepId' => 'st-1'],
			]);
		$engineTasks->method('lastError')->willReturn('');

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn(['checklist' => [['title' => 'Assemble the case file']]]);

		$checklist = new StatusChecklist($engineTasks, $lookup, new NullLogger());

		// The item already stands on the case, so no action is raised again.
		self::assertSame(
			[],
			$checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], userId: 'admin'),
			'a checklist item the engine already holds must not be raised twice'
		);
	}//end testTheChecklistReadsTheSameStoreItsActionsWriteTo()

	/**
	 * Only this status's tasks count, not every task on the case.
	 *
	 * @return void
	 */
	public function testAnotherStatusTasksAreNotCounted(): void {
		$checklist = $this->build(
			checklist: [['title' => 'Assemble the case file']],
			tasks: []
		);

		// The double always adds a task on `st-other`. If the reader did not
		// narrow on `workflowStepId`, that row would come back here.
		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], userId: 'admin'));
	}//end testAnotherStatusTasksAreNotCounted()

	/**
	 * Build a StatusChecklist over a fixed status row and task list.
	 *
	 * @param mixed                            $checklist The status's checklist value, as stored.
	 * @param array<int, array<string, mixed>> $tasks     The tasks the store holds for the status.
	 * @param boolean                          $throws    Whether the task search throws.
	 *
	 * @return StatusChecklist The reader under test.
	 */
	private function build(mixed $checklist, array $tasks, bool $throws = false): StatusChecklist {
		// The ENGINE, not the object store. `forCase` answers every task on
		// the case; the `workflowStepId` narrowing is the reader's job, and
		// this double therefore hands back tasks for BOTH statuses so that
		// narrowing is actually exercised. A double that pre-filtered would
		// pass a reader that did not filter at all.
		$engineTasks = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$engineTasks->method('forCase')->willReturnCallback(
			static function (string $caseId, string $actor) use ($tasks, $throws): array {
				if ($throws === true || $caseId === '') {
					return [];
				}

				return array_merge(
					$tasks,
					[['id' => 'other-status-task', 'title' => 'Belongs to another status', 'workflowStepId' => 'st-other']]
				);
			}
		);
		$engineTasks->method('lastError')->willReturn($throws === true ? 'unreadable' : '');

		$row = [];
		if ($checklist !== null) {
			$row['checklist'] = $checklist;
		}

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn($row);

		return new StatusChecklist($engineTasks, $lookup, new NullLogger());
	}//end build()
}//end class
