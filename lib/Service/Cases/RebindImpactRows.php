<?php

/**
 * Dossiq RebindImpactRows.
 *
 * The rows a rebind impact shows: a ported answer, a dropped one and a
 * required field, each described the same way the dialog and the journal
 * read them.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Service\CaseTypeStore;

/**
 * Builds the ported, dropped and required rows of CaseRebindImpact.
 *
 * A plain helper: CaseRebindImpact builds it from its own store and converter.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
final class RebindImpactRows {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore        $store     Row ids and references.
	 * @param RebindValueConverter $converter The kind, choices and fit of a field.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly RebindValueConverter $converter,
	) {
	}//end __construct()

	/**
	 * One ported row.
	 *
	 * @param RebindAnswer  $answer  The answer.
	 * @param RebindLanding $landing The field and value it lands with.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function portedRow(RebindAnswer $answer, RebindLanding $landing): array {
		$sourceKind = $this->sourceKind(source: $answer->source);
		$targetKind = $this->converter->kindOf(definition: $landing->target);
		$changed = $sourceKind !== $targetKind || $landing->value !== $answer->entry['value'];

		return [
			'source' => $answer->entry['name'],
			'target' => trim((string)($landing->target['name'] ?? '')),
			'targetDefinition' => $this->store->rowId(row: $landing->target),
			'sourceKind' => $sourceKind,
			'targetKind' => $targetKind,
			'value' => $answer->entry['value'],
			'newValue' => $landing->value,
			'mapping' => $this->mapping(landing: $landing, changed: $changed),
		];
	}//end portedRow()

	/**
	 * How a ported answer was mapped: `remapped`, `converted` or `same`.
	 *
	 * @param RebindLanding $landing Where it lands.
	 * @param boolean       $changed Whether its kind or value changes on the way.
	 *
	 * @return string The mapping.
	 */
	private function mapping(RebindLanding $landing, bool $changed): string {
		if ($landing->remapped === true) {
			return 'remapped';
		}

		if ($changed === true) {
			return 'converted';
		}

		return 'same';
	}//end mapping()

	/**
	 * One dropped row, before its candidates are known.
	 *
	 * The reason is `absent` when the target has no field of the answer's
	 * name, and `type` when it has one the value does not fit.
	 *
	 * @param RebindAnswer         $answer The answer.
	 * @param array<string, mixed> $target The target field of the answer's name, or [] when there is none.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function droppedRow(RebindAnswer $answer, array $target): array {
		$reason = 'absent';
		if ($target !== []) {
			$reason = 'type';
		}

		return [
			'name' => $answer->entry['name'],
			'definition' => $answer->entry['definition'],
			'kind' => $this->sourceKind(source: $answer->source),
			'value' => $answer->entry['value'],
			'reason' => $reason,
			'candidates' => [],
		];
	}//end droppedRow()

	/**
	 * One required row: the field, and the answer given for it.
	 *
	 * @param array<string, mixed> $target The required field.
	 * @param string               $value  The answer given, as text.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function requiredRow(array $target, string $value): array {
		$normalized = $this->converter->fit(value: $value, source: [], target: $target);

		return [
			'name' => trim((string)($target['name'] ?? '')),
			'definition' => $this->store->rowId(row: $target),
			'kind' => $this->converter->kindOf(definition: $target),
			'choices' => $this->converter->choicesOf(definition: $target),
			'description' => trim((string)($target['description'] ?? '')),
			'value' => $value,
			'normalized' => $normalized,
			'valid' => ($normalized !== null),
		];
	}//end requiredRow()

	/**
	 * The kind of an answer, `text` when the source type declares no field for it.
	 *
	 * @param array<string, mixed> $source The definition, or [].
	 *
	 * @return string The kind.
	 */
	private function sourceKind(array $source): string {
		if ($source === []) {
			return 'text';
		}

		return $this->converter->kindOf(definition: $source);
	}//end sourceKind()
}//end class
