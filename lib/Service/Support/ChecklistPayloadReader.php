<?php

/**
 * Procest checklist payload reader.
 *
 * Knows the SHAPE of an inspection checklist payload — nothing about what the
 * answers mean. Split out of `ChecklistService` (REQ-003) so that service holds
 * only the arithmetic the requirement names, and so the shape rules live in one
 * place rather than being restated by every consumer.
 *
 * The rules encoded here are read from the shipped `inspectionChecklistTemplate`
 * and `inspectionChecklistRun` schemas, and this class is now their single
 * in-code statement:
 *   - items may be flat (`items`) or sectioned (`sections[].items`);
 *   - a run carries its frozen template under `templateSnapshot`;
 *   - an item's id is `id`, else `order`, else its position in the list.
 *
 * Pure: no I/O, no state.
 *
 * @category Service
 * @package  OCA\Procest\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Support;

/**
 * Reads items and responses out of a checklist payload.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 */
class ChecklistPayloadReader {
	/**
	 * Index every item in the payload by its id.
	 *
	 * @param array<string, mixed> $checklist The run payload.
	 *
	 * @return array<string, array<string, mixed>> Items keyed by id.
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function items(array $checklist): array {
		$source = $checklist;
		if (is_array($checklist['templateSnapshot'] ?? null) === true) {
			$source = $checklist['templateSnapshot'];
		}

		$out = [];
		$this->collect(items: ($source['items'] ?? []), out: $out);

		$sections = ($source['sections'] ?? []);
		if (is_array($sections) === true) {
			foreach ($sections as $section) {
				if (is_array($section) === true) {
					$this->collect(items: ($section['items'] ?? []), out: $out);
				}
			}
		}

		return $out;
	}//end items()

	/**
	 * Read the response rows out of a payload, discarding malformed entries.
	 *
	 * @param array<string, mixed> $checklist The run payload.
	 *
	 * @return array<int, array<string, mixed>> The responses.
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function responses(array $checklist): array {
		$responses = ($checklist['responses'] ?? []);
		if (is_array($responses) === false) {
			return [];
		}

		$out = [];
		foreach ($responses as $response) {
			if (is_array($response) === true) {
				$out[] = $response;
			}
		}

		return $out;
	}//end responses()

	/**
	 * Index responses by item id.
	 *
	 * A later row for the same item wins, so a re-answered item is judged on
	 * its latest answer.
	 *
	 * @param array<string, mixed> $checklist The run payload.
	 *
	 * @return array<string, array<string, mixed>> Responses keyed by item id.
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function responsesByItemId(array $checklist): array {
		$out = [];
		foreach ($this->responses(checklist: $checklist) as $response) {
			$itemId = (string)($response['itemId'] ?? '');
			if ($itemId !== '') {
				$out[$itemId] = $response;
			}
		}

		return $out;
	}//end responsesByItemId()

	/**
	 * Decide whether a response carries an actual answer.
	 *
	 * A response row that exists but holds nothing usable is NOT an answer —
	 * treating it as one is how a run of blank required items would report
	 * itself complete.
	 *
	 * @param array<string, mixed> $response The submitted answer.
	 *
	 * @return bool True when something was answered.
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function isAnswered(array $response): bool {
		foreach (['value', 'numericValue', 'choice'] as $key) {
			$value = ($response[$key] ?? null);
			if ($value !== null && $value !== '') {
				return true;
			}
		}

		$photos = ($response['photos'] ?? []);

		return (is_array($photos) === true && $photos !== []);
	}//end isAnswered()

	/**
	 * Human-readable name for an item, for use in a violation message.
	 *
	 * @param array<string, mixed> $item The item definition.
	 * @param string $itemId Fallback identifier.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function label(array $item, string $itemId): string {
		$label = (string)($item['label'] ?? '');
		if ($label !== '') {
			return $label;
		}

		return $itemId;
	}//end label()

	/**
	 * Add a list of items to the id-keyed index.
	 *
	 * @param mixed $items The candidate item list.
	 * @param array<string, array<string, mixed>> $out The index, updated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	private function collect(mixed $items, array &$out): void {
		if (is_array($items) === false) {
			return;
		}

		foreach ($items as $index => $item) {
			if (is_array($item) === false) {
				continue;
			}

			$id = (string)($item['id'] ?? ($item['order'] ?? $index));
			$out[$id] = $item;
		}
	}//end collect()
}//end class
