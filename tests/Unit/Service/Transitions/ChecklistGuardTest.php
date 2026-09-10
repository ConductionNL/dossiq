<?php

/**
 * ChecklistGuard Unit Tests
 *
 * The guard reads tasks from OpenRegister's task engine, so these tests
 * drive the two seams onto it rather than an object service: one task by id
 * (`EngineTaskGateway::find`) and every task on a case
 * (`EngineTaskInbox::forCase`).
 *
 * The behaviour worth pinning, in order of what it has cost:
 *
 *  1. A guard naming no task reads every task on the case. A workflow
 *     TEMPLATE cannot know a runtime task uuid, so every shipped checklist
 *     guard names none, and refusing those outright made them permanent
 *     blockers that looked like unfinished work.
 *  2. A required item the checklist does not carry counts as MISSING. The
 *     allow-list once reported only items that were present and unticked,
 *     so an item nobody had put on the checklist satisfied the guard.
 *  3. An unreadable engine fails CLOSED. A case with no tasks and a read
 *     that failed both answer with an empty list, and "no tasks" passes, so
 *     a guard that could not tell them apart would stop guarding at exactly
 *     the moment the engine was unavailable.
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
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-19
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Transitions\ChecklistGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class ChecklistGuardTest extends TestCase {

	/**
	 * The engine's reader, answering with these tasks for a case.
	 *
	 * @param array<int, array<string, mixed>> $tasks The tasks on the case.
	 * @param string                           $error Why the read failed, or ''.
	 *
	 * @return EngineTaskInbox&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function inbox(array $tasks, string $error = ''): EngineTaskInbox {
		$inbox = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$inbox->method('forCase')->willReturn($tasks);
		$inbox->method('lastError')->willReturn($error);

		return $inbox;
	}//end inbox()

	/**
	 * The engine's single-task read, answering with this task.
	 *
	 * @param array<string, mixed>|null $task The task, or null when unreadable.
	 *
	 * @return EngineTaskGateway&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function gateway(?array $task): EngineTaskGateway {
		$gateway = $this->getMockBuilder(EngineTaskGateway::class)
			->disableOriginalConstructor()
			->getMock();
		$gateway->method('find')->willReturn($task);
		$gateway->method('lastError')->willReturn($task === null ? 'engine down' : '');

		return $gateway;
	}//end gateway()

	/**
	 * The guard, wired to one pair of engine doubles.
	 *
	 * @param array<int, array<string, mixed>> $caseTasks Tasks on the case.
	 * @param array<string, mixed>|null        $namedTask The task an id resolves to.
	 * @param string                           $error     Why the case read failed, or ''.
	 *
	 * @return ChecklistGuard The guard.
	 */
	private function guard(array $caseTasks = [], ?array $namedTask = null, string $error = ''): ChecklistGuard {
		return new ChecklistGuard(
			$this->inbox($caseTasks, $error),
			$this->gateway($namedTask),
			new NullLogger()
		);
	}//end guard()

	/**
	 * A guard naming no task reads every task on the case.
	 *
	 * @return void
	 */
	public function testWithoutTaskIdReadsEveryTaskOnTheCase(): void {
		$guard = $this->guard(
			caseTasks: [
				['checklist' => [['label' => 'Stuk 1', 'checked' => true]]],
				['checklist' => [['label' => 'Stuk 2', 'checked' => false]]],
			]
		);

		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame(['Stuk 2'], $result->details['missing']);
	}//end testWithoutTaskIdReadsEveryTaskOnTheCase()

	/**
	 * The engine's typed checklist is read as it arrives.
	 *
	 * `caseTask` stored `checklist` as a JSON-encoded string and the guard
	 * decoded it. The engine refuses a string at write time, so the decode
	 * is gone and this pins the shape that replaced it.
	 *
	 * @return void
	 */
	public function testReadsTheEnginesTypedChecklist(): void {
		$guard = $this->guard(
			namedTask: [
				'id' => 't-1',
				'checklist' => [
					['id' => 'i-1', 'label' => 'Stuk 1', 'checked' => false],
				],
			]
		);

		$result = $guard->evaluate(guardConfig: ['taskId' => 't-1'], case: ['id' => 'c'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame(['Stuk 1'], $result->details['missing']);
	}//end testReadsTheEnginesTypedChecklist()

	/**
	 * A required item the checklist does not carry counts as missing.
	 *
	 * @return void
	 */
	public function testARequiredItemThatIsAbsentCountsAsMissing(): void {
		$guard = $this->guard(namedTask: ['checklist' => [['label' => 'Iets anders', 'checked' => true]]]);

		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1', 'requiredItems' => ['Rechtsmiddelenclausule opgenomen']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame(['Rechtsmiddelenclausule opgenomen'], $result->details['missing']);
	}//end testARequiredItemThatIsAbsentCountsAsMissing()

	/**
	 * A case-wide check with no case to read fails closed.
	 *
	 * @return void
	 */
	public function testFailsWhenTheCaseCannotBeIdentified(): void {
		$result = $this->guard()->evaluate(guardConfig: [], case: [], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame('Zaak niet herkend voor checklistcontrole', $result->failureMessage);
	}//end testFailsWhenTheCaseCannotBeIdentified()

	/**
	 * A case read the engine could not make fails closed, not open.
	 *
	 * The engine answers `[]` both when the case has no tasks and when the
	 * read failed, and the first of those PASSES the guard. Without the
	 * failure being asked for by name, an unavailable engine would wave
	 * every transition through.
	 *
	 * @return void
	 */
	public function testAnUnreadableEngineFailsTheCaseWideCheck(): void {
		$guard = $this->guard(caseTasks: [], error: 'the task engine is not available');

		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame('Taken van de zaak niet gevonden', $result->failureMessage);
	}//end testAnUnreadableEngineFailsTheCaseWideCheck()

	/**
	 * A case that genuinely has no tasks has nothing unticked, so it passes.
	 *
	 * The other half of the pair above: without this, a guard that always
	 * failed on an empty list would satisfy that test too.
	 *
	 * @return void
	 */
	public function testACaseWithNoTasksPasses(): void {
		$result = $this->guard(caseTasks: [])->evaluate(
			guardConfig: [],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testACaseWithNoTasksPasses()

	/**
	 * A named task the engine cannot produce fails closed.
	 *
	 * @return void
	 */
	public function testFailsWhenTheNamedTaskCannotBeRead(): void {
		$result = $this->guard(namedTask: null)->evaluate(
			guardConfig: ['taskId' => 't-1'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame('Gekoppelde taak niet gevonden', $result->failureMessage);
	}//end testFailsWhenTheNamedTaskCannotBeRead()

	/**
	 * @return void
	 */
	public function testPassesWhenAllItemsChecked(): void {
		$guard = $this->guard(
			namedTask: [
				'checklist' => [
					['label' => 'Stuk 1', 'checked' => true],
					['label' => 'Stuk 2', 'checked' => true],
				],
			]
		);

		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testPassesWhenAllItemsChecked()

	/**
	 * @return void
	 */
	public function testFailsAndListsMissingItems(): void {
		$guard = $this->guard(
			namedTask: [
				'checklist' => [
					['label' => 'Stuk 1', 'checked' => true],
					['label' => 'Stuk 2', 'checked' => false],
					['label' => 'Stuk 3', 'checked' => false],
				],
			]
		);

		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame(['Stuk 2', 'Stuk 3'], $result->details['missing']);
	}//end testFailsAndListsMissingItems()

	/**
	 * Only the named items count; an unticked item outside the list does not.
	 *
	 * @return void
	 */
	public function testHonoursRequiredItemsWhitelist(): void {
		$guard = $this->guard(
			namedTask: [
				'checklist' => [
					['label' => 'Stuk 1', 'checked' => false],
					['label' => 'Stuk 2', 'checked' => true],
				],
			]
		);

		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1', 'requiredItems' => ['Stuk 2']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testHonoursRequiredItemsWhitelist()
}//end class
