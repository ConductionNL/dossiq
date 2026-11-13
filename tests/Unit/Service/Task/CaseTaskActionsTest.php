<?php

/**
 * Claiming, reading and cancelling a case task, and the required-field rule
 * a completion is checked against.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCA\Dossiq\Service\Task\CaseTaskActions;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\TaskEffects;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Task\CaseTaskActions
 */
class CaseTaskActionsTest extends TestCase {

	/**
	 * The task engine.
	 *
	 * @var EngineTaskGateway&MockObject
	 */
	private EngineTaskGateway&MockObject $engine;

	/**
	 * The actions under test.
	 *
	 * @var CaseTaskActions
	 */
	private CaseTaskActions $actions;

	/**
	 * Build the actions over a mocked engine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine  = $this->createMock(EngineTaskGateway::class);
		$this->actions = new CaseTaskActions(
			engineTasks: $this->engine,
			effects: $this->createMock(TaskEffects::class),
			notices: $this->createMock(CaseActNotices::class)
		);
	}//end setUp()

	/**
	 * Whether a claim is offered is the engine's answer.
	 *
	 * @return void
	 */
	public function testClaimSupportIsAskedOfTheEngine(): void {
		$this->engine->expects($this->exactly(2))->method('supportsClaim')->willReturnOnConsecutiveCalls(true, false);

		$this->assertTrue(condition: $this->actions->engineAnswersClaim());
		$this->assertFalse(condition: $this->actions->engineAnswersClaim());
	}//end testClaimSupportIsAskedOfTheEngine()

	/**
	 * Finding a task passes the engine's answer through, null included.
	 *
	 * @return void
	 */
	public function testFindPassesTheEngineAnswerThrough(): void {
		$this->engine->expects($this->exactly(2))->method('find')
			->willReturnCallback(static fn (string $taskId): ?array => $taskId === 'task-1' ? ['id' => 'task-1'] : null);

		$this->assertSame(expected: ['id' => 'task-1'], actual: $this->actions->find(taskId: 'task-1'));
		$this->assertNull(actual: $this->actions->find(taskId: 'task-2'));
	}//end testFindPassesTheEngineAnswerThrough()

	/**
	 * An accepted claim reports the task it claimed.
	 *
	 * @return void
	 */
	public function testAcceptedClaim(): void {
		$this->engine->expects($this->once())->method('claim')->with(taskId: 'task-1', actor: 'anna')->willReturn(true);
		$this->engine->expects($this->never())->method('lastError');

		$this->assertSame(
			expected: ['claimed' => true, 'task' => 'task-1'],
			actual: $this->actions->claim(taskId: 'task-1', actor: 'anna')
		);
	}//end testAcceptedClaim()

	/**
	 * A refused claim throws with the engine's own reason.
	 *
	 * @return void
	 */
	public function testRefusedClaimKeepsTheEngineReason(): void {
		$this->engine->method('claim')->willReturn(false);
		$this->engine->method('lastError')->willReturn('not_a_candidate');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not_a_candidate');

		$this->actions->claim(taskId: 'task-1', actor: 'piet');
	}//end testRefusedClaimKeepsTheEngineReason()

	/**
	 * A cancel reason is trimmed, and a blank one is passed as null.
	 *
	 * @return void
	 */
	public function testCancelTrimsTheReason(): void {
		$seen = [];
		$this->engine->method('cancel')->willReturnCallback(
			static function (string $taskId, ?string $reason, string $actor) use (&$seen): bool {
				$seen[] = [$taskId, $reason, $actor];
				return true;
			}
		);

		$this->assertSame(
			expected: ['cancelled' => true, 'task' => 'task-1'],
			actual: $this->actions->cancel(taskId: 'task-1', reason: ' dubbel ', actor: 'anna')
		);
		$this->actions->cancel(taskId: 'task-2', reason: '  ', actor: 'anna');

		$this->assertSame(expected: [['task-1', 'dubbel', 'anna'], ['task-2', null, 'anna']], actual: $seen);
	}//end testCancelTrimsTheReason()

	/**
	 * A refused cancel is prefixed so the caller can tell it apart.
	 *
	 * @return void
	 */
	public function testRefusedCancelIsPrefixed(): void {
		$this->engine->method('cancel')->willReturn(false);
		$this->engine->method('lastError')->willReturn('already_closed');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cancel_refused:already_closed');

		$this->actions->cancel(taskId: 'task-1', reason: '', actor: 'anna');
	}//end testRefusedCancelIsPrefixed()

	/**
	 * The first required field whose answer is blank is named.
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldNamesTheFirstBlankAnswer(): void {
		$task = [
			'metadata' => [
				'form' => [
					'fields' => [
						'noise',
						['field' => 'optioneel'],
						['field' => ' ', 'required' => true],
						['field' => 'verslag', 'required' => true],
						['field' => 'bijlagen', 'required' => true],
						['field' => 'besluit', 'required' => true],
					],
				],
			],
		];

		$this->assertSame(
			expected: 'bijlagen',
			actual: CaseTaskActions::missingRequiredField(task: $task, data: ['verslag' => 'ok', 'bijlagen' => []])
		);
		$this->assertSame(
			expected: 'besluit',
			actual: CaseTaskActions::missingRequiredField(task: $task, data: ['verslag' => 0, 'bijlagen' => ['a'], 'besluit' => "\t"])
		);
		$this->assertSame(
			expected: '',
			actual: CaseTaskActions::missingRequiredField(task: $task, data: ['verslag' => false, 'bijlagen' => ['a'], 'besluit' => 'ja'])
		);
	}//end testMissingRequiredFieldNamesTheFirstBlankAnswer()

	/**
	 * Malformed metadata, form or fields require nothing.
	 *
	 * @return void
	 */
	public function testMalformedFormRequiresNothing(): void {
		foreach ([['metadata' => 'x'], ['metadata' => ['form' => 'x']], ['metadata' => ['form' => ['fields' => 'x']]], []] as $task) {
			$this->assertSame(expected: '', actual: CaseTaskActions::missingRequiredField(task: $task, data: []));
		}
	}//end testMalformedFormRequiresNothing()

	/**
	 * A task's title is trimmed, and falls back to its id.
	 *
	 * @return void
	 */
	public function testTitleFallsBackToTheId(): void {
		$this->assertSame(expected: 'Advies', actual: $this->actions->titleOf(task: ['title' => ' Advies ', 'id' => 't']));
		$this->assertSame(expected: 't', actual: $this->actions->titleOf(task: ['title' => ' ', 'id' => 't']));
		$this->assertSame(expected: '', actual: $this->actions->titleOf(task: []));
	}//end testTitleFallsBackToTheId()
}//end class
