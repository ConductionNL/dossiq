<?php

/**
 * The rules a case definition package's settings.json follows.
 *
 * A setting holds a scalar an app config can store, or names another app's
 * schema as `{"schema": {"app", "register", "slug"}}`, which the installer
 * resolves to this instance's schema id (PackageSchemaReferences).
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
 * Says what is wrong with a package's settings, and reads a schema reference.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class PackageSettingRules {

	/**
	 * The one key of a setting value that names a schema.
	 */
	public const REFERENCE_KEY = 'schema';

	/**
	 * What is wrong with each setting, in key order.
	 *
	 * @param array<mixed> $settings The decoded settings.json.
	 *
	 * @return array<int, string> The errors; empty when every setting is valid.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function errors(array $settings): array {
		$errors = [];
		foreach ($settings as $key => $value) {
			// 64 is the app config key limit: a longer key is refused by the
			// store at write time, after the rows went in.
			if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string)$key) !== 1) {
				$errors[] = "settings.json: '{$key}' is not a valid settings key (a-z, 0-9, _, at most 64).";
			}

			if ($this->isReference(value: $value) === true) {
				if ($this->reference(value: $value) === null) {
					$errors[] = "settings.json: '{$key}' names a schema without an app, a register and a slug; it needs all three.";
				}

				continue;
			}

			if (is_scalar($value) === false) {
				$errors[] = "settings.json: '{$key}' must hold a string, number or boolean, or name a schema by app, register and slug.";
			}
		}

		return $errors;
	}//end errors()

	/**
	 * Whether a setting value names a schema rather than holding a value.
	 *
	 * @param mixed $value The setting value.
	 *
	 * @return boolean True for an object whose only key is `schema`.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function isReference(mixed $value): bool {
		return is_array($value) === true && array_keys($value) === [self::REFERENCE_KEY];
	}//end isReference()

	/**
	 * The app, register slugs and schema slug a reference names.
	 *
	 * @param mixed $value The setting value.
	 *
	 * @return array{app: string, registers: array<int, string>, slug: string}|null
	 *         Null when it is not a complete reference.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function reference(mixed $value): ?array {
		if ($this->isReference(value: $value) === false || is_array($value[self::REFERENCE_KEY]) === false) {
			return null;
		}

		$reference = $value[self::REFERENCE_KEY];
		$app = $this->text(value: $reference['app'] ?? null);
		$slug = $this->text(value: $reference['slug'] ?? null);
		$registers = $this->registers(value: $reference['register'] ?? null);
		if ($app === '' || $slug === '' || $registers === []) {
			return null;
		}

		return ['app' => $app, 'registers' => $registers, 'slug' => $slug];
	}//end reference()

	/**
	 * The register slugs: one string, or a list newest first.
	 *
	 * @param mixed $value The `register` value.
	 *
	 * @return array<int, string> The non-empty slugs.
	 */
	private function registers(mixed $value): array {
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter(array_map(fn (mixed $slug): string => $this->text(value: $slug), $value)));
	}//end registers()

	/**
	 * A value as trimmed text, or '' when it is not a string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
