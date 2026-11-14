<?php

/**
 * CaseTypeStore carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CaseTypeStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\CaseTypeStore
 */
class CaseTypeStoreTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$register = 'value-2';
		$schema = 'value-3';

		$value = new CaseTypeStore(
			objectService: $objectService,
			register: $register,
			schema: $schema,
		);

		self::assertSame(expected: $objectService, actual: $value->objectService);
		self::assertSame(expected: $register, actual: $value->register);
		self::assertSame(expected: $schema, actual: $value->schema);
	}//end testEveryPropertyReadsBack()
}//end class
