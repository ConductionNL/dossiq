<?php

/**
 * Example data for DqZaak: the open Lindelaan Woo request has a location, so
 * its Locaties card draws one point with the address under the map.
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
 * @spec openspec/changes/locations-card-board/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Lindelaan request's location in the Zuiddrecht example set.
 */
class ZuiddrechtLindelaanLocationTest extends TestCase {

	/**
	 * The case-location rows of the fragment, keyed by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function locations(): array {
		$part = json_decode(
			(string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/66-lindelaan-location.json'),
			true
		);
		$rows = [];
		foreach ($part['objects'] as $object) {
			if ($object['schema'] === 'case-location') {
				$rows[$object['key']] = $object;
			}
		}

		return $rows;
	}//end locations()

	/**
	 * The open Lindelaan request is the case the location points at.
	 *
	 * @return void
	 */
	public function testTheLocationIsOnTheOpenLindelaanRequest(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$cases = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] === 'case') {
				$cases[$object['key']] = $object['data'];
			}
		}

		$location = $this->locations()['woo-fietspad-besluitklaar-locatie'];
		$this->assertSame(expected: 'dossiq', actual: $location['register']);
		$this->assertSame(expected: '{{object:woo-fietspad-besluitklaar.id}}', actual: $location['data']['case']);
		$this->assertSame(expected: 'Openbare verlichting fietspad Lindelaan', actual: $cases['woo-fietspad-besluitklaar']['title']);
	}//end testTheLocationIsOnTheOpenLindelaanRequest()

	/**
	 * The point is in Zuiddrecht and the card has an address to show.
	 *
	 * @return void
	 */
	public function testThePointIsInZuiddrechtWithAnAddress(): void {
		foreach ($this->locations() as $key => $location) {
			$data = $location['data'];
			$this->assertTrue(condition: ($data['longitude'] > 4.6 && $data['longitude'] < 4.7), message: $key);
			$this->assertTrue(condition: ($data['latitude'] > 51.8 && $data['latitude'] < 51.83), message: $key);
			$this->assertSame(expected: 'Lindelaan, Zuiddrecht', actual: $data['formattedAddress'], message: $key);
			$this->assertSame(expected: 'free', actual: $data['source'], message: $key);
		}
	}//end testThePointIsInZuiddrechtWithAnAddress()

	/**
	 * The row carries only what the case-location schema declares, and its required fields.
	 *
	 * @return void
	 */
	public function testTheRowMatchesTheSchema(): void {
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/dossiq_register.json'), true);
		$schema   = null;
		foreach ($register['components']['schemas'] as $candidate) {
			if (($candidate['slug'] ?? '') === 'case-location') {
				$schema = $candidate;
			}
		}

		$this->assertNotNull($schema);
		foreach ($this->locations() as $key => $location) {
			$this->assertSame(
				expected: [],
				actual: array_values(array_diff(array_keys($location['data']), array_keys($schema['properties']))),
				message: $key
			);
			foreach ($schema['required'] as $field) {
				$this->assertArrayHasKey(key: $field, array: $location['data'], message: $key);
			}

			$this->assertContains(needle: $location['data']['source'], haystack: $schema['properties']['source']['enum'], message: $key);
		}
	}//end testTheRowMatchesTheSchema()
}//end class
