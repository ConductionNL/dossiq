<?php

/**
 * CaseVisit carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseVisit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseVisit
 */
class CaseVisitTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$mapper = new \stdClass();
		$case = new \stdClass();
		$readerId = 'value-3';

		$value = new CaseVisit(
			mapper: $mapper,
			case: $case,
			readerId: $readerId,
		);

		self::assertSame(expected: $mapper, actual: $value->mapper);
		self::assertSame(expected: $case, actual: $value->case);
		self::assertSame(expected: $readerId, actual: $value->readerId);
	}//end testEveryPropertyReadsBack()
}//end class
