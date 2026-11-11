<?php

/**
 * Characterisation tests for CreateTaskHandler::handle: the default title,
 * the log lines of each failure, and the failure it answers when anything
 * throws.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

class CreateTaskHandlerGoldenTest extends TestCase {

	/**
	 * Every line logged.
	 *
	 * @var array<int, array>
	 */
	private array $lines = [];

	/**
	 * What the engine was handed.
	 *
	 * @var array<int, array>
	 */
	private array $imports = [];

	private function handler(string $reason = '', mixed $taskId = 'task-1'): CreateTaskHandler {
		$gateway = $this->getMockBuilder(EngineTaskGateway::class)->disableOriginalConstructor()->getMock();
		$gateway->method('unavailableReason')->willReturn($reason);
		$gateway->method('lastError')->willReturn('the engine said no');
		$gateway->method('keyTaken')->willReturn(false);
		$gateway->method('mirrorImport')->willReturnCallback(function (array $task, string $caseId, ?string $actor) use ($taskId): string {
			$this->imports[] = [$task, $caseId, $actor];
			if ($taskId instanceof \Throwable) {
				throw $taskId;
			}

			return $taskId;
		});

		$sink = &$this->lines;
		$logger = new class($sink) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->sink[] = [(string)$level, (string)$message, $context];
			}
		};

		return new CreateTaskHandler(new AssigneeResolver(new NullLogger()), $gateway, $logger);
	}//end handler()

	public function testAnUntitledTaskIsNamedAfterTheTransition(): void {
		$result = $this->handler()->handle(['assignee' => 'none'], ['id' => 'case-1'], ['transitionLabel' => 'Beoordelen', 'userId' => 'jan']);

		self::assertTrue($result->succeeded);
		self::assertSame(['taskId' => 'task-1'], $result->data);
		self::assertSame('Taak na transitie Beoordelen', $this->imports[0][0]['title']);
		self::assertSame('available', $this->imports[0][0]['status']);
		self::assertSame('case-1', $this->imports[0][1]);
		self::assertSame('jan', $this->imports[0][2]);
		self::assertSame([], $this->lines);

		$this->handler()->handle([], ['id' => 'case-1'], []);
		self::assertSame('Taak na transitie ', $this->imports[1][0]['title']);
	}//end testAnUntitledTaskIsNamedAfterTheTransition()

	public function testEachFailureIsLoggedAndAnswered(): void {
		$unavailable = $this->handler(reason: 'not installed')->handle(['title' => 'T'], ['id' => 'c'], []);
		self::assertSame('storage_unavailable', $unavailable->error);
		self::assertSame([['error', 'CreateTaskHandler: the task engine is unavailable', ['reason' => 'not installed']]], $this->lines);
		self::assertSame([], $this->imports);

		$this->lines = [];
		$refused = $this->handler(taskId: '')->handle(['title' => 'T', 'assignee' => 'none'], ['id' => 'c'], []);
		self::assertSame('create_task_failed', $refused->error);
		self::assertSame([['error', 'CreateTaskHandler: the engine refused the task', ['reason' => 'the engine said no', 'case' => 'c']]], $this->lines);

		$this->lines = [];
		$thrown = $this->handler(taskId: new \RuntimeException('boom'))->handle(['title' => 'T', 'assignee' => 'none'], ['id' => 'c'], ['run' => 'r']);
		self::assertFalse($thrown->succeeded);
		self::assertSame('create_task_failed', $thrown->error);
		self::assertSame([['error', 'CreateTaskHandler failed', ['exception' => 'boom', 'context' => ['run' => 'r']]]], $this->lines);
	}//end testEachFailureIsLoggedAndAnswered()
}//end class
