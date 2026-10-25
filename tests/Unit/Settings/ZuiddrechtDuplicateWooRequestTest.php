<?php

/**
 * Example data for DqNieuweZaakDubbel: an open Woo request a new one can
 * duplicate, a Woo case type that asks a reason, and a coordinator who may give one.
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
 * The duplicate Woo request.
 */
class ZuiddrechtDuplicateWooRequestTest extends TestCase {

	/**
	 * The decoded Zuiddrecht fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return $set;
	}//end set()

	/**
	 * Henk Visser's swimming pool request is open, received yesterday, outside Pieter's teams.
	 *
	 * @return void
	 */
	public function testAnOpenWooRequestByHenkVisser(): void {
		$case = array_column($this->set()['objects'], null, 'key')['woo-kosten-zwembad'];

		$this->assertSame(expected: ['identifier' => 'woo-verzoek', 'version' => 2], actual: $case['lookups']['caseType']['where']);
		$this->assertSame(expected: 'Ontvangen', actual: $case['lookups']['status']['where']['name']);
		$this->assertSame(expected: 'Kosten zwembad Sportpark Zuid', actual: $case['data']['title']);
		$this->assertSame(expected: '{{object:persoon-henkvisser.id}}', actual: $case['data']['requester']);
		$this->assertSame(expected: '{{datetime:-1 10:05}}', actual: $case['data']['receivedAt']);
		$this->assertArrayNotHasKey(key: 'endDate', array: $case['data']);
		$this->assertNotContains(needle: $case['data']['assignedGroup'], haystack: ['behandelaars', 'zuiddrecht-woo-en-informatie']);
	}//end testAnOpenWooRequestByHenkVisser()

	/**
	 * The Woo type blocks a duplicate and Pieter, a coordinator, files it anyway with a reason.
	 *
	 * @return void
	 */
	public function testTheWooTypeAsksACoordinatorForAReason(): void {
		$set     = $this->set();
		$changes = array_column($set['changes'], null, 'key');
		$change  = $changes['woo-verzoek-v2-dubbel-met-reden'];

		$this->assertSame(expected: ['identifier' => 'woo-verzoek', 'version' => 2], actual: $change['where']);
		$this->assertSame(expected: ['duplicatePolicy' => 'block'], actual: $change['set']);

		$groups = array_column($set['groups'], 'members', 'group');
		$this->assertContains(needle: 'pieter', haystack: $groups['dossiq-coordinators']);
	}//end testTheWooTypeAsksACoordinatorForAReason()
}//end class
