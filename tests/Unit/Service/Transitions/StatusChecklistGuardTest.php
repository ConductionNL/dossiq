<?php

/**
 * StatusChecklistGuard Unit Tests
 *
 * The guard is the server-side half of the disabled button: a browser can show
 * that the button is off, only this can show that a POST past it is refused.
 * The case that matters most is the one with NO task at all — the free-form
 * move that happened before the checklist existed — because a guard that read
 * that as "nothing to do" would be a guard that cannot fail.
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
use OCA\Dossiq\Service\Transitions\StatusChecklistGuard;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Transitions\StatusChecklistGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class StatusChecklistGuardTest extends TestCase {
	/**
	 * The required item with an open task fails, and the message names it.
	 *
	 * @return void
	 */
	public function testARequiredItemWithAnOpenTaskFailsAndNamesIt(): void {
		$guard = $this->guard(
			items: [
				['title' => 'Check the objection is on time', 'required' => true],
				['title' => 'Confirm receipt to the objector', 'required' => false],
			],
			tasks: [
				['title' => 'Check the objection is on time', 'status' => 'available'],
				['title' => 'Confirm receipt to the objector', 'status' => 'available'],
			],
		);

		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c', 'status' => 'st-intake'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame('Checklist item not done: Check the objection is on time', $result->failureMessage);
		self::assertSame(['Check the objection is on time'], $result->details['open']);
	}//end testARequiredItemWithAnOpenTaskFailsAndNamesIt()

	/**
	 * The required item with a completed task passes.
	 *
	 * @return void
	 */
	public function testARequiredItemWithACompletedTaskPasses(): void {
		$guard = $this->guard(
			items: [['title' => 'Check the objection is on time', 'required' => true]],
			tasks: [['title' => 'Check the objection is on time', 'status' => 'completed']],
		);

		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c', 'status' => 'st-intake'], userId: 'u');

		self::assertTrue($result->passed);
	}//end testARequiredItemWithACompletedTaskPasses()

	/**
	 * A required item with NO task counts as not done.
	 *
	 * @return void
	 */
	public function testARequiredItemWithNoTaskFails(): void {
		$guard = $this->guard(
			items: [['title' => 'Check the objection is on time', 'required' => true]],
			tasks: [],
		);

		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c', 'status' => 'st-intake'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame('Checklist item not done: Check the objection is on time', $result->failureMessage);
	}//end testARequiredItemWithNoTaskFails()

	/**
	 * A task that is merely started is not a task that is done.
	 *
	 * @return void
	 */
	public function testATaskThatIsActiveIsNotDone(): void {
		$guard = $this->guard(
			items: [['title' => 'Check the objection is on time', 'required' => true]],
			tasks: [['title' => 'Check the objection is on time', 'status' => 'active']],
		);

		self::assertFalse(
			$guard->evaluate(guardConfig: [], case: ['id' => 'c', 'status' => 'st-intake'], userId: 'u')->passed
		);
	}//end testATaskThatIsActiveIsNotDone()

	/**
	 * An optional item never holds the case, however open its task is.
	 *
	 * @return void
	 */
	public function testAnOptionalItemNeverFails(): void {
		$guard = $this->guard(
			items: [['title' => 'Confirm receipt to the objector', 'required' => false]],
			tasks: [['title' => 'Confirm receipt to the objector', 'status' => 'available']],
		);

		self::assertTrue(
			$guard->evaluate(guardConfig: [], case: ['id' => 'c', 'status' => 'st-intake'], userId: 'u')->passed
		);
	}//end testAnOptionalItemNeverFails()

	/**
	 * A status with no checklist at all passes without reading any task.
	 *
	 * @return void
	 */
	public function testAStatusWithoutAChecklistPasses(): void {
		$checklist = $this->createMock(StatusChecklist::class);
		$checklist->method('itemsFor')->willReturn([]);
		$checklist->expects($this->never())->method('tasksFor');

		$guard = new StatusChecklistGuard($checklist, $this->l10n());

		self::assertTrue(
			$guard->evaluate(guardConfig: [], case: ['id' => 'c', 'status' => 'st-intake'], userId: 'u')->passed
		);
	}//end testAStatusWithoutAChecklistPasses()

	/**
	 * The guard reads the status the case is IN, not one it is moving to.
	 *
	 * The items being enforced are the ones the case has yet to finish. Reading
	 * the target status would enforce work nobody has been asked to do yet.
	 *
	 * @return void
	 */
	public function testTheGuardReadsTheStatusTheCaseIsIn(): void {
		$checklist = $this->createMock(StatusChecklist::class);
		$checklist->expects($this->once())
			->method('itemsFor')
			->with('st-intake')
			->willReturn([]);

		$guard = new StatusChecklistGuard($checklist, $this->l10n());
		$guard->evaluate(guardConfig: [], case: ['id' => 'c', 'status' => 'st-intake'], userId: 'u');
	}//end testTheGuardReadsTheStatusTheCaseIsIn()

	/**
	 * A completed ENGINE task satisfies its required item, end to end.
	 *
	 * 🔴 THE ONE TEST IN THIS FILE THAT DOES NOT MOCK `StatusChecklist`, and
	 * the reason the defect it pins shipped. Every other case here hands the
	 * guard a double whose `tasksFor()` answers whatever the case needs, which
	 * proves the guard's arithmetic and nothing about where the tasks come
	 * from. `CreateTaskHandler` moved its write to the task engine (#2363) and
	 * `StatusChecklist::tasksFor()` went on searching the `caseTask` register
	 * schema, so on a real instance it answered an empty list for every case:
	 * a handler ticked the task off, `completedTitles()` saw nothing, and the
	 * case could not leave its phase. A mocked checklist reported green
	 * throughout.
	 *
	 * So this one wires the real reader over a fake ENGINE inbox. Point the
	 * reader back at the register and it goes red.
	 *
	 * @return void
	 */
	public function testACompletedEngineTaskSatisfiesItsRequiredItem(): void {
		$guard = new StatusChecklistGuard(
			$this->realChecklist(
				items: [['title' => 'Check the objection is on time', 'required' => true]],
				engineTasks: [
					[
						'id' => 'task-1',
						'title' => 'Check the objection is on time',
						'status' => 'completed',
						'case' => 'c',
						'workflowStepId' => 'st-intake',
					],
				],
			),
			$this->l10n()
		);

		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c', 'status' => 'st-intake'], userId: 'jan');

		self::assertTrue($result->passed, (string)$result->failureMessage);
	}//end testACompletedEngineTaskSatisfiesItsRequiredItem()

	/**
	 * A completed task from ANOTHER status does not satisfy this one's item.
	 *
	 * The engine has no `workflowStepId` filter, so the narrowing happens in
	 * `StatusChecklist`. A narrowing that let another phase's task through
	 * would open the case's progress on work this phase never asked for.
	 *
	 * @return void
	 */
	public function testACompletedTaskFromAnotherStatusDoesNotSatisfyTheItem(): void {
		$guard = new StatusChecklistGuard(
			$this->realChecklist(
				items: [['title' => 'Check the objection is on time', 'required' => true]],
				engineTasks: [
					[
						'id' => 'task-1',
						'title' => 'Check the objection is on time',
						'status' => 'completed',
						'case' => 'c',
						'workflowStepId' => 'st-decision',
					],
				],
			),
			$this->l10n()
		);

		self::assertFalse(
			$guard->evaluate(guardConfig: [], case: ['id' => 'c', 'status' => 'st-intake'], userId: 'jan')->passed
		);
	}//end testACompletedTaskFromAnotherStatusDoesNotSatisfyTheItem()

	/**
	 * The real `StatusChecklist`, over a fake engine inbox.
	 *
	 * @param array<int, array<string, mixed>> $items       The status's checklist items, as stored.
	 * @param array<int, array<string, mixed>> $engineTasks The tasks the engine holds for the case.
	 *
	 * @return StatusChecklist The reader.
	 */
	private function realChecklist(array $items, array $engineTasks): StatusChecklist {
		$inbox = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$inbox->method('forCase')->willReturn($engineTasks);
		$inbox->method('lastError')->willReturn('');

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn(['checklist' => $items]);

		return new StatusChecklist($lookup, $inbox, new NullLogger());
	}//end realChecklist()

	/**
	 * Build the guard over a fixed item list and task list.
	 *
	 * @param array<int, array{title: string, required: bool}> $items The status's items.
	 * @param array<int, array<string, mixed>>                 $tasks The status's tasks on the case.
	 *
	 * @return StatusChecklistGuard The guard under test.
	 */
	private function guard(array $items, array $tasks): StatusChecklistGuard {
		$checklist = $this->createMock(StatusChecklist::class);
		$checklist->method('itemsFor')->willReturn($items);
		$checklist->method('tasksFor')->willReturn($tasks);

		return new StatusChecklistGuard($checklist, $this->l10n());
	}//end guard()

	/**
	 * An IL10N double that renders the English source with its placeholders.
	 *
	 * @return IL10N&\PHPUnit\Framework\MockObject\MockObject The localiser.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return $l10n;
	}//end l10n()
}//end class
