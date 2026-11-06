<?php

/**
 * Example data for the DqFilter boards: a saved view sorted by deadline puts
 * cases with the same deadline in the order they came in, as every other
 * deadline order of the case list does (design-system #741).
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
 * The deadline order of the Zuiddrecht saved views.
 */
class ZuiddrechtSavedViewsTieOrderTest extends TestCase {

	/**
	 * Every saved view of the fragment and its parts, keyed by their key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function views(): array {
		$dir   = __DIR__.'/../../../lib/Settings/example-sets/';
		$files = array_merge([$dir.'zuiddrecht.json'], glob($dir.'zuiddrecht.d/*.json'));
		$views = [];
		foreach ($files as $file) {
			$set = json_decode((string)file_get_contents($file), true);
			foreach (($set['views'] ?? []) as $view) {
				$views[$view['key']] = $view['data'];
			}
		}

		return $views;
	}//end views()

	/**
	 * A control: the menu view of the DqFilterInMenu board sorts by deadline.
	 *
	 * @return void
	 */
	public function testTheMenuViewSortsByDeadline(): void {
		$sort = $this->views()['pieter-termijn-deze-week']['query']['sort'];
		$this->assertSame(expected: ['key' => 'deadline', 'order' => 'asc'], actual: $sort[0]);
	}//end testTheMenuViewSortsByDeadline()

	/**
	 * Every view sorted by deadline breaks a tie on receivedAt, oldest first.
	 *
	 * @return void
	 */
	public function testEqualDeadlinesKeepTheOrderTheyCameIn(): void {
		$sorted = array_filter($this->views(), static fn (array $view): bool => (($view['query']['sort'][0]['key'] ?? null) === 'deadline'));
		$this->assertCount(expectedCount: 5, haystack: $sorted);
		foreach ($sorted as $key => $view) {
			$this->assertSame(expected: ['key' => 'receivedAt', 'order' => 'asc'], actual: ($view['query']['sort'][1] ?? null), message: $key);
		}
	}//end testEqualDeadlinesKeepTheOrderTheyCameIn()
}//end class
