<?php

/**
 * Every shipped package's example rows match their schemas as written.
 *
 * Example rows go through PackageExamples on their way to the writer: dates
 * filled, and every property the register declares as a JSON-encoded string
 * encoded. OpenRegister refuses a row whose value does not have the declared
 * type, and one refused row rolls back the whole package, so this checks
 * each row as the writer would send it against the effective register.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseDefinition\PackageExamples;
use OCA\Dossiq\Service\DemoDataService;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Example rows, as written, have the types, values and required properties their schemas declare.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class PackageExampleRowsMatchTheirSchemasTest extends TestCase {

	/**
	 * The effective register's schemas, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>> The schemas.
	 */
	private function schemas(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$base = json_decode(json: (string)file_get_contents($settings . '/dossiq_register.json'), associative: true);
		self::assertIsArray(actual: $base, message: 'The shipped register configuration must parse.');
		[$effective] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');

		return (array)(($effective['components'] ?? [])['schemas'] ?? []);
	}//end schemas()

	/**
	 * Every package's example files, keyed by their path, as PackageExamples hands them to the writer.
	 *
	 * @return array<string, array<string, mixed>> The files.
	 */
	private function examplesAsWritten(): array {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap(
			[[Application::APP_ID, DemoDataService::DECIDED_KEY, '', DemoDataService::DECIDED_INSTALLED]]
		);
		$examples = new PackageExamples(appConfig: $appConfig, logger: new NullLogger());

		$files = [];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/packages/*/examples/*.json') as $path) {
			$decoded = json_decode(json: (string)file_get_contents((string)$path), associative: true);
			$relative = basename(dirname((string)$path, 2)) . '/examples/' . basename((string)$path);
			self::assertIsArray(actual: $decoded, message: $relative . ' must parse.');
			$files[$relative] = $examples->toWrite(package: $relative, examples: [$decoded])[0];
		}

		return $files;
	}//end examplesAsWritten()

	/**
	 * Whether a value has one of the declared JSON types.
	 *
	 * @param mixed              $value The value.
	 * @param array<int, string> $types The declared types.
	 *
	 * @return boolean True when it has one of them.
	 */
	private static function hasType(mixed $value, array $types): bool {
		$actual = match (true) {
			$value === null => 'null',
			is_bool($value) => 'boolean',
			is_int($value) => 'integer',
			is_float($value) => 'number',
			is_string($value) => 'string',
			is_array($value) && array_is_list($value) => 'array',
			default => 'object',
		};

		return in_array($actual, $types, true) === true
			|| ($actual === 'integer' && in_array('number', $types, true) === true)
			|| ($actual === 'array' && $value === [] && in_array('object', $types, true) === true);
	}//end hasType()

	/**
	 * The problems of one row against its schema.
	 *
	 * @param array<string, mixed> $row    The row as written.
	 * @param array<string, mixed> $schema The schema.
	 * @param string               $where  The file and row, for the messages.
	 *
	 * @return array<int, string> The problems.
	 */
	private static function problems(array $row, array $schema, string $where): array {
		$problems = [];
		$properties = (array)($schema['properties'] ?? []);
		foreach ((array)($schema['required'] ?? []) as $required) {
			if (array_key_exists((string)$required, $row) === false) {
				$problems[] = "{$where}: misses required '{$required}'";
			}
		}

		foreach ($row as $property => $value) {
			if ($property === 'id') {
				continue;
			}

			if (isset($properties[$property]) === false) {
				$problems[] = "{$where}: '{$property}' is not a property of the schema";
				continue;
			}

			$definition = (array)$properties[$property];
			$types = array_map('strval', (array)($definition['type'] ?? []));
			if ($types !== [] && self::hasType(value: $value, types: $types) === false) {
				$problems[] = "{$where}: '{$property}' should be type '" . implode(' or ', $types) . "' but is '" . get_debug_type($value) . "'";
			}

			if (isset($definition['enum']) === true && in_array($value, (array)$definition['enum'], true) === false) {
				$problems[] = "{$where}: '{$property}' is not one of the values the schema allows";
			}
		}//end foreach

		return $problems;
	}//end problems()

	/**
	 * The register slugs an example file writes to: by settings key, or by a
	 * reference to dossiq's own register (app, register and slug).
	 *
	 * @param mixed $schema The file's `schema`.
	 *
	 * @return array<int, string> The slugs; empty when it names none of this register.
	 */
	private static function slugsOf(mixed $schema): array {
		if (is_string($schema) === true) {
			return array_map('strval', array_keys(SchemaSlugMap::SLUG_TO_CONFIG_KEY, $schema, true));
		}

		if (is_array($schema) === true && ($schema['app'] ?? '') === 'dossiq' && is_string($schema['slug'] ?? null) === true) {
			return [$schema['slug']];
		}

		return [];
	}//end slugsOf()

	/**
	 * Every example row of every shipped package, as written, matches its schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testEveryExampleRowMatchesItsSchemaAsWritten(): void {
		$schemas = $this->schemas();
		$files = $this->examplesAsWritten();
		self::assertArrayHasKey(key: 'bezwaar-beroep/examples/20-case.json', array: $files, message: 'The bezwaar example case is where this test starts.');

		$problems = [];
		foreach ($files as $relative => $file) {
			$slugs = self::slugsOf(schema: $file['schema']);
			self::assertNotSame(expected: [], actual: $slugs, message: "{$relative} names no schema of this register.");
			foreach ((array)$file['rows'] as $index => $row) {
				$schema = (array)($schemas[$slugs[0]] ?? []);
				$problems = array_merge($problems, self::problems(row: (array)$row, schema: $schema, where: "{$relative} row {$index}"));
			}
		}

		self::assertSame(expected: [], actual: $problems, message: implode("\n", $problems));
	}//end testEveryExampleRowMatchesItsSchemaAsWritten()

	/**
	 * The encoding is what makes the case rows pass: the file writes the
	 * status history as readable JSON, and the row as written carries it as text.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testTheStatusHistoryIsWrittenAsText(): void {
		$file = $this->examplesAsWritten()['bezwaar-beroep/examples/20-case.json'];
		$history = $file['rows'][0]['statusHistory'];

		self::assertIsString(actual: $history);
		self::assertCount(expectedCount: 4, haystack: (array)json_decode(json: $history, associative: true));
	}//end testTheStatusHistoryIsWrittenAsText()
}//end class
