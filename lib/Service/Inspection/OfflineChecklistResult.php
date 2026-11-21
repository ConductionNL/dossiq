<?php

/**
 * An offline leaf checklist result, in the shape the other carried-over runs share.
 *
 * Pure. inspection-checklists-onto-task 4.2b: the offline field-inspection leaf
 * queued `checklistResult` rows with its own keys; the carry-over reads them
 * through this class.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

/**
 * Moves an offline leaf result onto the keys the other sources share.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
 */
final class OfflineChecklistResult {

	/**
	 * An offline leaf result in the shape the other sources share.
	 *
	 * The leaf queued `{inspectionRef, checklistTemplateRef, items[]}` with each
	 * item as `{questionId, answer, evidenceRefs, notes, answeredAt,
	 * gpsAtAnswer}`. Its answers move onto `itemId`, `value`, `photos` and
	 * `comment`; the planned inspection is kept as `inspection`; the latest
	 * `answeredAt` is the moment it finished. The case is not on the row: the
	 * caller sets `case` from the planned inspection.
	 *
	 * @param array<string, mixed> $object The `checklistResult` row.
	 *
	 * @return array<string, mixed> The row, normalised.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
	 */
	public function normalised(array $object): array {
		$items = [];
		$answered = '';
		$rows = [];
		if (is_array($object['items'] ?? null) === true) {
			$rows = $object['items'];
		}

		foreach ($rows as $raw) {
			if (is_array($raw) === false) {
				continue;
			}

			$items[] = $this->offlineItem(raw: $raw);
			$at = (string)($raw['answeredAt'] ?? '');
			if ($at > $answered) {
				$answered = $at;
			}
		}//end foreach

		$object['items'] = $items;
		if (trim((string)($object['inspectionRef'] ?? '')) !== '' && trim((string)($object['inspection'] ?? '')) === '') {
			$object['inspection'] = (string)$object['inspectionRef'];
		}

		if ($answered !== '' && trim((string)($object['completedAt'] ?? '')) === '') {
			$object['completedAt'] = $answered;
		}

		return $object;
	}//end normalised()

	/**
	 * One offline leaf answer on the keys the other sources share.
	 *
	 * @param array<string, mixed> $raw The leaf's item.
	 *
	 * @return array<string, mixed> The item: `itemId`, `value`, `photos`, and `comment` and `gpsAtAnswer` when given.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
	 */
	private function offlineItem(array $raw): array {
		$item = [
			'itemId' => (string)($raw['questionId'] ?? $raw['itemId'] ?? ''),
			'value' => ($raw['answer'] ?? $raw['value'] ?? null),
			'photos' => [],
		];
		if (is_array($raw['evidenceRefs'] ?? null) === true) {
			$item['photos'] = $raw['evidenceRefs'];
		}

		if (trim((string)($raw['notes'] ?? '')) !== '') {
			$item['comment'] = (string)$raw['notes'];
		}

		if (is_array($raw['gpsAtAnswer'] ?? null) === true) {
			$item['gpsAtAnswer'] = $raw['gpsAtAnswer'];
		}

		return $item;
	}//end offlineItem()
}//end class
