<?php

/**
 * The acts a handler takes on a task from the case page.
 *
 * A handler finishing "hoor de belanghebbende" on the case page is doing four
 * things at once: answering the form the task asked for, completing the task,
 * running what the case type said completing it does, and turning the file
 * they uploaded into a document on the case. This class is where those four
 * are ordered, and the order is the whole point.
 *
 * REFUSALS COME FIRST, BECAUSE AFTER THE COMPLETION THERE IS NOTHING LEFT TO
 * REFUSE. A required field left empty is refused with the field named, per
 * ADR-050. An effect naming a handler nobody registered is refused with the
 * handler named, per ADR-102, rather than completing the task without the
 * effect — a task that was supposed to send the letter and quietly did not is
 * worse than one that would not complete.
 *
 * WHAT THIS CANNOT REFUSE, SAID PLAINLY. A task can also be completed from the
 * task page, from the inbox and over OpenRegister's own API, and dossiq is not
 * in the path of any of those. So the effects and the file publication hang
 * off the engine's {@see \OCA\OpenRegister\Event\TaskTerminalEvent} in
 * {@see \OCA\Dossiq\Listener\TaskCompletionEffectsListener}, which fires
 * however the task was completed, and this class only pre-checks. The two
 * halves are not a duplicate: one refuses while a person is still looking at
 * the form, the other runs whatever happened.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

use RuntimeException;

/**
 * Completing and claiming a task, with dossiq's own refusals in front.
 *
 * It owns BOTH verbs rather than only the completion, so the controller has
 * one collaborator for the task acts instead of two and never reaches past it
 * into the engine seam. A controller that talks to a service for one verb and
 * to the gateway for the next is where the two paths start disagreeing about
 * who may do what.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
class CaseTaskActions {

	/**
	 * Constructor.
	 *
	 * @param EngineTaskGateway $engineTasks The one seam onto the task engine.
	 * @param TaskEffects       $effects     Reads and checks what completing the task does.
	 */
	public function __construct(
		private readonly EngineTaskGateway $engineTasks,
		private readonly TaskEffects $effects,
	) {
	}//end __construct()

	/**
	 * Whether the task engine on this instance answers a claim act.
	 *
	 * Asked of the engine rather than assumed, and answered to the surface
	 * rather than only acted on: a claim button that does nothing would be
	 * worse than none, and a case type promising a candidate group the engine
	 * ignores would be worse still.
	 *
	 * @return boolean True when a task can be claimed.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function engineAnswersClaim(): bool {
		return $this->engineTasks->supportsClaim();
	}//end engineAnswersClaim()

	/**
	 * Read one task, or null when the engine has none by that id.
	 *
	 * @param string $taskId The task.
	 *
	 * @return array<string, mixed>|null The task.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function find(string $taskId): ?array {
		return $this->engineTasks->find(taskId: $taskId);
	}//end find()

	/**
	 * Let one candidate take an unclaimed task.
	 *
	 * The engine rules on whether this caller is in the task's candidate pool
	 * and refuses with its own reason, which is kept: a generic failure would
	 * throw away the only part a handler can act on.
	 *
	 * @param string $taskId The task.
	 * @param string $actor  Who is taking it.
	 *
	 * @return array{claimed: bool, task: string} What happened.
	 *
	 * @throws RuntimeException With the engine's own refusal.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function claim(string $taskId, string $actor): array {
		if ($this->engineTasks->claim(taskId: $taskId, actor: $actor) === false) {
			throw new RuntimeException($this->engineTasks->lastError());
		}

		return ['claimed' => true, 'task' => $taskId];
	}//end claim()

	/**
	 * Complete one task with the answers its form asked for.
	 *
	 * @param string               $taskId  The task.
	 * @param array<string, mixed> $data    The form answers, keyed by field.
	 * @param string               $outcome The outcome word recorded on the task.
	 * @param string               $actor   Who is completing it.
	 *
	 * @return array{completed: bool, task: string, case: string, state: string, isTerminal: bool} What happened.
	 *
	 * @throws RuntimeException Named refusals: `task_not_found`,
	 *                          `required_field:<field>`,
	 *                          `unresolvable_effect:<type>`, `complete_failed`.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function complete(string $taskId, array $data, string $outcome, string $actor): array {
		$task = $this->engineTasks->find(taskId: $taskId);
		if ($task === null) {
			throw new RuntimeException('task_not_found');
		}

		$missing = self::missingRequiredField(task: $task, data: $data);
		if ($missing !== '') {
			// The FIELD, not "a required field": a form of nine fields with
			// one empty is a message a handler can act on only when it says
			// which one.
			throw new RuntimeException('required_field:' . $missing);
		}

		$declared = $this->effects->declaredOn(task: $task);
		$unresolved = $this->effects->unresolved(effects: $declared);
		if ($unresolved !== []) {
			throw new RuntimeException('unresolvable_effect:' . $unresolved[0]);
		}

		$completed = $this->engineTasks->complete(
			taskId: $taskId,
			data: $data,
			outcome: $outcome,
			actor: $actor
		);
		if ($completed === false) {
			throw new RuntimeException($this->engineTasks->lastError());
		}

		return [
			'completed' => true,
			'task' => (string)($task['id'] ?? $taskId),
			'case' => (string)($task['objectUuid'] ?? ''),
			// The engine accepted the completion, so the task IS terminal, and
			// the answer says so in the engine's own two words. The surface
			// reads `isTerminal` to decide whether to confirm the task by name,
			// and a response that omitted it would complete the task and
			// silently show no confirmation — which reads as a click that did
			// nothing.
			'state' => 'completed',
			'isTerminal' => true,
		];
	}//end complete()

	/**
	 * The first required field of the task's form that the answers leave empty.
	 *
	 * The declaration is the task's own, under `metadata.form`, which is what
	 * dossiq wrote when the task was created and what OpenRegister renders it
	 * from. Reading the same declaration both surfaces read is what keeps the
	 * refusal and the form in agreement.
	 *
	 * @param array<string, mixed> $task The task.
	 * @param array<string, mixed> $data The answers.
	 *
	 * @return string The field's name, or '' when nothing required is missing.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public static function missingRequiredField(array $task, array $data): string {
		foreach (self::requiredFields(task: $task) as $name) {
			if (self::isBlank(answer: ($data[$name] ?? null)) === true) {
				return $name;
			}
		}

		return '';
	}//end missingRequiredField()

	/**
	 * The names of the fields the task's form requires, in declared order.
	 *
	 * @param array<string, mixed> $task The task.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	private static function requiredFields(array $task): array {
		$metadata = ($task['metadata'] ?? []);
		$form = [];
		if (is_array($metadata) === true) {
			$form = ($metadata['form'] ?? []);
		}

		$fields = [];
		if (is_array($form) === true) {
			$fields = ($form['fields'] ?? []);
		}

		if (is_array($fields) === false) {
			return [];
		}

		$required = [];
		foreach ($fields as $field) {
			if (is_array($field) === false || ($field['required'] ?? false) !== true) {
				continue;
			}

			$name = trim((string)($field['field'] ?? ''));
			if ($name !== '') {
				$required[] = $name;
			}
		}

		return $required;
	}//end requiredFields()

	/**
	 * Whether an answer is no answer at all.
	 *
	 * 🔑 `0` AND `false` ARE ANSWERS, which is why this is not `empty()`. A
	 * required amount answered with zero is answered, and telling the handler
	 * to fill in a field they filled in is how a form stops being trusted.
	 *
	 * @param mixed $answer What was given.
	 *
	 * @return boolean True when nothing was given.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	private static function isBlank(mixed $answer): bool {
		if ($answer === null || $answer === []) {
			return true;
		}

		return (is_string($answer) === true && trim($answer) === '');
	}//end isBlank()
}//end class
