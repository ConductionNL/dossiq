<?php

/**
 * The Woo request case type names the roles its parties hold, and the BRP
 * person and KvK company records are parties of OpenRegister's party model,
 * so a Woo case lists its requester, representative, interested party,
 * adviser and reporter with their periods and indicators.
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
 * @spec openspec/specs/woo-parties-configuration/spec.md#requirement-a-woo-case-names-its-parties-roles-and-its-contacts-are-parties
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Woo role types and the party declarations of the contact schemas.
 *
 * @spec openspec/specs/woo-parties-configuration/spec.md#requirement-a-woo-case-names-its-parties-roles-and-its-contacts-are-parties
 */
class WooPartiesConfigurationTest extends TestCase {

	/**
	 * A settings file, decoded.
	 *
	 * @param string $path The path under lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(string $path): array {
		$decoded = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/'.$path), true);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end settings()

	/**
	 * The Woo case type ships five role types, one per generic role the board names.
	 *
	 * @return void
	 */
	public function testTheWooCaseTypeNamesItsRoles(): void {
		$objects = $this->settings(path: 'register.d/87-woo-role-types.json')['components']['objects'];
		$roles   = [];
		foreach ($objects as $object) {
			$this->assertSame(expected: 'roleType', actual: $object['@self']['schema']);
			$this->assertSame(expected: '3c0f5a00-0000-4000-a000-00000000a001', actual: $object['caseType']);
			$this->assertSame(expected: $object['id'], actual: $object['uuid']);
			$roles[$object['name']] = $object['genericRole'];
		}

		$this->assertSame(
			expected: [
				'Verzoeker'      => 'initiator',
				'Gemachtigde'    => 'gemachtigde',
				'Belanghebbende' => 'stakeholder',
				'Adviseur'       => 'advisor',
				'Melder'         => 'contact',
			],
			actual: $roles
		);
	}//end testTheWooCaseTypeNamesItsRoles()

	/**
	 * A BRP person is a person party and a KvK company an organisation party, each with indicators.
	 *
	 * @return void
	 */
	public function testTheContactSchemasArePartiesWithIndicators(): void {
		$schemas = $this->settings(path: 'register.d/25-brp-kvk.json')['components']['schemas'];

		$this->assertSame(
			expected: ['kind' => 'person', 'nameProperty' => 'displayName', 'addressesProperty' => 'contactAddresses'],
			actual: $schemas['brpPerson']['configuration']['x-openregister-party']
		);
		$this->assertSame(
			expected: ['kind' => 'organisation', 'nameProperty' => 'tradeName'],
			actual: $schemas['kvkCompany']['configuration']['x-openregister-party']
		);
		foreach (['brpPerson', 'kvkCompany'] as $schema) {
			$this->assertSame(expected: 'array', actual: $schemas[$schema]['properties']['indicators']['type']);
		}
	}//end testTheContactSchemasArePartiesWithIndicators()
}//end class
