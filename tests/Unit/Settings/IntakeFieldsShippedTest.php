<?php

/**
 * The two moments reach an instance that already imported the register.
 *
 * 🔴 A SAME-NUMBER VERSION COLLISION DOES NOT CONFLICT: two branches taking
 * one new number merge that line in silence and `ImportHandler` then skips the
 * second import, so the fields reach a fresh install and no existing instance
 * (dossiq#2938). The floor is the highest number already claimed when this
 * change landed, which includes the two branches ahead of it in the queue,
 * not the number this branch started from.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The shipped register carries the intake stamp, above the floor.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeFieldsShippedTest extends TestCase {
	/**
	 * The highest register version claimed when this change landed: 0.20.1 on
	 * the integration branch, 0.20.2 on fees-and-payments and 0.20.3 on
	 * routing-by-weight, both of which merge first.
	 *
	 * @var string
	 */
	private const FLOOR = '0.20.3';

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
				'The register is on %s and %s is already claimed. An import at or below an existing version is SKIPPED, so the '
				. 'two moments would reach a fresh install and no existing instance.',
				$version,
				self::FLOOR
			)
		);
	}//end testTheRegisterVersionMovedPastEveryClaimedNumber()

	/**
	 * And it carries the three fields that bump is for, as evidence rather
	 * than as editable fields.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesBothMomentsAndTheFlag(): void {
		$properties = $this->register()['components']['schemas']['case']['properties'];

		foreach (['receivedAt', 'termStartsAt', 'receivedOutsideWorkingHours'] as $field) {
			$this->assertArrayHasKey($field, $properties, sprintf('case.%s is missing', $field));
			$this->assertTrue($properties[$field]['readOnly'], sprintf('case.%s must not be editable', $field));
		}

		// Moments, not dates: a `date` format renders a Sunday evening filing
		// and a Monday morning one identical, which is the whole distinction.
		$this->assertSame('date-time', $properties['receivedAt']['format']);
		$this->assertSame('date-time', $properties['termStartsAt']['format']);
	}//end testTheCaseCarriesBothMomentsAndTheFlag()

	/**
	 * The app version moved too, so the listener this change registers runs on
	 * an upgrade.
	 *
	 * @return void
	 */
	public function testTheAppVersionMovedPastTheFleet(): void {
		$xml = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertNotFalse($xml, 'info.xml could not be read');

		$this->assertTrue(
			version_compare((string)$xml->version, '0.4.31-unstable.20260918120500', '>'),
			'The app version must be strictly above every number already claimed.'
		);
	}//end testTheAppVersionMovedPastTheFleet()
}//end class
