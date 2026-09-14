<?php

/**
 * A task declaration is refused at publish, where a person can still fix it.
 *
 * Publishing is the last moment somebody is present. After it, an effect
 * naming a handler nobody registered is a task that cannot be completed, and a
 * candidate group nobody has is a task that reaches no team, both discovered
 * by the handler who needed the work to move rather than by the administrator
 * who wrote it.
 *
 * 🔴 EVERY REFUSAL NAMES WHAT WAS MISSING AND WHICH TASK IT WAS ON. "The
 * workflow could not be published" is the message this app already gives, and
 * it is the reason an administrator opens a ticket instead of fixing a typo.
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

use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use OCP\IGroupManager;

/**
 * Refuses a workflow whose task declarations name something that is not there.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
class TaskDeclarationValidator {

	/**
	 * The keys that belong INSIDE the block and are meaningless beside it.
	 *
	 * A `leadTimeDays` written next to `task` rather than in it is not read,
	 * and an unread declaration is the silent failure this whole change is
	 * about. So it is refused by name rather than ignored.
	 *
	 * @var array<int, string>
	 */
	private const MISPLACED = ['leadTimeDays', 'candidateGroups', 'candidateUsers', 'effects'];

	/**
	 * The two form kinds OpenRegister's task form resolver knows.
	 *
	 * @var array<int, string>
	 */
	private const FORM_KINDS = ['fields', 'external'];

	/**
	 * Constructor.
	 *
	 * @param ActionHandlerRegistry $handlers The registry an effect names a handler from.
	 * @param IGroupManager         $groups   Resolves a candidate group.
	 */
	public function __construct(
		private readonly ActionHandlerRegistry $handlers,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * Every refusal the steps of one workflow carry, in reading order.
	 *
	 * @param array<int, array<string, mixed>> $steps The workflow's steps.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The refusals; empty when it may be published.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public function refusals(array $steps): array {
		$refusals = [];
		foreach ($steps as $index => $step) {
			if (is_array($step) === false) {
				continue;
			}

			$refusals = array_merge($refusals, $this->refusalsForStep(step: $step, index: (int)$index));
		}

		return $refusals;
	}//end refusals()

	/**
	 * The refusals of one step.
	 *
	 * @param array<string, mixed> $step  The step.
	 * @param integer              $index Its position, for the path.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The refusals.
	 */
	private function refusalsForStep(array $step, int $index): array {
		$task = TaskDeclaration::titleOf(step: $step);
		if ($task === '') {
			$task = 'step ' . $index;
		}

		$path = sprintf('steps[%d].%s', $index, TaskDeclaration::BLOCK);
		$refusals = $this->misplaced(step: $step, task: $task, index: $index);

		if (is_array(($step[TaskDeclaration::BLOCK] ?? null)) === false) {
			return $refusals;
		}

		$declaration = TaskDeclaration::of(step: $step);

		return array_merge(
			$refusals,
			$this->formRefusals(form: $declaration['form'], task: $task, path: $path . '.form'),
			$this->groupRefusals(groups: $declaration['candidateGroups'], task: $task, path: $path . '.candidateGroups'),
			$this->effectRefusals(effects: $declaration['effects'], task: $task, path: $path . '.effects'),
		);
	}//end refusalsForStep()

	/**
	 * A declaration key written beside the block instead of inside it.
	 *
	 * @param array<string, mixed> $step  The step.
	 * @param string               $task  The task's name.
	 * @param integer              $index Its position.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The refusals.
	 */
	private function misplaced(array $step, string $task, int $index): array {
		$refusals = [];
		foreach (self::MISPLACED as $key) {
			if (array_key_exists($key, $step) === false) {
				continue;
			}

			$refusals[] = self::refusal(
				path: sprintf('steps[%d].%s', $index, $key),
				code: 'misplaced_task_key',
				message: sprintf(
					'Task "%s" declares "%s" beside its task block instead of inside it, where nothing reads it.',
					$task,
					$key
				)
			);
		}

		return $refusals;
	}//end misplaced()

	/**
	 * Refuse a form that cannot be resolved, naming the form and the task.
	 *
	 * @param array<string, mixed>|null $form The declared form.
	 * @param string                    $task The task's name.
	 * @param string                    $path The path for the refusal.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The refusals.
	 */
	private function formRefusals(?array $form, string $task, string $path): array {
		if ($form === null) {
			return [];
		}

		$kind = trim((string)($form['kind'] ?? ''));
		if (in_array($kind, self::FORM_KINDS, true) === false) {
			return [
				self::refusal(
					path: $path . '.kind',
					code: 'unresolvable_form',
					message: sprintf(
						'Task "%s" names a form of kind "%s", which does not exist. Use one of: %s.',
						$task,
						$kind,
						implode(', ', self::FORM_KINDS)
					)
				),
			];
		}

		if ($kind === 'external') {
			return $this->externalFormRefusals(form: $form, task: $task, path: $path);
		}

		return $this->fieldFormRefusals(form: $form, task: $task, path: $path);
	}//end formRefusals()

	/**
	 * A bound Forms form needs an id to be bound to.
	 *
	 * @param array<string, mixed> $form The declared form.
	 * @param string               $task The task's name.
	 * @param string               $path The path for the refusal.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The refusals.
	 */
	private function externalFormRefusals(array $form, string $task, string $path): array {
		if ((int)($form['formId'] ?? 0) > 0) {
			return [];
		}

		return [
			self::refusal(
				path: $path . '.formId',
				code: 'unresolvable_form',
				message: sprintf('Task "%s" names an external form without a form id, so no form can be opened.', $task)
			),
		];
	}//end externalFormRefusals()

	/**
	 * A field form names a schema and at least one field of it.
	 *
	 * Whether each field EXISTS is the engine's question, asked on every read
	 * against the live schema, and answered there with the field's own reason.
	 * Repeating it here would be the second authority that eventually refuses
	 * a form the engine renders.
	 *
	 * @param array<string, mixed> $form The declared form.
	 * @param string               $task The task's name.
	 * @param string               $path The path for the refusal.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The refusals.
	 */
	private function fieldFormRefusals(array $form, string $task, string $path): array {
		$refusals = [];
		if (trim((string)($form['schema'] ?? '')) === '') {
			$refusals[] = self::refusal(
				path: $path . '.schema',
				code: 'unresolvable_form',
				message: sprintf('Task "%s" declares a form of fields without naming the schema they belong to.', $task)
			);
		}

		$fields = ($form['fields'] ?? []);
		if (is_array($fields) === false || $fields === []) {
			$refusals[] = self::refusal(
				path: $path . '.fields',
				code: 'unresolvable_form',
				message: sprintf('Task "%s" declares a form with no fields, which asks the handler for nothing.', $task)
			);

			return $refusals;
		}

		foreach ($fields as $position => $field) {
			$name = '';
			if (is_array($field) === true) {
				$name = trim((string)($field['field'] ?? ''));
			}

			if ($name === '') {
				$refusals[] = self::refusal(
					path: sprintf('%s.fields[%d].field', $path, (int)$position),
					code: 'unresolvable_form',
					message: sprintf('Task "%s" declares a form field with no name.', $task)
				);
			}
		}

		return $refusals;
	}//end fieldFormRefusals()

	/**
	 * Refuse a candidate group nobody has.
	 *
	 * @param array<int, string> $groups The declared groups.
	 * @param string             $task   The task's name.
	 * @param string             $path   The path for the refusal.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The refusals.
	 */
	private function groupRefusals(array $groups, string $task, string $path): array {
		$refusals = [];
		foreach ($groups as $position => $group) {
			if ($this->groups->groupExists($group) === true) {
				continue;
			}

			$refusals[] = self::refusal(
				path: sprintf('%s[%d]', $path, (int)$position),
				code: 'unresolvable_group',
				message: sprintf('Task "%s" names the candidate group "%s", which does not exist.', $task, $group)
			);
		}

		return $refusals;
	}//end groupRefusals()

	/**
	 * Refuse an effect naming a handler the registry does not have.
	 *
	 * @param array<int, array<string, mixed>> $effects The declared effects.
	 * @param string                           $task    The task's name.
	 * @param string                           $path    The path for the refusal.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The refusals.
	 */
	private function effectRefusals(array $effects, string $task, string $path): array {
		$known = $this->handlers->getRegisteredTypes();
		$refusals = [];
		foreach ($effects as $position => $effect) {
			$type = (string)$effect['type'];
			if (in_array($type, $known, true) === true) {
				continue;
			}

			$refusals[] = self::refusal(
				path: sprintf('%s[%d].type', $path, (int)$position),
				code: 'unresolvable_effect',
				message: sprintf(
					'Task "%s" declares the effect "%s", which no handler answers to. Known effects: %s.',
					$task,
					$type,
					implode(', ', $known)
				)
			);
		}

		return $refusals;
	}//end effectRefusals()

	/**
	 * Build one refusal record, in the shape the step config validator uses.
	 *
	 * @param string $path    The path to the bad value.
	 * @param string $code    The stable code.
	 * @param string $message What is missing, and on which task.
	 *
	 * @return array{path: string, code: string, message: string} The refusal.
	 */
	private static function refusal(string $path, string $code, string $message): array {
		return ['path' => $path, 'code' => $code, 'message' => $message];
	}//end refusal()
}//end class
