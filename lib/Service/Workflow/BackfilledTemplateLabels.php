<?php

/**
 * The labels of a backfilled workflow template, read as text.
 *
 * MigrateWorkflowDefinitions builds a "basis" template for every case type
 * from its status order. It cast the case type title and the status names to
 * strings, and both are translatable: OpenRegister hands them back as a
 * language map (`{"nl": "Ontvangen"}`), and a cast of a map is "Array". So
 * the shipped templates were titled "Array — basis", every step was "Array",
 * and every move a handler could make was a button labelled "Array". Found
 * live on review-check, 11 October 2026 (N-2).
 *
 * This class names a backfilled template, and heals one that was stored with
 * those labels. It only touches a template the backfill wrote (its
 * description is {@see self::MARKER}) and only a label that is literally
 * "Array", so a template an administrator renamed keeps its name.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

use OCA\Dossiq\Service\Support\LanguageMapText;

/**
 * Names a backfilled template and heals one stored with "Array" labels.
 *
 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
 */
class BackfilledTemplateLabels {

	/**
	 * The description every backfilled template carries.
	 *
	 * @var string
	 */
	public const MARKER = 'Backfilled from implicit statusType ordering.';

	/**
	 * What a string cast of a language map gives.
	 *
	 * @var string
	 */
	public const CAST_OF_A_MAP = 'Array';

	/**
	 * The step title when a status has no name.
	 *
	 * @var string
	 */
	public const STEP_FALLBACK = 'Stap';

	/**
	 * The move label when the target status has no name.
	 *
	 * @var string
	 */
	public const MOVE_FALLBACK = 'Door';

	/**
	 * Constructor.
	 *
	 * @param LanguageMapText $text A translatable title or name as one string.
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	public function __construct(
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * The title of the basis template for a case type.
	 *
	 * @param mixed $caseTypeTitle The case type title, a string or a language map.
	 *
	 * @return string The template title.
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	public function titleFor(mixed $caseTypeTitle): string {
		return $this->text->textOr(value: $caseTypeTitle, fallback: 'Workflow') . ' (basis)';
	}//end titleFor()

	/**
	 * The fields to write onto a stored backfilled template, or [].
	 *
	 * @param array<string, mixed>               $template     The stored template.
	 * @param array<string, mixed>               $caseType     Its case type.
	 * @param array<string, array<string, mixed>> $statusesById The case type's statuses, by id.
	 *
	 * @return array<string, mixed> The healed fields; [] when nothing needs it.
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	public function healed(array $template, array $caseType, array $statusesById): array {
		if (($template['description'] ?? null) !== self::MARKER) {
			return [];
		}

		$changes = $this->healedTitle(template: $template, caseType: $caseType);
		foreach ($this->healers(statusesById: $statusesById) as $key => $heal) {
			$list = $this->healedList(raw: ($template[$key] ?? null), heal: $heal);
			if ($list !== null) {
				$changes[$key] = $list;
			}
		}

		return $changes;
	}//end healed()

	/**
	 * How each stored list is healed, by the template field that holds it, steps first.
	 *
	 * @param array<string, array<string, mixed>> $statusesById The case type's statuses, by id.
	 *
	 * @return array<string, callable> The healer of one entry, by field.
	 */
	private function healers(array $statusesById): array {
		return [
			'steps' => fn (array $step): array => $this->healedStep(step: $step, statusesById: $statusesById),
			'transitions' => fn (array $move): array => $this->healedMove(move: $move, statusesById: $statusesById),
		];
	}//end healers()

	/**
	 * The title to write when the stored one is a cast of a map, as a change set.
	 *
	 * @param array<string, mixed> $template The stored template.
	 * @param array<string, mixed> $caseType Its case type.
	 *
	 * @return array<string, string> ['title' => ...], or [] when the title is fine.
	 */
	private function healedTitle(array $template, array $caseType): array {
		if (str_starts_with((string)($template['title'] ?? ''), self::CAST_OF_A_MAP . ' ') === false) {
			return [];
		}

		return ['title' => $this->titleFor(caseTypeTitle: ($caseType['title'] ?? null))];
	}//end healedTitle()

	/**
	 * One step with its "Array" title and description read from its status.
	 *
	 * @param array<string, mixed>                $step         The stored step.
	 * @param array<string, array<string, mixed>> $statusesById The case type's statuses, by id.
	 *
	 * @return array<string, mixed> The step.
	 */
	private function healedStep(array $step, array $statusesById): array {
		$status = ($statusesById[(string)($step['status'] ?? '')] ?? []);
		if (($step['title'] ?? null) === self::CAST_OF_A_MAP) {
			$step['title'] = $this->text->textOr(value: ($status['name'] ?? null), fallback: self::STEP_FALLBACK);
		}

		if (($step['description'] ?? null) === self::CAST_OF_A_MAP) {
			$step['description'] = $this->text->textOf(value: ($status['description'] ?? null));
		}

		return $step;
	}//end healedStep()

	/**
	 * One transition with its "Array" label read from its target status.
	 *
	 * @param array<string, mixed>                $move         The stored transition.
	 * @param array<string, array<string, mixed>> $statusesById The case type's statuses, by id.
	 *
	 * @return array<string, mixed> The transition.
	 */
	private function healedMove(array $move, array $statusesById): array {
		if (($move['label'] ?? null) === self::CAST_OF_A_MAP) {
			$target        = ($statusesById[(string)($move['toStatus'] ?? '')] ?? []);
			$move['label'] = $this->text->textOr(value: ($target['name'] ?? null), fallback: self::MOVE_FALLBACK);
		}

		return $move;
	}//end healedMove()

	/**
	 * A stored JSON list with each entry healed, or null when nothing changed.
	 *
	 * The backfill stores steps and transitions as JSON strings, so a healed
	 * list is written back the same way.
	 *
	 * @param mixed    $raw  The stored list, a JSON string or an array.
	 * @param callable $heal Heals one entry.
	 *
	 * @return string|null The healed list as JSON, or null when nothing changed.
	 */
	private function healedList(mixed $raw, callable $heal): ?string {
		$list = self::storedList(raw: $raw);
		if ($list === null) {
			return null;
		}

		$healed = self::healEach(list: $list, heal: $heal);
		if ($healed === null) {
			return null;
		}

		return (string)json_encode(array_values($healed), (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}//end healedList()

	/**
	 * A stored list as an array, decoding a JSON string; null when it is no list.
	 *
	 * @param mixed $raw The stored list.
	 *
	 * @return array<int|string, mixed>|null The list.
	 */
	private static function storedList(mixed $raw): ?array {
		$list = $raw;
		if (is_string($raw) === true) {
			$list = json_decode($raw, true);
		}

		if (is_array($list) === false) {
			return null;
		}

		return $list;
	}//end storedList()

	/**
	 * Every array entry healed in place, or null when no entry changed.
	 *
	 * @param array<int|string, mixed> $list The list.
	 * @param callable                 $heal Heals one entry.
	 *
	 * @return array<int|string, mixed>|null The healed list.
	 */
	private static function healEach(array $list, callable $heal): ?array {
		$changed = false;
		foreach ($list as $index => $entry) {
			$healed = self::healedEntry(entry: $entry, heal: $heal);
			if ($healed !== $entry) {
				$list[$index] = $healed;
				$changed      = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $list;
	}//end healEach()

	/**
	 * One entry healed when it is an array, as it was otherwise.
	 *
	 * @param mixed    $entry The stored entry.
	 * @param callable $heal  Heals one entry.
	 *
	 * @return mixed The entry.
	 */
	private static function healedEntry(mixed $entry, callable $heal): mixed {
		if (is_array($entry) === false) {
			return $entry;
		}

		return $heal($entry);
	}//end healedEntry()
}//end class
