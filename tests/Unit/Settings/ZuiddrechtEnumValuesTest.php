<?php

/**
 * Every dossiq object in the Zuiddrecht example set uses a value its schema
 * allows for each enum property. `example-set:install` refuses the whole set
 * on one wrong value, so a slip here stops every app's demo data.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Enum values in the Zuiddrecht fragment.
 */
class ZuiddrechtEnumValuesTest extends TestCase {

	/**
	 * The enum of every schema property, as RegisterFragmentMerger builds it:
	 * register.d files apply in name order and a list (an enum) concatenates,
	 * so a fragment can append a value.
	 *
	 * @return array<string, array<string, array<int, mixed>>> Schema slug => property => allowed values.
	 */
	private function enums(): array {
		$settings = __DIR__.'/../../../lib/Settings';
		$files    = glob($settings.'/register.d/*.json');
		sort($files);
		array_unshift($files, $settings.'/dossiq_register.json');

		$enums = [];
		foreach ($files as $file) {
			$register = json_decode((string)file_get_contents($file), true);
			foreach (($register['components']['schemas'] ?? []) as $slug => $schema) {
				foreach (($schema['properties'] ?? []) as $name => $property) {
					if (is_array($property) === true && isset($property['enum']) === true) {
						$enums[$slug][$name] = array_merge(($enums[$slug][$name] ?? []), $property['enum']);
					}
				}
			}
		}

		return $enums;
	}//end enums()

	/**
	 * No dossiq object carries a value outside its property's enum; template
	 * placeholders are resolved at install and skipped.
	 *
	 * @return void
	 */
	public function testEveryEnumValueIsAllowed(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$enums = $this->enums();

		$refused = [];
		foreach ($set['objects'] as $object) {
			if (($object['register'] ?? '') !== 'dossiq') {
				continue;
			}

			foreach (($enums[$object['schema']] ?? []) as $name => $allowed) {
				$value = $object['data'][$name] ?? null;
				if (is_scalar($value) === false || (is_string($value) === true && str_starts_with($value, '{{') === true)) {
					continue;
				}

				if (in_array($value, $allowed, true) === false) {
					$refused[] = $object['key'].' ('.$object['schema'].').'.$name.' = '.json_encode($value);
				}
			}
		}

		$this->assertSame(expected: [], actual: $refused);
	}//end testEveryEnumValueIsAllowed()
}//end class
