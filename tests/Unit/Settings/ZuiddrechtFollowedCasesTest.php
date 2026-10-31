<?php

/**
 * Example data for DqMijnWerk: the cases Pieter follows.
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
 * Pieter follows two cases of colleagues.
 */
class ZuiddrechtFollowedCasesTest extends TestCase {

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
	 * Pieter watches Aanbesteding jeugdzorg 2026 and Subsidie buurtfeest Oosthaven, and the first is the aanbesteding package's example case.
	 *
	 * @return void
	 */
	public function testPieterFollowsTwoCases(): void {
		$watched = [];
		foreach ($this->fragment()['watchers'] as $watcher) {
			if ($watcher['data']['user'] === '{{cast:pieter.uid}}') {
				$watched[] = $watcher['data']['objectUuid'];
			}
		}

		$this->assertSame(expected: ['b84067ac-35ec-55b5-bf17-975206ce32f2', '{{object:subsidie-buurtfeest-oosthaven.id}}'], actual: $watched);

		$package = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/packages/aanbesteding/examples/20-case.json'), true);
		$this->assertSame(expected: 'b84067ac-35ec-55b5-bf17-975206ce32f2', actual: $package['rows'][0]['id']);
		$this->assertSame(expected: 'Aanbesteding jeugdzorg 2026', actual: $package['rows'][0]['title']);
	}//end testPieterFollowsTwoCases()
}//end class
