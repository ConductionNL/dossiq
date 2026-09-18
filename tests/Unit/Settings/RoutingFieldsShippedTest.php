<?php

/**
 * The weight, the team and the area reach an instance that already imported.
 *
 * 🔴 A SAME-NUMBER VERSION COLLISION DOES NOT CONFLICT. Two branches writing
 * one new register version merge that line in silence, both land, and
 * `ImportHandler` skips the second import because
 * `version_compare(new, existing, '<=')` holds: the schema change reaches a
 * fresh CI install and no existing instance, with nothing on screen to show
 * for it (dossiq#2938). The floor is therefore written against the number the
 * fleet is on, not the number this branch started from, and it is above the
 * number the fees-and-payments branch takes, because that one lands first.
 *
 * A routing pool whose weights never arrive is the worst version of this:
 * every strategy still routes, so nothing fails, and the part-time colleague
 * keeps receiving a full share.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The shipped register carries the routing fields, above the floor.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class RoutingFieldsShippedTest extends TestCase {
	/**
	 * The highest register version already claimed when this change landed:
	 * 0.20.1 on the integration branch, and 0.20.2 on the fees-and-payments
	 * branch that merges before this one.
	 *
	 * @var string
	 */
	private const FLOOR = '0.20.2';

	/**
	 * The shipped register.
	 *
	 * @return array<string, mixed> The register.
	 */
	private function register(): array {
		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json');
		$this->assertIsString($raw, 'the register could not be read');

		return (array)json_decode((string)$raw, true);
	}//end register()

	/**
	 * The register version moved past every number already claimed.
	 *
	 * @return void
	 */
	public function testTheRegisterVersionMovedPastEveryClaimedNumber(): void {
		$version = (string)($this->register()['info']['version'] ?? '');

		$this->assertTrue(
			version_compare($version, self::FLOOR, '>'),
			sprintf(
				'The register is on %s and %s is already claimed. An import at or below an existing version is SKIPPED, so '
				. 'the weight, the team and the area would reach a fresh install and no existing instance.',
				$version,
				self::FLOOR
			)
		);
	}//end testTheRegisterVersionMovedPastEveryClaimedNumber()

	/**
	 * And it carries the fields that bump is for.
	 *
	 * @return void
	 */
	public function testTheRegisterCarriesTheWeightTheTeamAndTheArea(): void {
		$schemas = $this->register()['components']['schemas'];

		foreach (['weight', 'team'] as $property) {
			$this->assertArrayHasKey($property, $schemas['role']['properties'], sprintf('role.%s is missing', $property));
		}

		foreach (['district', 'neighbourhood', 'areaResolvedAt', 'areaSource', 'areaFallbackUsed'] as $property) {
			$this->assertArrayHasKey($property, $schemas['case']['properties'], sprintf('case.%s is missing', $property));
		}

		$this->assertArrayHasKey('areaFallbackRoleType', $schemas['caseType']['properties']);
		// Absent means one, and the default is what makes an existing pool
		// route exactly as it did before weights existed.
		$this->assertSame(1, $schemas['role']['properties']['weight']['default']);
	}//end testTheRegisterCarriesTheWeightTheTeamAndTheArea()

	/**
	 * The app version moved too, so an upgrade runs what this change adds.
	 *
	 * @return void
	 */
	public function testTheAppVersionMovedPastTheFleet(): void {
		$xml = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertNotFalse($xml, 'info.xml could not be read');

		$this->assertTrue(
			version_compare((string)$xml->version, '0.4.30-unstable.20260918120000', '>'),
			'The app version must be strictly above every number already claimed, or nothing runs on upgrade.'
		);
	}//end testTheAppVersionMovedPastTheFleet()
}//end class
