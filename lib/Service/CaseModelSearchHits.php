<?php

/**
 * Read the rows OpenRegister answers a caseType or caseModel lookup with.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The first plain row of a lookup, and a published caseModel with its planItems decoded.
 *
 * Pure: no I/O. Moved out of {@see CasePlanProjectionService} unchanged
 * (method-decomposition).
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
final class CaseModelSearchHits {

	/**
	 * The first model a search answered, its planItems decoded, or null when it answered none.
	 *
	 * @param mixed $found Whatever `searchObjects()` answered.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function publishedModelOf(mixed $found): ?array {
		$model = $this->firstAsArray(value: $found);
		if ($model === null) {
			return null;
		}

		return $this->decodePlanItems(model: $model);
	}//end publishedModelOf()

	/**
	 * Decode `planItems` when OpenRegister hands it back as JSON text.
	 *
	 * The property is declared `type: array`, but OpenRegister's magic-mapper
	 * read path answers some rows with the text they were written as; the
	 * retiring loader decoded it in the same place.
	 *
	 * @param array<string, mixed> $model The caseModel as read.
	 *
	 * @return array<string, mixed> The caseModel with a list-shaped `planItems`.
	 */
	private function decodePlanItems(array $model): array {
		$planItems = ($model['planItems'] ?? null);
		if (is_string($planItems) === false || $planItems === '') {
			return $model;
		}

		$decoded = json_decode($planItems, true);
		$model['planItems'] = [];
		if (is_array($decoded) === true) {
			$model['planItems'] = $decoded;
		}

		return $model;
	}//end decodePlanItems()

	/**
	 * The first result of a search, as a plain array.
	 *
	 * @param mixed $value Whatever `searchObjects()` answered.
	 *
	 * @return array<string, mixed>|null The first row, or null when there is none.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function firstAsArray(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		foreach ($value as $item) {
			$row = $this->rowOf(item: $item);
			if ($row !== []) {
				return $row;
			}
		}

		return null;
	}//end firstAsArray()

	/**
	 * One search hit as a plain array: the array itself, or what a serialisable object answers.
	 *
	 * @param mixed $item One hit.
	 *
	 * @return array<string, mixed> The row, empty when the hit carries none.
	 */
	private function rowOf(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
			$serialized = $item->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end rowOf()
}//end class
