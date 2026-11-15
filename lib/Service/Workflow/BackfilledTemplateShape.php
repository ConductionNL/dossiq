<?php

/**
 * The steps and transitions of a backfilled workflow template.
 *
 * MigrateWorkflowDefinitions builds a "basis" template for every case type
 * from its status order. This class turns the ordered status types into the
 * template's embedded lists: one step per status that is not final, and one
 * transition from each status to the next. Names are translatable, so they
 * are read as text and never cast.
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
 * The steps and transitions of a template backfilled from ordered status types.
 *
 * One step per status that is not final, one transition between each status
 * and the next, each with its own identifier and a label read as text.
 *
 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
 */
class BackfilledTemplateShape {

	/**
	 * Constructor.
	 *
	 * @param LanguageMapText $text Reads a translatable name or description as one string.
	 */
	public function __construct(
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * Build the embedded step list from ordered statusType rows.
	 *
	 * @param array<int, array<string, mixed>> $statuses Ordered statusType rows
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	public function steps(array $statuses): array {
		$steps = [];
		foreach ($statuses as $status) {
			if ((bool)($status['isFinal'] ?? false) === true) {
				continue;
			}

			$steps[] = [
				'id' => $this->uuid(),
				'title' => $this->text->textOr(value: ($status['name'] ?? null), fallback: 'Stap'),
				'description' => $this->text->textOf(value: ($status['description'] ?? null)),
				'status' => (string)($status['id'] ?? ''),
				'order' => (int)($status['order'] ?? 0),
				'assigneeRole' => '',
				'isRequired' => false,
				'checklist' => [],
				'automaticActions' => [],
			];
		}

		return $steps;
	}//end steps()

	/**
	 * Build the embedded transition list from ordered statusType rows.
	 *
	 * @param array<int, array<string, mixed>> $statuses Ordered statusType rows
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	public function transitions(array $statuses): array {
		$transitions = [];
		$count = count($statuses);
		for ($i = 0; $i < ($count - 1); $i++) {
			$from = $statuses[$i];
			$to = $statuses[($i + 1)];

			$transitions[] = [
				'id' => $this->uuid(),
				'fromStatus' => (string)($from['id'] ?? ''),
				'toStatus' => (string)($to['id'] ?? ''),
				'label' => $this->text->textOr(value: ($to['name'] ?? null), fallback: 'Door'),
				'guards' => [],
				'automaticActions' => [],
				'allowedRoles' => [],
			];
		}

		return $transitions;
	}//end transitions()

	/**
	 * Generate a UUID v4 for embedded step / transition identifiers.
	 *
	 * @return string
	 */
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		$hex = bin2hex($bytes);
		return substr($hex, 0, 8) . '-'
			. substr($hex, 8, 4) . '-'
			. substr($hex, 12, 4) . '-'
			. substr($hex, 16, 4) . '-'
			. substr($hex, 20, 12);
	}//end uuid()
}//end class
