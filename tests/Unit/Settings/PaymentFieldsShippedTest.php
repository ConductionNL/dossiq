<?php

/**
 * The payment fields reach an instance that already imported the register.
 *
 * 🔴 A SAME-NUMBER VERSION COLLISION DOES NOT CONFLICT. Two branches writing
 * the same new register version merge that line in silence, both land, and
 * `ImportHandler` then skips the second import because
 * `version_compare(new, existing, '<=')` holds. The schema change reaches a
 * fresh CI install and no existing instance, and nothing on screen looks
 * wrong. That is what happened to 0.20.0 (dossiq#2938).
 *
 * 🔴 THE FLOOR IS WRITTEN AGAINST WHERE THIS CHANGE LANDED, NOT WHERE IT CAME
 * FROM. A floor of `> 0.19.2`, the number this branch started at, is satisfied
 * by the very collision it is supposed to catch.
 *
 * 🔴 AND IT HAPPENED AGAIN WHILE THIS BRANCH WAITED. Three changes landed on
 * parity/round2 at register 0.20.3 — #2923, #2937 and #2936 — each writing the
 * identical line, which merges in silence. An instance that imported 0.20.3
 * from the first of them skips the other two. The register is ONE document, so
 * the next import above that number carries everything that accumulated under
 * the collision, and this change's 0.20.6 is that import. The floor is 0.20.5
 * because the two branches behind this one claim 0.20.4 and 0.20.5.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The shipped register carries the payment projection, above the floor.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */
class PaymentFieldsShippedTest extends TestCase {
	/**
	 * The version the fleet was on when this change landed.
	 *
	 * @var string
	 */
	private const FLOOR = '0.20.5';

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
	 * The register version moved past the number the fleet is on.
	 *
	 * @return void
	 */
	public function testTheRegisterVersionMovedPastTheFleet(): void {
		$version = (string)($this->register()['info']['version'] ?? '');

		$this->assertTrue(
			version_compare($version, self::FLOOR, '>'),
			sprintf(
				'The register is on %s and the fleet is on %s. An import at or below the fleet version is SKIPPED, so these '
				. 'properties would reach a fresh install and no existing instance.',
				$version,
				self::FLOOR
			)
		);
	}//end testTheRegisterVersionMovedPastTheFleet()

	/**
	 * And it carries the fields that bump is for.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesTheProjectionAndTheCaseTypeCarriesTheRule(): void {
		$schemas = $this->register()['components']['schemas'];

		foreach (['paymentState', 'paymentStateCheckedAt', 'contract'] as $property) {
			$this->assertArrayHasKey($property, $schemas['case']['properties'], sprintf('case.%s is missing', $property));
		}

		$this->assertArrayHasKey('paymentRequiredBeforeHandling', $schemas['caseType']['properties']);
		// The projection is never a field a handler edits: the state is a
		// financial fact and an editable one is a guess.
		$this->assertTrue($schemas['case']['properties']['paymentState']['readOnly']);
	}//end testTheCaseCarriesTheProjectionAndTheCaseTypeCarriesTheRule()

	/**
	 * The app version moved too, so the repair steps and the background job
	 * this change registers actually run on an upgrade.
	 *
	 * @return void
	 */
	public function testTheAppVersionMovedPastTheFleet(): void {
		$xml = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertNotFalse($xml, 'info.xml could not be read');

		$this->assertTrue(
			version_compare((string)$xml->version, '0.4.33-unstable.20260918123000', '>'),
			'The app version must be strictly above the one on the integration branch, or nothing runs on upgrade.'
		);
	}//end testTheAppVersionMovedPastTheFleet()
}//end class
