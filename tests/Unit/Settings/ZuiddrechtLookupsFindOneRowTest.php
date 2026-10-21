<?php

/**
 * Every lookup of the Zuiddrecht set describes one shipped row.
 *
 * OpenRegister's example-set installer refuses an object whose lookup matches
 * more than one row, and with it the whole set. A role type found by name
 * alone matched every "Gemachtigde" the packages ship, one per case type, so
 * the set would not install wherever the vth package was. A lookup has to
 * name a business key that only one shipped row holds.
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
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * No lookup of the Zuiddrecht set matches two rows dossiq ships.
 *
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */
class ZuiddrechtLookupsFindOneRowTest extends TestCase {

	/**
	 * The settings directory.
	 */
	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The child collections a package's case type file carries, with their schema.
	 */
	private const COLLECTIONS = [
		'statusTypes' => 'statusType',
		'roleTypes' => 'roleType',
		'resultTypes' => 'resultType',
		'decisionTypes' => 'decisionType',
		'documentTypes' => 'documentType',
		'propertyDefinitions' => 'propertyDefinition',
	];

	/**
	 * Every row dossiq ships into its register, by schema: the register and
	 * its register.d parts, every package's case type, seed and examples, and
	 * the repair-step seeds. A case type reference is replaced by the case
	 * type's canonical key, whichever way the source spells it.
	 *
	 * @return array<string, list<array<string, mixed>>> The rows, by schema slug.
	 */
	private function shippedRows(): array {
		$rows = [];
		$this->addRegisterRows(rows: $rows);
		$this->addPackageCaseTypes(rows: $rows);
		$this->addPackageRowFiles(rows: $rows);
		$this->addRepairSeeds(rows: $rows);

		return $this->withCaseTypeKeys(rows: $rows);
	}//end shippedRows()

	/**
	 * Decode one settings file.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string, mixed> The decoded file, empty when it does not parse.
	 */
	private function decoded(string $path): array {
		return (array)json_decode((string)file_get_contents($path), true);
	}//end decoded()

	/**
	 * Add the objects of the register and its register.d and demo.d parts.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows The rows, by schema.
	 *
	 * @return void
	 */
	private function addRegisterRows(array &$rows): void {
		$paths = array_merge(
			[self::SETTINGS . '/dossiq_register.json'],
			(array)glob(self::SETTINGS . '/register.d/*.json'),
			(array)glob(self::SETTINGS . '/demo.d/*.json')
		);
		foreach ($paths as $path) {
			$data = $this->decoded(path: (string)$path);
			foreach ((array)($data['components']['objects'] ?? []) as $object) {
				if (is_array($object) === true) {
					$rows[(string)($object['@self']['schema'] ?? '')][] = $object;
				}
			}
		}
	}//end addRegisterRows()

	/**
	 * Add every package's case types and their child rows.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows The rows, by schema.
	 *
	 * @return void
	 */
	private function addPackageCaseTypes(array &$rows): void {
		foreach ((array)glob(self::SETTINGS . '/packages/*/case-type.json') as $path) {
			$data = $this->decoded(path: (string)$path);
			$caseTypes = (array)($data['caseType'] ?? []);
			if (array_is_list($caseTypes) === false) {
				$caseTypes = [$caseTypes];
			}

			foreach ($caseTypes as $caseType) {
				$rows['caseType'][] = (array)$caseType;
			}

			foreach (self::COLLECTIONS as $collection => $schema) {
				foreach ((array)($data[$collection] ?? []) as $row) {
					$rows[$schema][] = (array)$row;
				}
			}
		}
	}//end addPackageCaseTypes()

	/**
	 * Add the rows of every package's seed and examples files.
	 *
	 * A file names its schema by slug, or as `<snake_name>_schema`.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows The rows, by schema.
	 *
	 * @return void
	 */
	private function addPackageRowFiles(array &$rows): void {
		$paths = array_merge(
			(array)glob(self::SETTINGS . '/packages/*/seed/*.json'),
			(array)glob(self::SETTINGS . '/packages/*/examples/*.json')
		);
		foreach ($paths as $path) {
			$data = $this->decoded(path: (string)$path);
			$schema = ($data['schema'] ?? '');
			if (is_array($schema) === true) {
				$schema = ($schema['slug'] ?? '');
			}

			$schema = lcfirst(str_replace('_', '', ucwords((string)preg_replace('/_schema$/', '', (string)$schema), '_')));
			foreach ((array)($data['rows'] ?? []) as $row) {
				$rows[$schema][] = (array)$row;
			}
		}
	}//end addPackageRowFiles()

	/**
	 * Add what the case-flow repair step and the demo caseload seed write.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows The rows, by schema.
	 *
	 * @return void
	 */
	private function addRepairSeeds(array &$rows): void {
		$flow = $this->decoded(path: self::SETTINGS . '/case_flow_seed_data.json');
		foreach ((array)($flow['caseTypes'] ?? []) as $caseType) {
			foreach ((array)($caseType['statusTypes'] ?? []) as $status) {
				$rows['statusType'][] = ['caseType' => '@ref:' . (string)$caseType['slug']] + (array)$status;
			}

			unset($caseType['statusTypes']);
			$rows['caseType'][] = (array)$caseType;
		}

		foreach ((array)($flow['cases'] ?? []) as $case) {
			$rows['case'][] = ['caseType' => '@ref:' . (string)($case['caseTypeSlug'] ?? '')] + (array)$case;
		}

		$demo = $this->decoded(path: self::SETTINGS . '/demo_caseload_seed_data.json');
		foreach ((array)($demo['cases'] ?? []) as $case) {
			$rows['case'][] = (array)$case;
		}
	}//end addRepairSeeds()

	/**
	 * Replace every case type reference by one key per case type.
	 *
	 * A case type is referred to by its id, by `@ref:<slug>`, or by its
	 * identifier; each spelling maps to the same key.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows The rows, by schema.
	 *
	 * @return array<string, list<array<string, mixed>>> The rows, references replaced.
	 */
	private function withCaseTypeKeys(array $rows): array {
		$keys = [];
		foreach ($rows['caseType'] as $index => $caseType) {
			$key = 'caseType#' . $index;
			$slug = (string)($caseType['@self']['slug'] ?? ($caseType['slug'] ?? ''));
			$aliases = [(string)($caseType['id'] ?? ''), '@ref:' . $slug, (string)($caseType['identifier'] ?? '')];
			foreach (array_diff($aliases, ['', '@ref:']) as $alias) {
				$keys[$alias] = $key;
			}

			$rows['caseType'][$index]['id'] = $key;
		}

		foreach ($rows as $schema => $list) {
			foreach ($list as $index => $row) {
				$reference = ($row['caseType'] ?? null);
				if ($schema !== 'caseType' && is_string($reference) === true && isset($keys[$reference]) === true) {
					$rows[$schema][$index]['caseType'] = $keys[$reference];
				}
			}
		}

		return $rows;
	}//end withCaseTypeKeys()

	/**
	 * The rows a lookup matches, given what earlier lookups of the object found.
	 *
	 * A `{{lookup:name}}` in the where is replaced by what that lookup found;
	 * when it found no single row the field is left out, so the count is an
	 * upper bound.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows   The shipped rows.
	 * @param array<string, mixed>                      $lookup The lookup.
	 * @param array<string, string>                     $found  Ids found by earlier lookups, by name.
	 *
	 * @return list<array<string, mixed>> The matching rows.
	 */
	private function matching(array $rows, array $lookup, array $found): array {
		$where = [];
		foreach ((array)($lookup['where'] ?? []) as $field => $value) {
			if (is_string($value) === true && preg_match('/^\{\{lookup:([^}]+)\}\}$/', $value, $ref) === 1) {
				if (isset($found[$ref[1]]) === false) {
					continue;
				}

				$value = $found[$ref[1]];
			}

			$where[$field] = $value;
		}

		return array_values(
			array_filter(
				($rows[(string)($lookup['schema'] ?? '')] ?? []),
				static function (array $row) use ($where): bool {
					foreach ($where as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end matching()

	/**
	 * How many shipped rows each dossiq lookup of the set matches.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows The shipped rows.
	 * @param array<string, mixed>                      $set  The set.
	 *
	 * @return list<array{label: string, count: int}> One entry per lookup.
	 */
	private function counts(array $rows, array $set): array {
		$counts = [];
		foreach ((array)($set['objects'] ?? []) as $object) {
			$found = [];
			foreach ((array)($object['lookups'] ?? []) as $name => $lookup) {
				if ((string)($lookup['register'] ?? $object['register']) !== 'dossiq') {
					continue;
				}

				$matches = $this->matching(rows: $rows, lookup: (array)$lookup, found: $found);
				if (count($matches) === 1) {
					$found[(string)$name] = (string)($matches[0]['id'] ?? '');
				}

				$counts[] = [
					'label' => 'Object "' . (string)$object['key'] . '" lookup "' . (string)$name . '" ('
						. (string)$lookup['schema'] . ' where ' . json_encode($lookup['where'], JSON_UNESCAPED_UNICODE) . ')',
					'count' => count($matches),
				];
			}
		}

		return $counts;
	}//end counts()

	/**
	 * A lookup that names a business key two shipped rows share is caught.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function testALookupByASharedNameIsCaught(): void {
		$lookup = ['schema' => 'roleType', 'where' => ['name' => 'Gemachtigde']];
		$set = ['objects' => [['key' => 'gemachtigde', 'register' => 'dossiq', 'lookups' => ['roleType' => $lookup]]]];

		$counts = $this->counts(rows: $this->shippedRows(), set: $set);

		self::assertGreaterThan(
			expected: 1,
			actual: $counts[0]['count'],
			message: 'The packages ship more than one "Gemachtigde" role type; this test reads them wrongly if it finds one or none.'
		);
	}//end testALookupByASharedNameIsCaught()

	/**
	 * Each dossiq lookup of the set matches at most one shipped row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function testNoLookupMatchesTwoShippedRows(): void {
		$set = json_decode((string)file_get_contents(self::SETTINGS . '/example-sets/zuiddrecht.json'), true);
		self::assertIsArray(actual: $set, message: 'The Zuiddrecht set must parse.');

		$counts = $this->counts(rows: $this->shippedRows(), set: $set);
		self::assertNotSame(expected: [], actual: $counts, message: 'The set looks up dossiq rows; finding none means this test reads it wrongly.');
		foreach ($counts as $count) {
			self::assertLessThanOrEqual(
				expected: 1,
				actual: $count['count'],
				message: $count['label'] . ' matches ' . $count['count'] . ' shipped rows; the installer needs exactly one. Add a key only one row holds.'
			);
		}
	}//end testNoLookupMatchesTwoShippedRows()
}//end class
