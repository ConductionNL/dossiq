<?php

/**
 * What completing a task does.
 *
 * Two of these can only be asserted here. That an unresolvable effect refuses
 * the completion BEFORE the task is completed is a sequence, and a browser
 * sees only its result: a task still open, which is what a refused click looks
 * like for every other reason too. And that one failing effect does not stop
 * the next one needs two effects and a failure in the first, which is not a
 * state a real instance can be put into on demand.
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
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\CaseTaskCompletion;
use OCA\Dossiq\Service\Task\TaskEffects;
use OCA\Dossiq\Service\Transitions\ActionHandlerInterface;
use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use OCA\Dossiq\Service\Transitions\ActionResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Task\TaskEffects
 * @covers \OCA\Dossiq\Service\Task\CaseTaskCompletion
 */
class TaskEffectsTest extends TestCase {

	/**
	 * A task carries the effects its case type declared on it.
	 *
	 * @return void
	 */
	public function testTheEffectsAreReadOffTheTask(): void {
		$effects = $this->effects()->declaredOn(
			task: [
				'id' => 'task-1',
				'metadata' => [
					'dossiq' => [
						'effects' => [
							['type' => 'sendEmail', 'template' => 'beschikking'],
							['type' => ''],
							'not an effect',
						],
					],
				],
			]
		);

		// The nameless entry and the string are dropped: neither names a
		// handler, so neither can be run OR refused by name.
		$this->assertCount(expectedCount: 1, haystack: $effects);
		$this->assertSame(expected: 'sendEmail', actual: $effects[0]['type']);
	}

	/**
	 * A task with no declaration declares nothing, without erroring.
	 *
	 * @return void
	 */
	public function testATaskWithNoDeclarationDeclaresNothing(): void {
		$this->assertSame(expected: [], actual: $this->effects()->declaredOn(task: ['id' => 'task-1']));
		$this->assertSame(expected: [], actual: $this->effects()->declaredOn(task: ['metadata' => 'nonsense']));
	}

	/**
	 * Completing runs the declared effects in the order they were declared.
	 *
	 * @return void
	 */
	public function testCompletingRunsTheDeclaredEffects(): void {
		$ran = [];
		$effects = $this->effects(handlers: [
			'sendEmail' => $this->handler(succeeded: true, ran: $ran),
			'resumeTerm' => $this->handler(succeeded: true, ran: $ran),
		]);

		$results = $effects->run(
			effects: [['type' => 'sendEmail'], ['type' => 'resumeTerm']],
			case: ['id' => 'case-1'],
			context: ['caseId' => 'case-1', 'taskId' => 'task-1']
		);

		$this->assertSame(expected: ['sendEmail', 'resumeTerm'], actual: $ran);
		$this->assertTrue(condition: $results[0]['ran']);
		$this->assertTrue(condition: $results[1]['ran']);
	}

	/**
	 * One failing effect does not take the next one with it.
	 *
	 * A declaration that sends a letter and resumes a term must still resume
	 * the term when the mail server is down: the term is a statutory deadline
	 * and the letter can be sent again.
	 *
	 * @return void
	 */
	public function testAFailingEffectDoesNotStopTheNext(): void {
		$ran = [];
		$effects = $this->effects(handlers: [
			'sendEmail' => $this->handler(succeeded: false, ran: $ran),
			'resumeTerm' => $this->handler(succeeded: true, ran: $ran),
		]);

		$results = $effects->run(
			effects: [['type' => 'sendEmail'], ['type' => 'resumeTerm']],
			case: ['id' => 'case-1'],
			context: []
		);

		$this->assertSame(expected: ['sendEmail', 'resumeTerm'], actual: $ran);
		$this->assertFalse(condition: $results[0]['ran']);
		$this->assertTrue(condition: $results[1]['ran']);
	}

	/**
	 * An effect no handler answers to is named before anything runs.
	 *
	 * @return void
	 */
	public function testAnUnresolvableEffectIsNamed(): void {
		$missing = $this->effects(handlers: ['sendEmail' => $this->handler(succeeded: true)])
			->unresolved(effects: [['type' => 'sendEmail'], ['type' => 'teleport'], ['type' => 'teleport']]);

		$this->assertSame(expected: ['teleport'], actual: $missing);
	}

	/**
	 * A required field left empty names the field, and nothing else.
	 *
	 * @return void
	 */
	public function testABlankRequiredFieldNamesTheField(): void {
		$task = [
			'metadata' => [
				'form' => [
					'kind' => 'fields',
					'fields' => [
						['field' => 'aanwezigen', 'required' => false],
						['field' => 'verslag', 'required' => true],
					],
				],
			],
		];

		$this->assertSame(
			expected: 'verslag',
			actual: CaseTaskCompletion::missingRequiredField(task: $task, data: ['aanwezigen' => 'drie'])
		);
		$this->assertSame(
			expected: 'verslag',
			actual: CaseTaskCompletion::missingRequiredField(task: $task, data: ['verslag' => '   '])
		);
		$this->assertSame(
			expected: '',
			actual: CaseTaskCompletion::missingRequiredField(task: $task, data: ['verslag' => 'Gehoord op 3 maart'])
		);
	}

	/**
	 * Zero and false are answers.
	 *
	 * `empty()` would refuse a required amount answered with 0 and a required
	 * yes-or-no answered with no, and the handler would be told to fill in a
	 * field they had filled in.
	 *
	 * @return void
	 */
	public function testZeroAndFalseAreAnswers(): void {
		$task = ['metadata' => ['form' => ['fields' => [['field' => 'bedrag', 'required' => true]]]]];

		$this->assertSame(expected: '', actual: CaseTaskCompletion::missingRequiredField(task: $task, data: ['bedrag' => 0]));
		$this->assertSame(expected: '', actual: CaseTaskCompletion::missingRequiredField(task: $task, data: ['bedrag' => false]));
	}

	/**
	 * A task with no form asks for nothing.
	 *
	 * @return void
	 */
	public function testATaskWithNoFormAsksForNothing(): void {
		$this->assertSame(expected: '', actual: CaseTaskCompletion::missingRequiredField(task: ['id' => 't'], data: []));
	}

	/**
	 * A TaskEffects over a registry holding these handlers.
	 *
	 * @param array<string, ActionHandlerInterface> $handlers The handlers by type.
	 *
	 * @return TaskEffects The service.
	 */
	private function effects(array $handlers = []): TaskEffects {
		$registry = $this->createMock(originalClassName: ActionHandlerRegistry::class);
		$registry->method('getHandler')->willReturnCallback(
			static fn (string $type): ?ActionHandlerInterface => ($handlers[$type] ?? null)
		);

		return new TaskEffects(handlers: $registry, logger: new NullLogger());
	}

	/**
	 * A handler that records that it ran and answers as told.
	 *
	 * @param boolean            $succeeded What it answers.
	 * @param array<int, string> $ran       Collects the types that ran, in order.
	 *
	 * @return ActionHandlerInterface The handler.
	 */
	private function handler(bool $succeeded, array &$ran = []): ActionHandlerInterface {
		return new class ($succeeded, $ran) implements ActionHandlerInterface {
			/**
			 * @param boolean            $succeeded What it answers.
			 * @param array<int, string> $ran       The shared record of what ran.
			 */
			public function __construct(private readonly bool $succeeded, private array &$ran) {
			}

			/**
			 * @param array<string, mixed> $actionConfig The effect.
			 * @param array<string, mixed> $case The case.
			 * @param array<string, mixed> $transitionContext The context.
			 *
			 * @return ActionResult The outcome.
			 */
			public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
				$this->ran[] = (string)($actionConfig['type'] ?? '');

				$error = 'nope';
				if ($this->succeeded === true) {
					$error = null;
				}

				return new ActionResult(succeeded: $this->succeeded, error: $error);
			}
		};
	}
}//end class
