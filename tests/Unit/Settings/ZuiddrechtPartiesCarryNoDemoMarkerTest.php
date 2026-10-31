<?php

/**
 * Example data for DqContact and DqOrganisatie: the people and organisations
 * of the Zuiddrecht set describe who they are, as the boards draw them,
 * without a "Voorbeeld… Geen echte…" line. The set and its tenant already say
 * the data is a demo.
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
 * @spec exclude example-set data integrity; no requirement covers the demo descriptions
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The set's people and organisations carry no demo marker.
 */
class ZuiddrechtPartiesCarryNoDemoMarkerTest extends TestCase {

	/**
	 * The set's objects by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return array_column($set['objects'], null, 'key');
	}//end objects()

	/**
	 * No person or organisation description says it is an example or not real.
	 *
	 * @return void
	 */
	public function testNoDescriptionCarriesTheDemoMarker(): void {
		foreach ($this->objects() as $key => $object) {
			if (in_array($object['schema'], ['brpPerson', 'kvkCompany'], true) === false) {
				continue;
			}

			$description = (string)($object['data']['description'] ?? '');
			$this->assertDoesNotMatchRegularExpression(pattern: '/Voorbeeld|[Gg]een echte? /', string: $description, message: $key);
		}
	}//end testNoDescriptionCarriesTheDemoMarker()

	/**
	 * Sanne and Buurtvereniging Oost read as DqContact and DqOrganisatie draw them.
	 *
	 * @return void
	 */
	public function testTheBoardsDescriptionsStay(): void {
		$objects = $this->objects();

		$this->assertSame(expected: 'Verzoeker Woo, verlichting fietspad Lindelaan', actual: $objects['persoon-sanne']['data']['description']);
		$buurtoost = $objects['bedrijf-buurtoost']['data']['description'];
		$this->assertSame(expected: 'Organiseert het buurtfeest en de speeldagen in Oosthaven', actual: $buurtoost);
	}//end testTheBoardsDescriptionsStay()
}//end class
