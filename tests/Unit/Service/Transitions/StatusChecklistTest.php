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

use OCA\Dossiq\Service\SettingsService;
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
	 * A task search that throws is logged and read as no tasks.
	 *
	 * @return void
	 */
	public function testASearchThatThrowsYieldsNoTasks(): void {
		$checklist = $this->build(checklist: [['title' => 'Assemble the case file']], tasks: [], throws: true);

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1']));
		self::assertCount(1, $checklist->actionsFor(statusTypeId: 'st-1', case: ['id' => 'case-1']));
	}//end testASearchThatThrowsYieldsNoTasks()

	/**
	 * With no object service there is nothing to read and nothing to skip.
	 *
	 * @return void
	 */
	public function testWithoutStorageThereAreNoTasks(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn(['checklist' => [['title' => 'Assemble the case file']]]);

		$checklist = new StatusChecklist($settings, $lookup, new NullLogger());

		self::assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1']));
	}//end testWithoutStorageThereAreNoTasks()

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
		$objectService = new class($tasks, $throws) {
			/**
			 * @param array<int, array<string, mixed>> $tasks  The stored tasks.
			 * @param boolean                          $throws Whether to throw.
			 */
			public function __construct(
				private array $tasks,
				private bool $throws,
			) {
			}

			/**
			 * The tasks of one case and one status, as the store filters them.
			 *
			 * The filter is asserted here rather than trusted: a search that
			 * did not scope on `workflowStepId` would read another status's
			 * tasks and skip an item that has none of its own.
			 *
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The matching tasks.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				if ($this->throws === true) {
					throw new RuntimeException('unreadable');
				}

				if (($filters['case'] ?? '') === '' || ($filters['workflowStepId'] ?? '') === '') {
					return [];
				}

				return $this->tasks;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ([
				'register' => 'dossiq',
				'task_schema' => 'caseTask',
			][$key] ?? '')
		);

		$row = [];
		if ($checklist !== null) {
			$row['checklist'] = $checklist;
		}

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn($row);

		return new StatusChecklist($settings, $lookup, new NullLogger());
	}//end build()
}//end class
