<?php

/**
 * One task's declaration on a case type, normalised.
 *
 * A case type names the tasks in its process, and until now it could say
 * nothing about any of them: not what has to be filled in, not who may pick it
 * up, not how long it has, and not what finishing it does. The declaration
 * block this class reads is that missing half, and it is ONE block per task
 * rather than five maps keyed by task name, because five parallel maps drift
 * the moment a task is renamed and nothing says so.
 *
 * It lives on the workflow step, which is where the case type already names
 * the task:
 *
 * ```json
 * {
 *   "id": "…", "title": "Hoor de belanghebbende", "status": "<statusType>",
 *   "task": {
 *     "enabled": true,
 *     "leadTimeDays": 10,
 *     "candidateGroups": ["Juridische Zaken"],
 *     "candidateUsers": [],
 *     "form": {"kind": "fields", "schema": "case",
 *              "fields": [{"field": "verslag", "required": true}]},
 *     "effects": [{"type": "sendEmail", "template": "…"}]
 *   }
 * }
 * ```
 *
 * 🔑 THE FORM IS OPENREGISTER'S SHAPE, NOT A SECOND ONE. `form` is written
 * onto the engine task's `metadata.form` verbatim, and
 * `OCA\OpenRegister\Service\Task\TaskFormResolver` resolves and renders it
 * from there. Inventing a dossiq form shape here would mean translating
 * between two vocabularies on every read, and the engine would validate a
 * declaration it had never seen.
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

/**
 * Reads and normalises the per-task declaration block of a workflow step.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
final class TaskDeclaration {

	/**
	 * The key the block lives under on a workflow step.
	 *
	 * @var string
	 */
	public const BLOCK = 'task';

	/**
	 * A declaration that asks for nothing, for a step carrying no block.
	 *
	 * A step with no block behaves exactly as it did before this change:
	 * the task runs, it has no form, no candidates, no lead time of its own
	 * and no effects. That is what makes the block additive rather than a
	 * migration of every case type on every instance.
	 *
	 * @var array<string, mixed>
	 */
	public const NONE = [
		'enabled' => true,
		'leadTimeDays' => 0,
		'candidateGroups' => [],
		'candidateUsers' => [],
		'form' => null,
		'effects' => [],
	];

	/**
	 * Normalise the declaration of one workflow step.
	 *
	 * 🔴 IT READS ONE KEY. A `candidateGroups` written beside `task` rather
	 * than inside it is IGNORED rather than honoured, deliberately: reading
	 * both spellings is how the two drift into disagreeing, and an ignored
	 * key is caught by {@see TaskDeclarationValidator}, which refuses to
	 * publish a step carrying one.
	 *
	 * @param array<string, mixed> $step The workflow step.
	 *
	 * @return array{enabled: bool, leadTimeDays: int, candidateGroups: array<int, string>, candidateUsers: array<int, string>, form: array<string, mixed>|null, effects: array<int, array<string, mixed>>} The declaration.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public static function of(array $step): array {
		$block = ($step[self::BLOCK] ?? null);
		if (is_array($block) === false) {
			return self::NONE;
		}

		return [
			// Absent means on. A task nobody has configured is a task that
			// runs, which is what every case type authored before this block
			// existed expects.
			'enabled' => (($block['enabled'] ?? true) !== false),
			'leadTimeDays' => max(0, (int)($block['leadTimeDays'] ?? 0)),
			'candidateGroups' => self::names(value: ($block['candidateGroups'] ?? [])),
			'candidateUsers' => self::names(value: ($block['candidateUsers'] ?? [])),
			'form' => self::form(value: ($block['form'] ?? null)),
			'effects' => self::effects(value: ($block['effects'] ?? [])),
		];
	}//end of()

	/**
	 * The title a step declares, which is the name its task carries.
	 *
	 * @param array<string, mixed> $step The workflow step.
	 *
	 * @return string The title, trimmed, or the empty string.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public static function titleOf(array $step): string {
		return trim((string)($step['title'] ?? ''));
	}//end titleOf()

	/**
	 * The statusType a step belongs to.
	 *
	 * @param array<string, mixed> $step The workflow step.
	 *
	 * @return string The statusType uuid, or the empty string.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public static function statusOf(array $step): string {
		return trim((string)($step['status'] ?? ''));
	}//end statusOf()

	/**
	 * A list of plain names, with the blanks and the non-scalars dropped.
	 *
	 * @param mixed $value The raw list.
	 *
	 * @return array<int, string> The names.
	 */
	private static function names(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$names = [];
		foreach ($value as $entry) {
			if (is_string($entry) === false && is_numeric($entry) === false) {
				continue;
			}

			$name = trim((string)$entry);
			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}

		return $names;
	}//end names()

	/**
	 * The form declaration, or null when the step declares none.
	 *
	 * Passed through rather than rebuilt: the engine owns this shape and
	 * validates it, and a dossiq copy of its field list would be a second
	 * description of the same form that could disagree with the first.
	 *
	 * @param mixed $value The raw block.
	 *
	 * @return array<string, mixed>|null The declaration.
	 */
	private static function form(mixed $value): ?array {
		if (is_array($value) === false || $value === []) {
			return null;
		}

		return $value;
	}//end form()

	/**
	 * The declared effects, each one an action reference carrying a type.
	 *
	 * An entry with no `type` names no handler, so it can neither be run nor
	 * refused by name. It is dropped here and refused at publish, which is
	 * the only moment a person is present to fix it.
	 *
	 * @param mixed $value The raw list.
	 *
	 * @return array<int, array<string, mixed>> The effects.
	 */
	private static function effects(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$effects = [];
		foreach ($value as $effect) {
			if (is_array($effect) === false) {
				continue;
			}

			$type = trim((string)($effect['type'] ?? ''));
			if ($type === '') {
				continue;
			}

			$effect['type'] = $type;
			$effects[] = $effect;
		}

		return $effects;
	}//end effects()
}//end class
