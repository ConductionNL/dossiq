<?php

/**
 * Example data for DqFilterDelen: Pieter's saved view "Wacht op inwoner" is
 * already shared, with the group Woo en informatie (may use) and with Anouk
 * Bakker (may update), as the board's "Gedeeld met" list draws it.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The share dialog's shared view.
 */
class ZuiddrechtSharedSavedViewTest extends TestCase {

	/**
	 * The view names its two shares by cast key, a group that reads and a colleague that writes.
	 *
	 * @return void
	 */
	public function testWachtOpInwonerIsSharedWithTheWooTeamAndAnouk(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$views = array_column($set['views'], null, 'key');

		$this->assertSame(
			expected: [
				['group' => '{{cast:woo-en-informatie.gid}}', 'mode' => 'read'],
				['user' => '{{cast:anouk.uid}}', 'mode' => 'write'],
			],
			actual: ($views['pieter-wacht-op-inwoner']['data']['sharedWith'] ?? null)
		);
	}//end testWachtOpInwonerIsSharedWithTheWooTeamAndAnouk()
}//end class
