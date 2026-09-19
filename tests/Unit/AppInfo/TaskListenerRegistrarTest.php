<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\TaskListenerRegistrar;
use OCA\Dossiq\Listener\TaskCompletionEffectsListener;
use OCA\Dossiq\Listener\TaskCompletionResumeListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Both task-completion listeners attach to the engine's terminal event.
 *
 * 🔑 TWO LISTENERS ON ONE EVENT, AND THE SECOND IS EASY TO LOSE. Resuming the
 * run a task was blocking and doing what the case type declared completing it
 * does are two jobs with two failure modes. A registrar that attaches only the
 * first looks identical at runtime until a declared effect quietly stops
 * happening, which is a letter nobody sent.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\AppInfo\Registrar\TaskListenerRegistrar
 *
 * @spec openspec/specs/task-management/spec.md
 */
class TaskListenerRegistrarTest extends TestCase {

	/**
	 * Both listeners are registered, on the engine's terminal event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function testBothTaskListenersAttachToTheTerminalEvent(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new TaskListenerRegistrar())->register(context: $context);

		self::assertSame(
			expected: [
				['OCA\OpenRegister\Event\TaskTerminalEvent', TaskCompletionResumeListener::class],
				['OCA\OpenRegister\Event\TaskTerminalEvent', TaskCompletionEffectsListener::class],
			],
			actual: $registered,
			message: 'both listeners attach to TaskTerminalEvent, and neither is dropped',
		);
	}//end testBothTaskListenersAttachToTheTerminalEvent()

	/**
	 * The event is the ENGINE's terminal event and not an object update.
	 *
	 * Tasks are OpenRegister `Task` rows, so nothing writes a `caseTask`
	 * object any more: an ObjectUpdatedEvent listener would never fire again
	 * and the run would only resume on the 30-minute heartbeat.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function testTheEventIsNotAnObjectUpdate(): void {
		$events = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event) use (&$events): void {
				$events[] = $event;
			}
		);

		(new TaskListenerRegistrar())->register(context: $context);

		self::assertNotContains('OCA\OpenRegister\Event\ObjectUpdatedEvent', $events);
	}//end testTheEventIsNotAnObjectUpdate()
}//end class
