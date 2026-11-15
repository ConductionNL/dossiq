<?php

/**
 * Dossiq withheld entries
 *
 * The list a publication records of what it withheld: one entry per document
 * withheld in full, holding its number in the inventory and the grounds it
 * was withheld on, and nothing else (decision 182: generic, configured per
 * case type). The Woo case type is the first user: it names `niet_openbaar`
 * as the withheld classification and `weigeringsgronden` as the grounds field.
 *
 * The inventory is every assessed document of the case in documentRef order,
 * numbered from 1. dossiq keeps no other inventory list, so this order is the
 * one both the recorded positions and any later list are read against.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/publication-withheld-list/spec.md#requirement-a-publication-records-what-it-withheld-req-pwl-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Publication;

/**
 * Builds the `{position, grounds}` entries of a publication's withheld list.
 *
 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/publication-withheld-list/spec.md#requirement-a-publication-records-what-it-withheld-req-pwl-001
 */
class WithheldEntries {

	/**
	 * The entries for the documents withheld in full.
	 *
	 * Only `position` and `grounds` are written: no title, file, file id,
	 * hash, document reference or text can travel on this path.
	 *
	 * @param array<int, array<string, mixed>> $assessments            Every assessment of the case.
	 * @param string                           $withheldValue          The classification that means withheld in full.
	 * @param string                           $groundsField           The assessment field holding the ground codes.
	 *
	 * @return list<array{position: int, grounds: list<string>}>
	 *
	 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/publication-withheld-list/spec.md#requirement-a-publication-records-what-it-withheld-req-pwl-001
	 */
	public function fromAssessments(array $assessments, string $withheldValue, string $groundsField): array {
		$inventory = array_values(
			array_filter(
				$assessments,
				static fn (mixed $row): bool => is_array($row) === true && (string)($row['documentRef'] ?? '') !== ''
			)
		);
		usort(
			$inventory,
			static fn (array $left, array $right): int => strcmp((string)$left['documentRef'], (string)$right['documentRef'])
		);

		$entries = [];
		foreach ($inventory as $index => $assessment) {
			if ((string)($assessment['classification'] ?? '') !== $withheldValue) {
				continue;
			}

			$entries[] = [
				'position' => ($index + 1),
				'grounds' => $this->codes(value: ($assessment[$groundsField] ?? [])),
			];
		}

		return $entries;
	}//end fromAssessments()

	/**
	 * The non-empty string codes of a ground list.
	 *
	 * @param mixed $value The stored ground list.
	 *
	 * @return list<string>
	 */
	private function codes(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$codes = [];
		foreach ($value as $code) {
			if (is_string($code) === true && trim($code) !== '') {
				$codes[] = trim($code);
			}
		}

		return $codes;
	}//end codes()
}//end class
