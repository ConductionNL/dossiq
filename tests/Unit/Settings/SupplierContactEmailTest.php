<?php

/**
 * A supplier carries the address its notices are mailed to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/tasks.md#T3
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Decision 128: `supplier.contactEmail` is the address OpenRegister reads
 * through a supplier message's `supplierRef`.
 *
 * @coversNothing
 */
class SupplierContactEmailTest extends TestCase {

	/**
	 * A supplier with an address validates; a malformed address is refused.
	 *
	 * @return void
	 */
	public function testTheSupplierSchemaTakesAnEmailAddress(): void {
		$register = new RealSchemaValidator();
		$property = ($register->schemas['supplier']['properties']['contactEmail'] ?? []);

		$this->assertSame('email', ($property['format'] ?? null));
		$this->assertSame(
			[],
			$register->errors(slug: 'supplier', payload: ['contactEmail' => 'inkoop@bouwbedrijf.example'], creating: false)
		);
		$this->assertNotSame(
			[],
			$register->errors(slug: 'supplier', payload: ['contactEmail' => 'geen adres'], creating: false)
		);
	}//end testTheSupplierSchemaTakesAnEmailAddress()

	/**
	 * A supplier message reaches its supplier through `supplierRef`, the hop
	 * OpenRegister's email-through-reference recipient will take.
	 *
	 * @return void
	 */
	public function testASupplierMessageNamesItsSupplier(): void {
		$register = new RealSchemaValidator();

		$this->assertArrayHasKey('supplierRef', $register->schemas['supplierMessage']['properties']);
	}//end testASupplierMessageNamesItsSupplier()
}//end class
