<?php

/**
 * Example data for DqOverdragen: the two legal teams a case can be handed to.
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
 * The legal teams carry their group and a short line on what they do.
 */
class ZuiddrechtLegalTeamsTest extends TestCase {

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
	 * Juridische zaken and Juridische zaken sociaal domein are organisation roles bound to their groups, with the board's descriptions.
	 *
	 * @return void
	 */
	public function testTheLegalTeamsAreRolesWithADescription(): void {
		$roles = [];
		foreach ($this->fragment()['objects'] as $object) {
			if ($object['schema'] === 'organisatieRol') {
				$roles[$object['data']['ncGroupId']] = $object['data'];
			}
		}

		$this->assertSame(expected: 'bezwaar, beroep en Woo-advies', actual: $roles['zuiddrecht-juridische-zaken']['description']);
		$this->assertSame(expected: 'Juridische zaken', actual: $roles['zuiddrecht-juridische-zaken']['roleName']);
		$this->assertSame(expected: 'bezwaar Wmo en jeugd', actual: $roles['zuiddrecht-juridische-zaken-sociaal-domein']['description']);
		$this->assertSame(expected: 'Juridische zaken sociaal domein', actual: $roles['zuiddrecht-juridische-zaken-sociaal-domein']['roleName']);
	}//end testTheLegalTeamsAreRolesWithADescription()
}//end class
