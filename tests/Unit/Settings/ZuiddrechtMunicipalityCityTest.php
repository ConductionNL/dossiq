<?php

/**
 * The Zuiddrecht example set names its municipality's city.
 *
 * @category Tests
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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\ConfigKeys;
use PHPUnit\Framework\TestCase;

/**
 * A demo opens the contacts and organisations lists on Zuiddrecht.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class ZuiddrechtMunicipalityCityTest extends TestCase {

	/**
	 * The fragment sets `municipality_city` to Zuiddrecht, a key the settings endpoint saves.
	 *
	 * @return void
	 */
	public function testTheSetNamesZuiddrechtAsTheMunicipalityCity(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json'),
			true
		);

		$values = array_column($fragment['appConfig'], 'value', 'key');

		$this->assertSame(expected: 'Zuiddrecht', actual: ($values['municipality_city'] ?? null));
		$this->assertContains(needle: 'municipality_city', haystack: ConfigKeys::ALL);
	}//end testTheSetNamesZuiddrechtAsTheMunicipalityCity()
}//end class
