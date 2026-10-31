<?php

/**
 * Example data for DqPersoonlijkeInstellingen and DqZijbalk: Pieter's Woo-verzoeken view.
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
 * Woo-verzoeken lists the open Woo requests.
 */
class ZuiddrechtMenuViewsTest extends TestCase {

	/**
	 * The fragment with its parts' lists appended, as the loader reads it.
	 *
	 * @return array<string, mixed> The fragment.
	 */
	private function fragment(): array {
		$folder   = __DIR__.'/../../../lib/Settings/example-sets';
		$fragment = json_decode((string)file_get_contents($folder.'/zuiddrecht.json'), true);
		$parts    = glob($folder.'/zuiddrecht.d/*.json');
		if ($parts === false) {
			$parts = [];
		}

		sort($parts);
		foreach ($parts as $file) {
			foreach (json_decode((string)file_get_contents($file), true) as $key => $value) {
				if (is_array($value) === true && array_is_list($value) === true) {
					$fragment[$key] = array_merge(($fragment[$key] ?? []), $value);
				}
			}
		}

		return $fragment;
	}//end fragment()

	/**
	 * Woo-verzoeken lists open cases of the Woo case type, quoted from a Woo case of the set.
	 *
	 * @return void
	 */
	public function testWooViewFiltersOnTheWooCaseType(): void {
		$fragment = $this->fragment();
		$views    = array_column($fragment['views'], 'data', 'key');
		$filters  = $views['pieter-woo-verzoeken']['query']['filters'];

		$this->assertSame(expected: '{{object:woo-afvalinzameling-oosthaven.caseType}}', actual: $filters['caseType']);
		$this->assertFalse(condition: $filters['isFinalStatus']);

		$cases = array_column(array_filter($fragment['objects'], static fn (array $object): bool => $object['schema'] === 'case'), null, 'key');
		$this->assertSame(
			expected: ['identifier' => 'woo-verzoek', 'version' => 2],
			actual: $cases['woo-afvalinzameling-oosthaven']['lookups']['caseType']['where']
		);
	}//end testWooViewFiltersOnTheWooCaseType()
}//end class
