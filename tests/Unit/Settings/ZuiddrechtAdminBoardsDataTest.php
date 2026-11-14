<?php

/**
 * The settings the admin boards (DqBeheerinstellingen, DqAfdelingen) draw for
 * Zuiddrecht: case mail reads INBOX, external advice
 * replies go to the advice desk on the counter site, and Woo en informatie
 * sits under Bedrijfsvoering in the department tree.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers the example settings
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The admin boards' settings.
 */
class ZuiddrechtAdminBoardsDataTest extends TestCase {

	/**
	 * The two app config values and the department parent.
	 *
	 * @return void
	 */
	public function testTheAdminBoardsSettingsAreSet(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$config  = array_column($set['appConfig'], 'value', 'key');
		$objects = array_column($set['objects'], 'data', 'key');

		$this->assertSame('INBOX', $config['email_imap_folder'] ?? null);
		$this->assertSame('https://loket.zuiddrecht.nl/advies', $config['consultation_external_response_url'] ?? null);
		$this->assertSame('{{object:afdeling-bedrijfsvoering.id}}', $objects['afdeling-informatie-en-woo']['parentRoleId']);
	}//end testTheAdminBoardsSettingsAreSet()
}//end class
