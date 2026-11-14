<?php

/**
 * Every dossiq object of the Zuiddrecht set carries what its schema requires.
 *
 * OpenRegister's example-set installer validates each object against its
 * schema and refuses the whole set when one misses a required property. The
 * checklist run in progress shipped without its case, so the set never
 * installed once the duplicate rows ahead of it were gone.
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
 * No dossiq object of the Zuiddrecht set misses a required property.
 *
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */
class ZuiddrechtObjectsCarryRequiredPropertiesTest extends TestCase {

	/**
	 * The settings directory.
	 */
	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The schemas dossiq ships, from the register and its register.d parts.
	 *
	 * @return array<string, array<string, mixed>> The schemas, by slug.
	 */
	private function schemas(): array {
		$paths = array_merge(
			[self::SETTINGS . '/dossiq_register.json'],
			(array)glob(self::SETTINGS . '/register.d/*.json')
		);
		$schemas = [];
		foreach ($paths as $path) {
			$data = (array)json_decode((string)file_get_contents((string)$path), true);
			foreach ((array)($data['components']['schemas'] ?? []) as $slug => $schema) {
				$schemas[(string)$slug] = (array)$schema;
			}
		}

		return $schemas;
	}//end schemas()

	/**
	 * Every dossiq object names each property its schema requires.
	 *
	 * @return void
	 */
	public function testEveryDossiqObjectCarriesItsRequiredProperties(): void {
		$set = (array)json_decode((string)file_get_contents(self::SETTINGS . '/example-sets/zuiddrecht.json'), true);
		$schemas = $this->schemas();
		$missing = [];
		foreach ((array)($set['objects'] ?? []) as $object) {
			if (($object['register'] ?? '') !== 'dossiq' || isset($schemas[$object['schema'] ?? '']) === false) {
				continue;
			}

			foreach ((array)($schemas[$object['schema']]['required'] ?? []) as $property) {
				if (array_key_exists((string)$property, (array)($object['data'] ?? [])) === false) {
					$missing[] = $object['key'] . ' (' . $object['schema'] . '): ' . $property;
				}
			}
		}

		$this->assertSame(expected: [], actual: $missing);
	}//end testEveryDossiqObjectCarriesItsRequiredProperties()

	/**
	 * The checklist run in progress belongs to the terrace roofing case Esra handles.
	 *
	 * @return void
	 */
	public function testTheChecklistRunNamesItsCase(): void {
		$set = (array)json_decode((string)file_get_contents(self::SETTINGS . '/example-sets/zuiddrecht.json'), true);
		$runs = array_values(array_filter(
			(array)($set['objects'] ?? []),
			static fn (array $object): bool => ($object['key'] ?? '') === 'inspectie-oplevering-esra'
		));

		$this->assertCount(expectedCount: 1, haystack: $runs);
		$this->assertSame(expected: '{{object:terras-slagerij.id}}', actual: $runs[0]['data']['case'] ?? null);
	}//end testTheChecklistRunNamesItsCase()
}//end class
