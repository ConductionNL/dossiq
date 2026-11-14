<?php

/**
 * Unit test for CasePropertyStore.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CasePropertyStore;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Repair\CasePropertyStore
 */
class CasePropertyStoreTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$register = 'register-value';
		$caseSchema = 'caseSchema-value';
		$valueSchema = 'valueSchema-value';
		$defSchema = 'defSchema-value';

		$value = new CasePropertyStore(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			valueSchema: $valueSchema,
			defSchema: $defSchema,
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: $register, actual: $value->register);
		$this->assertSame(expected: $caseSchema, actual: $value->caseSchema);
		$this->assertSame(expected: $valueSchema, actual: $value->valueSchema);
		$this->assertSame(expected: $defSchema, actual: $value->defSchema);
	}//end testEveryPropertyReadsBack()
}//end class
