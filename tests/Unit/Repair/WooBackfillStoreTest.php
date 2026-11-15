<?php

/**
 * Unit test for WooBackfillStore.
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

use OCA\Dossiq\Repair\WooBackfillStore;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Repair\WooBackfillStore
 */
class WooBackfillStoreTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$register = 'register-value';
		$decisionSchema = 'decisionSchema-value';
		$caseSchema = 'caseSchema-value';

		$value = new WooBackfillStore(
			objectService: $objectService,
			register: $register,
			decisionSchema: $decisionSchema,
			caseSchema: $caseSchema,
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: $register, actual: $value->register);
		$this->assertSame(expected: $decisionSchema, actual: $value->decisionSchema);
		$this->assertSame(expected: $caseSchema, actual: $value->caseSchema);
	}//end testEveryPropertyReadsBack()
}//end class
