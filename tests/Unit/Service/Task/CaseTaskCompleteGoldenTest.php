<?php

/**
 * Golden test for CaseTaskActions::complete: every refusal in order, what the
 * engine is asked, and the whole answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCA\Dossiq\Service\Task\CaseTaskActions;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\TaskEffects;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CaseTaskCompleteGoldenTest extends TestCase {

	private array $calls = [];

	private function actions(?array $task, array $unresolved = [], bool $engineAccepts = true): CaseTaskActions {
		$calls  = &$this->calls;
		$engine = $this->createMock(EngineTaskGateway::class);
		$engine->method('find')->willReturnCallback(
			static function (string $taskId) use (&$calls, $task): ?array {
				$calls[] = ['find', $taskId];
				return $task;
			}
		);
		$engine->method('complete')->willReturnCallback(
			static function (...$args) use (&$calls, $engineAccepts): bool {
				$calls[] = array_merge(['complete'], $args);
				return $engineAccepts;
			}
		);
		$engine->method('lastError')->willReturn('complete_failed');
		$effects = $this->createMock(TaskEffects::class);
		$effects->method('declaredOn')->willReturnCallback(
			static function (array $task) use (&$calls): array {
				$calls[] = ['declared'];
				return [['type' => 'x']];
			}
		);
		$effects->method('unresolved')->willReturnCallback(
			static function (array $effects) use (&$calls, $unresolved): array {
				$calls[] = ['unresolved', $effects];
				return $unresolved;
			}
		);
		$notices = $this->createMock(CaseActNotices::class);
		$notices->method('taskCompleted')->willReturnCallback(static fn (string $task): array => ['title' => 'Task ' . $task . ' completed.']);

		return new CaseTaskActions(engineTasks: $engine, effects: $effects, notices: $notices);
	}//end actions()

	public function testTheWholeAnswer(): void {
		$task = ['id' => 'engine-1', 'title' => ' Advies ', 'objectUuid' => 'case-1'];
		$this->assertSame(
			['completed' => true, 'task' => 'engine-1', 'case' => 'case-1', 'state' => 'completed', 'isTerminal' => true, 'notice' => ['title' => 'Task Advies completed.']],
			$this->actions($task)->complete(taskId: 'task-1', data: ['a' => 1], outcome: 'done', actor: 'anna')
		);
		$this->assertSame([['find', 'task-1'], ['declared'], ['unresolved', [['type' => 'x']]], ['complete', 'task-1', ['a' => 1], 'done', 'anna']], $this->calls);
		$this->assertSame(
			['completed' => true, 'task' => 'task-2', 'case' => '', 'state' => 'completed', 'isTerminal' => true, 'notice' => ['title' => 'Task  completed.']],
			$this->actions([])->complete(taskId: 'task-2', data: [], outcome: '', actor: '')
		);
	}//end testTheWholeAnswer()

	public function testEveryRefusalInOrder(): void {
		$required = ['id' => 't', 'metadata' => ['form' => ['kind' => 'fields', 'fields' => [['field' => 'verslag', 'required' => true]]]]];
		$cases = [
			'task_not_found' => [$this->actions(null), []],
			'unresolvable_effect:teleport' => [$this->actions(['id' => 't'], unresolved: ['teleport', 'fly']), []],
			'complete_failed' => [$this->actions(['id' => 't'], engineAccepts: false), []],
		];
		foreach ($cases as $expected => [$actions, $data]) {
			try {
				$actions->complete(taskId: 'task-1', data: $data, outcome: 'done', actor: 'anna');
				$this->fail('completed');
			} catch (RuntimeException $e) {
				$this->assertSame($expected, $e->getMessage());
			}
		}

		$this->calls = [];
		try {
			$this->actions($required)->complete(taskId: 'task-1', data: ['verslag' => ' '], outcome: 'done', actor: 'anna');
			$this->fail('completed');
		} catch (RuntimeException $e) {
			$this->assertSame('required_field:verslag', $e->getMessage());
		}

		$this->assertSame([['find', 'task-1']], $this->calls);
	}//end testEveryRefusalInOrder()
}//end class
