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
	 * Check the property a seed file recognises an existing row by.
	 *
	 * @param mixed             $match The `match` value.
	 * @param array<int, mixed> $rows  The rows.
	 * @param string            $path  The seed file, for the message.
	 *
	 * @return array<int, string> The errors.
	 */
	private function matchErrors(mixed $match, array $rows, string $path): array {
		if (is_string($match) === false || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $match) !== 1) {
			return ["{$path}: match must name one property of the rows."];
		}

		$errors = [];

		foreach ($rows as $index => $row) {
			$value = null;
			if (is_array($row) === true) {
				$value = ($row[$match] ?? null);
			}

			if (is_string($value) === false || trim($value) === '') {
				$errors[] = "{$path} row {$index}: has no '{$match}', the property this file recognises an existing row by.";
			}
		}

		return $errors;
	}//end matchErrors()
}//end class
