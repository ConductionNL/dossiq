<?php

/**
 * Test stub for OCA\OpenRegister\Service\Task\TaskFormCompletion.
 *
 * Every method throws: the real class owns the form check and the subject
 * write, so a test double of it is built with `onlyMethods` and refuses a
 * signature the real class does not have.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use LogicException;

if (class_exists('\\OCA\\OpenRegister\\Service\\Task\\TaskFormCompletion', false) === false) {
	/**
	 * Minimal TaskFormCompletion stand-in.
	 */
	class TaskFormCompletion {

		/**
		 * Complete a task, writing its form payload to the subject first.
		 *
		 * @param string               $uuid   The task uuid.
		 * @param TaskAnswer           $answer The completion outcome, result and comment.
		 * @param array<string, mixed> $data   The declared field values.
		 * @param string|null          $actor  The completing identity.
		 *
		 * @return object The completed task.
		 */
		public function complete(string $uuid, TaskAnswer $answer, array $data, ?string $actor): object {
			throw new LogicException('TaskFormCompletion stub: mock complete()');
		}//end complete()
	}//end class
}//end if
