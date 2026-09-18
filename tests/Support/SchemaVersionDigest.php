<?php

/**
 * What a schema's version has to move for, and what it does not.
 *
 * 🔴 THE VERSION IS NOT A BLANKET GATE, AND A GATE THAT PRETENDED IT WAS WOULD
 * BE A TREADMILL. Measured against OpenRegister's own importer
 * (`ImportHandler::importSchema`, @0eed192c, 2026-09-14): a schema whose
 * version is not newer is skipped ONLY when `schemaContentDiffers()` also says
 * no. That helper compares exactly three keys:
 *
 *     properties, required, authorization
 *
 * So a change inside those three reaches an instance whatever the version says,
 * and demanding a bump for one would be asking for a number nothing reads.
 *
 * 🔴 EVERYTHING ELSE IS GATED BY THE VERSION ALONE, AND FAILS SILENTLY. A
 * change to `configuration` — `linkedTypes`, `mailObjectTemplate`,
 * `x-openregister-*` — is invisible to `schemaContentDiffers()`. With the
 * version left alone the import logs a debug line and returns the stored
 * schema, so the app keeps working and the feature is simply not there. That is
 * the class this digest ratchets.
 *
 * The digest is therefore taken over the schema MINUS the three rescued keys
 * and minus `version` itself, which is the exact set the importer cannot see.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

/**
 * Collects one digest per shipped schema, over the part the importer is blind to.
 */
final class SchemaVersionDigest {

	/**
	 * Where the recorded digests live, relative to the repository root.
	 *
	 * @var string
	 */
	public const DIGEST_FILE = 'tests/schemas/schema-version-digests.json';

	/**
	 * The keys `ImportHandler::schemaContentDiffers()` compares.
	 *
	 * A change inside these reaches the instance on content alone, so the
	 * version does not have to move for it and this digest ignores them.
	 *
	 * @var array<int, string>
	 */
	public const RESCUED_BY_CONTENT = ['properties', 'required', 'authorization'];

	/**
	 * Every register file dossiq ships.
	 *
	 * @param string $root The repository root.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	public static function registerFiles(string $root): array {
		$files = glob($root . '/lib/Settings/*_register.json');
		$fragments = glob($root . '/lib/Settings/register.d/*.json');

		return array_values(array_merge(($files ?: []), ($fragments ?: [])));
	}//end registerFiles()

	/**
	 * One row per schema: its declared version and the digest of the rest.
	 *
	 * @param string $root The repository root.
	 *
	 * @return array<string, array{version: string, digest: string}> Keyed `file::schema`.
	 */
	public static function collect(string $root): array {
		$rows = [];
		foreach (self::registerFiles(root: $root) as $path) {
			$decoded = json_decode((string)file_get_contents($path), true);
			if (is_array($decoded) === false) {
				continue;
			}

			$relative = ltrim(str_replace($root, '', $path), '/');
			$schemas = ($decoded['components']['schemas'] ?? []);
			if (is_array($schemas) === false) {
				continue;
			}

			foreach ($schemas as $name => $schema) {
				if (is_array($schema) === false) {
					continue;
				}

				$rows[$relative . '::' . $name] = [
					'version' => (string)($schema['version'] ?? ''),
					'digest' => self::digestOf(schema: $schema),
				];
			}
		}

		ksort($rows);

		return $rows;
	}//end collect()

	/**
	 * The digest of everything the importer's content check cannot see.
	 *
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return string A sha256 over the normalised remainder.
	 */
	public static function digestOf(array $schema): string {
		foreach (array_merge(self::RESCUED_BY_CONTENT, ['version']) as $key) {
			unset($schema[$key]);
		}

		self::sortDeep($schema);

		return hash('sha256', (string)json_encode($schema));
	}//end digestOf()

	/**
	 * Sort every associative level, so key order alone never moves a digest.
	 *
	 * A reformat that reorders keys is not a change the importer can see
	 * either, and a gate that reddened on one would be noise nobody reads.
	 *
	 * @param mixed $value The value to sort in place.
	 *
	 * @return void
	 */
	private static function sortDeep(mixed &$value): void {
		if (is_array($value) === false) {
			return;
		}

		foreach ($value as &$child) {
			self::sortDeep($child);
		}

		unset($child);

		// A list keeps its order: `linkedTypes: ["mail","talk"]` and
		// `["talk","mail"]` are the same set to a reader and the same value to
		// the importer, but reordering a list is still an edit somebody made,
		// and the cheap reading is the safe one here.
		if (array_is_list($value) === false) {
			ksort($value);
		}
	}//end sortDeep()
}//end class
