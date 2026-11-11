<?php

/**
 * Golden test for StatusChecklist::tasksFor: what the engine is asked, which
 * tasks come back, and every log line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\Transitions\StatusChecklist;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class StatusTasksGoldenTest extends TestCase {

	private array $asked = [];

	private array $logged = [];

	private function checklist(array $tasks, string $error = ''): StatusChecklist {
		$asked = &$this->asked;
		$inbox = $this->getMockBuilder(EngineTaskInbox::class)->disableOriginalConstructor()->getMock();
		$inbox->method('forCase')->willReturnCallback(
			static function (string $caseId, string $actor, int $limit) use (&$asked, $tasks): array {
				$asked[] = [$caseId, $actor, $limit];
				return $tasks;
			}
		);
		$inbox->method('lastError')->willReturn($error);
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

		return new StatusChecklist($this->createMock(StatusTypeLookup::class), $inbox, $logger);
	}//end checklist()

	public function testTheTasksOfTheStatus(): void {
		$tasks = [
			['id' => 't1', 'workflowStepId' => 'st-1'],
			['id' => 't2', 'workflowStepId' => ' st-1 '],
			['id' => 't3', 'workflowStepId' => 'st-2'],
			['id' => 't4'],
		];

		$this->assertSame(
			[['id' => 't1', 'workflowStepId' => 'st-1'], ['id' => 't2', 'workflowStepId' => ' st-1 ']],
			$this->checklist($tasks)->tasksFor(statusTypeId: 'st-1', case: ['uuid' => 'case-1'], actor: 'jan')
		);
		$this->assertSame([['case-1', 'jan', 500]], $this->asked);
		$this->assertSame([], $this->logged);
	}//end testTheTasksOfTheStatus()

	public function testNothingToReadAndAFailedRead(): void {
		$checklist = $this->checklist([['id' => 't1', 'workflowStepId' => 'st-1']], error: 'engine down');

		$this->assertSame([], $checklist->tasksFor(statusTypeId: '', case: ['id' => 'case-1'], actor: 'jan'));
		$this->assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: [], actor: 'jan'));
		$this->assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: ' '));
		$this->assertSame([], $this->asked);
		$this->assertSame([], $checklist->tasksFor(statusTypeId: 'st-1', case: ['id' => 'case-1'], actor: 'jan'));
		$this->assertCount(1, $this->asked);
		$this->assertSame(
			[
				['error', 'StatusChecklist: no acting identity, so the status tasks cannot be read', ['case' => 'case-1', 'statusType' => 'st-1']],
				['error', 'StatusChecklist: reading the status tasks failed', ['exception' => 'engine down', 'case' => 'case-1', 'statusType' => 'st-1']],
			],
			$this->logged
		);
	}//end testNothingToReadAndAFailedRead()
}//end class
