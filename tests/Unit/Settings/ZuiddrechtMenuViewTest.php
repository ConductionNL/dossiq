<?php

/**
 * Example data for DqFilterInMenu: Pieter Jansen has his own saved view
 * "Termijn deze week" in his menu.
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
 * @spec openspec/specs/example-set-menu-view/spec.md#requirement-pieters-own-view-is-in-his-menu
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pieter's menu holds his view "Termijn deze week".
 *
 * @spec openspec/specs/example-set-menu-view/spec.md#requirement-pieters-own-view-is-in-his-menu
 */
class ZuiddrechtMenuViewTest extends TestCase {

	/**
	 * The menu preference quotes the view by its uuid placeholder, on the case list page.
	 *
	 * @return void
	 */
	public function testPietersMenuHoldsHisDeadlineView(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		$menu = null;
		foreach ($set['userPreferences'] as $entry) {
			if ($entry['user'] === '{{cast:pieter.uid}}' && $entry['key'] === 'pref_cn_saved_view_menu') {
				$menu = $entry;
			}
		}

		$this->assertNotNull(actual: $menu);
		$this->assertSame(
			expected: [
				['id' => '{{view:pieter-woo-verzoeken.uuid}}', 'page' => 'Cases', 'name' => 'Woo-verzoeken'],
				['id' => '{{view:pieter-termijn-deze-week.uuid}}', 'page' => 'Cases', 'name' => 'Termijn deze week'],
			],
			actual: json_decode($menu['value'], true)
		);
		$this->assertContains(needle: 'pieter-termijn-deze-week', haystack: array_column($set['views'], 'key'));
	}//end testPietersMenuHoldsHisDeadlineView()
}//end class
