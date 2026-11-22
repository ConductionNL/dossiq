<?php

/**
 * The completeness items a case type declares.
 *
 * What a case type's intake requirements list as the parts of a complete
 * application, read without a store: each item with a trimmed key and label
 * and its usable options, malformed ones left out.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

/**
 * Reads the declared completeness items of a case type.
 *
 * @spec openspec/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CompletenessItems {

	/**
	 * The options an item may carry, in their fixed order.
	 *
	 * @var string[]
	 */
	private const OPTIONS = ['documentType', 'documentTitleContains', 'field', 'evidence'];

	/**
	 * The declared items of a case type, each with a key and a label.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, array<string, string>> The items, malformed ones left out.
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function declaredBy(array $caseType): array {
		$declared = ($caseType[IntakeRequirements::PROPERTY]['completenessItems'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$items = [];
		foreach ($declared as $item) {
			$clean = $this->cleanItem(item: $item);
			if ($clean !== null) {
				$items[] = $clean;
			}
		}

		return $items;
	}//end declaredBy()

	/**
	 * One declared item with a trimmed key and label and its usable options, or null when malformed.
	 *
	 * @param mixed $item The declared entry.
	 *
	 * @return array<string, string>|null
	 */
	private function cleanItem(mixed $item): ?array {
		if (is_array($item) === false) {
			return null;
		}

		$key   = trim((string)($item['key'] ?? ''));
		$label = trim((string)($item['label'] ?? ''));
		if ($key === '' || $label === '') {
			return null;
		}

		return array_merge(['key' => $key, 'label' => $label], $this->itemOptions(item: $item));
	}//end cleanItem()

	/**
	 * The non-blank string options of a declared item, trimmed, in their fixed order.
	 *
	 * @param array<string, mixed> $item The declared item.
	 *
	 * @return array<string, string>
	 */
	private function itemOptions(array $item): array {
		$options = [];
		foreach (self::OPTIONS as $option) {
			if (is_string($item[$option] ?? null) === true && trim($item[$option]) !== '') {
				$options[$option] = trim($item[$option]);
			}
		}

		return $options;
	}//end itemOptions()
}//end class
