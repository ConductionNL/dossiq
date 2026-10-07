<?php

/**
 * Dossiq case answer reader.
 *
 * The answers a case carries in `case.properties`, read the way the register
 * stores them.
 *
 * 🔴 `case.properties` IS A LIST, NOT A MAP. The register declares it as an
 * array of `{propertyDefinition, name, value}` entries, which is what the
 * `FoldCasePropertiesOntoCase` repair writes, what `RequiredFieldGuard` reads
 * and what the Woo intake creates. The rebind used to read it as a name-keyed
 * map, so every live case looked like it answered nothing, and a rebind wrote
 * a map onto an array property. A legacy map is still read here.
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
 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Service\CaseTypeStore;

/**
 * Read a case's answers, from the register's list or a legacy map.
 *
 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
 */
class CaseAnswerReader {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore        $store     The app's one case type reader, for reference ids.
	 * @param RebindValueConverter $converter Any answer as the text the case stores.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly RebindValueConverter $converter,
	) {
	}//end __construct()

	/**
	 * The answers on a case that hold a value.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array{name: string, definition: string, value: string}> The answers.
	 *
	 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
	 */
	public function entriesOf(array $case): array {
		$raw = ($case['properties'] ?? []);
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		$entries = [];
		foreach ($raw as $index => $item) {
			$entry = $this->listEntry(index: $index, item: $item);
			if ($entry === null) {
				$entry = $this->mapEntry(index: $index, item: $item);
			}

			if ($entry['name'] !== '' && $entry['value'] !== '') {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end entriesOf()

	/**
	 * A name => value map, keyed by comparable name.
	 *
	 * @param array<mixed, mixed> $values The map as posted.
	 *
	 * @return array<string, mixed> The map.
	 *
	 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
	 */
	public function keyed(array $values): array {
		$keyed = [];
		foreach ($values as $name => $value) {
			$key = $this->key(name: (string)$name);
			if ($key !== '') {
				$keyed[$key] = $value;
			}
		}

		return $keyed;
	}//end keyed()

	/**
	 * The comparable form of a field name: trimmed, lower case.
	 *
	 * @param string $name The name.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
	 */
	public function key(string $name): string {
		return mb_strtolower(trim($name));
	}//end key()

	/**
	 * One answer from the register's list shape, or null when this is not one.
	 *
	 * @param int|string $index The position.
	 * @param mixed      $item  The entry.
	 *
	 * @return array{name: string, definition: string, value: string}|null The answer.
	 */
	private function listEntry(int|string $index, mixed $item): ?array {
		if (is_int($index) === false || is_array($item) === false) {
			return null;
		}

		return [
			'name' => trim((string)($item['name'] ?? '')),
			'definition' => $this->store->referenceId(value: ($item['propertyDefinition'] ?? '')),
			'value' => $this->converter->asText(value: ($item['value'] ?? null)),
		];
	}//end listEntry()

	/**
	 * One answer from a legacy name-keyed map.
	 *
	 * @param int|string $index The name.
	 * @param mixed      $item  The value.
	 *
	 * @return array{name: string, definition: string, value: string} The answer.
	 */
	private function mapEntry(int|string $index, mixed $item): array {
		return [
			'name' => trim((string)$index),
			'definition' => '',
			'value' => $this->converter->asText(value: $item),
		];
	}//end mapEntry()
}//end class
