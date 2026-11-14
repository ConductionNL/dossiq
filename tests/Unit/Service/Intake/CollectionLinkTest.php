<?php

/**
 * CollectionLink carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CollectionLink;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Intake\CollectionLink
 */
class CollectionLinkTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$register = 'value-2';
		$caseId = 'value-3';
		$collection = ['key' => 4];

		$value = new CollectionLink(
			objectService: $objectService,
			register: $register,
			caseId: $caseId,
			collection: $collection,
		);

		self::assertSame(expected: $objectService, actual: $value->objectService);
		self::assertSame(expected: $register, actual: $value->register);
		self::assertSame(expected: $caseId, actual: $value->caseId);
		self::assertSame(expected: $collection, actual: $value->collection);
	}//end testEveryPropertyReadsBack()
}//end class
