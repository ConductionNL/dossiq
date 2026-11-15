<?php

/**
 * Golden test for TaskEffects::run: every outcome, in order, what each handler
 * is handed, and every log line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Flow\RetiredActionRunner;
use OCA\Dossiq\Service\Task\TaskEffects;
use OCA\Dossiq\Service\Transitions\ActionHandlerInterface;
use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use OCA\Dossiq\Service\Transitions\ActionResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class TaskEffectsRunGoldenTest extends TestCase {

	private array $calls = [];

	private array $logged = [];

	private function effects(bool $withRetired): TaskEffects {
		$calls    = &$this->calls;
		$handler  = static function (string $type) use (&$calls): ActionHandlerInterface {
			return new class ($type, $calls) implements ActionHandlerInterface {
			public function __construct(private string $type, private array &$calls) {
			}

			public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
				$this->calls[] = ['handle', $this->type, $actionConfig, $case, $transitionContext];
				return match ($this->type) {
					'ok' => new ActionResult(succeeded: true),
					'refuse' => new ActionResult(succeeded: false, error: 'nope'),
					'refuse-null' => new ActionResult(succeeded: false),
					default => throw new RuntimeException('boom'),
				};
			}
			};
		};
		$handlers = $this->createMock(ActionHandlerRegistry::class);
		$handlers->method('getHandler')->willReturnCallback(
			static fn (string $type): ?ActionHandlerInterface => in_array($type, ['ok', 'refuse', 'refuse-null', 'boom'], true) ? $handler($type) : null
		);
		$retired = null;
		if ($withRetired === true) {
			$retired = $this->createMock(RetiredActionRunner::class);
			$retired->method('runnableTypes')->willReturn(['legacy', 'legacy-bad']);
			$retired->method('run')->willReturnCallback(
				static function (string $type, array $action, array $case, array $context) use (&$calls): array {
					$calls[] = ['retired', $type, $action, $case, $context];
					return ($type === 'legacy') ? ['ok' => true] : ['ok' => false, 'error' => 'old'];
				}
			);
		}

		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message, $context];
			}
		};

		return new TaskEffects(handlers: $handlers, logger: $logger, retired: $retired);
	}//end effects()

	public function testEveryOutcomeInOrder(): void {
		$case    = ['id' => 'case-1'];
		$context = ['caseId' => 'case-1', 'taskId' => 'task-1'];
		$effects = [['type' => ' ok '], ['type' => 'refuse'], ['type' => 'refuse-null'], ['type' => 'boom'], ['type' => 'gone'], ['type' => 'legacy', 'x' => 1], ['type' => 'legacy-bad'], []];

		$this->assertSame(
			[
				['type' => 'ok', 'ran' => true, 'error' => ''],
				['type' => 'refuse', 'ran' => false, 'error' => 'nope'],
				['type' => 'refuse-null', 'ran' => false, 'error' => ''],
				['type' => 'boom', 'ran' => false, 'error' => 'boom'],
				['type' => 'gone', 'ran' => false, 'error' => 'unresolvable_effect'],
				['type' => 'legacy', 'ran' => true, 'error' => ''],
				['type' => 'legacy-bad', 'ran' => false, 'error' => 'old'],
				['type' => '', 'ran' => false, 'error' => 'unresolvable_effect'],
			],
			$this->effects(withRetired: true)->run(effects: $effects, case: $case, context: $context)
		);
		$this->assertSame(
			[
				['handle', 'ok', ['type' => ' ok '], $case, $context],
				['handle', 'refuse', ['type' => 'refuse'], $case, $context],
				['handle', 'refuse-null', ['type' => 'refuse-null'], $case, $context],
				['handle', 'boom', ['type' => 'boom'], $case, $context],
				['retired', 'legacy', ['type' => 'legacy', 'x' => 1], $case, $context],
				['retired', 'legacy-bad', ['type' => 'legacy-bad'], $case, $context],
			],
			$this->calls
		);
		$this->assertSame(
			[
				['warning', 'Dossiq: a task effect refused', ['effect' => 'refuse', 'error' => 'nope', 'task' => 'task-1']],
				['warning', 'Dossiq: a task effect refused', ['effect' => 'refuse-null', 'error' => null, 'task' => 'task-1']],
				['error', 'Dossiq: a task effect failed', ['effect' => 'boom', 'exception' => 'boom', 'task' => 'task-1']],
				['error', 'Dossiq: a completed task declared an effect no handler answers to', ['effect' => 'gone', 'case' => 'case-1', 'task' => 'task-1']],
				['error', 'Dossiq: a completed task declared an effect no handler answers to', ['effect' => '', 'case' => 'case-1', 'task' => 'task-1']],
			],
			$this->logged
		);
	}//end testEveryOutcomeInOrder()

	public function testWithoutTheRetiredRunnerAndWithoutContext(): void {
		$this->assertSame(
			[['type' => 'legacy', 'ran' => false, 'error' => 'unresolvable_effect'], ['type' => 'refuse', 'ran' => false, 'error' => 'nope']],
			$this->effects(withRetired: false)->run(effects: [['type' => 'legacy'], ['type' => 'refuse']], case: [], context: [])
		);
		$this->assertSame(
			[
				['error', 'Dossiq: a completed task declared an effect no handler answers to', ['effect' => 'legacy', 'case' => '', 'task' => '']],
				['warning', 'Dossiq: a task effect refused', ['effect' => 'refuse', 'error' => 'nope', 'task' => '']],
			],
			$this->logged
		);
	}//end testWithoutTheRetiredRunnerAndWithoutContext()
}//end class
