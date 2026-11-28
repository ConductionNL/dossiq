<?php

/**
 * Dossiq Filed Over List Is No Relation Test
 *
 * A case's `duplicateOverrideOf` lists the cases it was filed over after the
 * duplicate warning, often twenty or more. OpenRegister writes a saved object
 * back into every object a `$ref` property points at, one full update each, so
 * a `$ref` on these items turned filing over twenty matches into twenty case
 * updates and a create of twenty seconds. The list stays plain UUIDs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The filed-over list on the case schema carries no relation.
 */
final class FiledOverListIsNoRelationTest extends TestCase {

	/**
	 * Every shipped definition of the case's `duplicateOverrideOf`, by file.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function definitions(): array {
		$root  = dirname(__DIR__, 3) . '/lib/Settings';
		$fragments = glob($root . '/register.d/*.json');
		if ($fragments === false) {
			$fragments = [];
		}

		$files = array_merge([$root . '/dossiq_register.json'], $fragments);
		$found = [];
		foreach ($files as $file) {
			$data     = json_decode((string)file_get_contents($file), true);
			$property = ($data['components']['schemas']['case']['properties']['duplicateOverrideOf'] ?? null);
			if (is_array($property) === true) {
				$found[basename($file)] = $property;
			}
		}

		return $found;
	}//end definitions()

	/**
	 * The list is declared, and none of its definitions points its items at a schema.
	 *
	 * @return void
	 */
	public function testTheFiledOverCasesAreListedWithoutARef(): void {
		$definitions = $this->definitions();
		$this->assertArrayHasKey(key: 'dossiq_register.json', array: $definitions);

		foreach ($definitions as $file => $property) {
			$this->assertArrayNotHasKey(key: '$ref', array: $property, message: $file . ' puts a $ref on the filed-over list');
			$this->assertArrayNotHasKey(key: '$ref', array: ($property['items'] ?? []), message: $file . ' puts a $ref on the filed-over items');
			$this->assertSame(expected: 'uuid', actual: ($property['items']['format'] ?? null), message: $file . ' should keep the items as UUIDs');
		}
	}//end testTheFiledOverCasesAreListedWithoutARef()
}//end class
