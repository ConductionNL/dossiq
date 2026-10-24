<?php

/**
 * Example data for DqZaakEmail: the Woo case type's e-mail template
 * "Ontvangstbevestiging Woo", version 2 active over version 1.
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
 * The Woo acknowledgement template.
 */
class ZuiddrechtWooEmailTemplateTest extends TestCase {

	/**
	 * Version 2 is the active one, made from version 1, for the Woo case type.
	 *
	 * @return void
	 */
	public function testVersionTwoIsActive(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');
		$v1      = $objects['mailsjabloon-ontvangst-woo-v1'];
		$v2      = $objects['mailsjabloon-ontvangst-woo-v2'];

		$this->assertSame(expected: ['identifier' => 'woo-verzoek', 'version' => 2], actual: $v2['lookups']['caseType']['where']);
		$this->assertSame(expected: ['Ontvangstbevestiging Woo', 2, true], actual: [$v2['data']['name'], $v2['data']['version'], $v2['data']['isActive']]);
		$this->assertSame(expected: [1, false], actual: [$v1['data']['version'], $v1['data']['isActive']]);
		$this->assertSame(expected: '{{object:mailsjabloon-ontvangst-woo-v1.id}}', actual: $v2['data']['previousVersion']);

		// Only names the mail resolver answers, so no placeholder is left in the sent text.
		preg_match_all('/{{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*}}/', $v2['data']['subject'].$v2['data']['body'], $names);
		$this->assertSame(expected: [], actual: array_values(array_diff($names[1], ['zaakNummer', 'contactNaam', 'startDate', 'deadline'])));
		$this->assertStringNotContainsString(needle: '{{case.', haystack: $v1['data']['body'].$v2['data']['body']);
	}//end testVersionTwoIsActive()
}//end class
