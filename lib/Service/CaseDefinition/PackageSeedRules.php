<?php

/**
 * The rules a package seed file follows.
 *
 * A seed file names the schema its rows go to: by settings key for a schema
 * of this app, or by app, register and slug for a schema of another app. It
 * may name the property (`match`) an existing row is recognised by.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * Checks one decoded seed file of a package.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
final class PackageSeedRules {

	/**
	 * The errors in one seed file's `schema` and `match`.
	 *
	 * @param array<string, mixed> $seed The decoded seed file.
	 * @param string               $path The seed file, for the messages.
	 *
	 * @return array<int, string> The errors.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function errors(array $seed, string $path): array {
		$errors = $this->schemaErrors(schema: ($seed['schema'] ?? null), path: $path);
		$rows = ($seed['rows'] ?? null);
		if (array_key_exists('match', $seed) === true && is_array($rows) === true) {
			array_push($errors, ...$this->matchErrors(match: $seed['match'], rows: $rows, path: $path));
		}

		return $errors;
	}//end errors()

	/**
	 * Check the schema a seed file names.
	 *
	 * @param mixed  $schema The `schema` value.
	 * @param string $path   The seed file, for the message.
	 *
	 * @return array<int, string> The errors.
	 */
	private function schemaErrors(mixed $schema, string $path): array {
		if (is_array($schema) === true) {
			$rules = new PackageSettingRules();
			if ($rules->reference(value: [PackageSettingRules::REFERENCE_KEY => $schema]) === null) {
				return ["{$path}: schema names another app's schema without an app, a register and a slug; it needs all three."];
			}

			return [];
		}

		if (is_string($schema) === false || preg_match('/^[a-z][a-z0-9_]*_schema$/', $schema) !== 1) {
			return ["{$path}: schema must name a settings key ending in _schema, or another app's schema by app, register and slug."];
		}

		return [];
	}//end schemaErrors()

	/**
	 * The properties a seed file's `match` names, or none when it names none.
	 *
	 * `match` is one property name, or a list of them. A row is then
	 * recognised by all of them together.
	 *
	 * @param mixed $match The `match` value.
	 *
	 * @return array<int, string> The property names; empty when `match` is not valid.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function matchProperties(mixed $match): array {
		if (is_string($match) === true) {
			$match = [$match];
		}

		if (is_array($match) === false || $match === [] || array_is_list($match) === false) {
			return [];
		}

		foreach ($match as $property) {
			if (is_string($property) === false || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $property) !== 1) {
				return [];
			}
		}

		return array_values(array_unique($match));
	}//end matchProperties()

	/**
	 * Whether a value can recognise a row: a text, a number or a flag.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when it can.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function isMatchable(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		return is_int($value) === true || is_bool($value) === true;
	}//end isMatchable()

	/**
	 * Check the properties a seed file recognises an existing row by.
	 *
	 * @param mixed             $match The `match` value.
	 * @param array<int, mixed> $rows  The rows.
	 * @param string            $path  The seed file, for the message.
	 *
	 * @return array<int, string> The errors.
	 */
	private function matchErrors(mixed $match, array $rows, string $path): array {
		$properties = $this->matchProperties(match: $match);
		if ($properties === []) {
			return ["{$path}: match must name one property of the rows, or a list of them."];
		}

		$errors = [];

		foreach ($rows as $index => $row) {
			foreach ($properties as $property) {
				$value = null;
				if (is_array($row) === true) {
					$value = ($row[$property] ?? null);
				}

				if ($this->isMatchable(value: $value) === false) {
					$errors[] = "{$path} row {$index}: has no '{$property}', a property this file recognises an existing row by.";
				}
			}
		}

		return $errors;
	}//end matchErrors()
}//end class
