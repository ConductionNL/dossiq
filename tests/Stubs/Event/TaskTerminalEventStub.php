<?php

/**
 * Test stub for OCA\OpenRegister\Event\TaskTerminalEvent.
 *
 * Loaded by tests/bootstrap.php when the real class is absent. Self-skips
 * via a class_exists() guard, so an installed openregister always wins.
 *
 * This is the event `TaskCompletionResumeListener` now listens to, in place
 * of `ObjectUpdatedEvent`. The engine fires it ONCE, when a task reaches a
 * terminal state, which is why the listener no longer has to work out
 * whether a given write was the moment of completion.
 *
 * `isCommitted()` is on the surface deliberately: an uncommitted event may
 * still roll back, and resuming a run over a completion that never happened
 * is not undone by any later event.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Stubs\Event
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

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Task;
use OCP\EventDispatcher\Event;

if (class_exists(TaskTerminalEvent::class) === false) {
	/**
	 * Stub class for TaskTerminalEvent — used only in standalone unit tests.
	 */
	class TaskTerminalEvent extends Event {

		/**
		 * Constructor.
		 *
		 * @param Task    $task      The task that reached a terminal state.
		 * @param boolean $committed Whether the transaction committed.
		 */
		public function __construct(
			private readonly Task $task,
			private readonly bool $committed = true,
		) {
			parent::__construct();
		}//end __construct()

		/**
		 * @return Task The task.
		 */
		public function getTask(): Task {
			return $this->task;
		}//end getTask()

		/**
		 * @return boolean Whether the transaction committed.
		 */
		public function isCommitted(): bool {
			return $this->committed;
		}//end isCommitted()

		/**
		 * @return string The task uuid.
		 */
		public function getTaskUuid(): string {
			return (string)$this->task->getUuid();
		}//end getTaskUuid()
	}//end class
}
