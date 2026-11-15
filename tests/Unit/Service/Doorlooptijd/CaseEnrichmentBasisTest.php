<?php

/**
 * CaseEnrichmentBasis carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Doorlooptijd;

use OCA\Dossiq\Service\Doorlooptijd\CaseEnrichmentBasis;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Doorlooptijd\CaseEnrichmentBasis
 */
class CaseEnrichmentBasisTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$today = new \DateTimeImmutable('2026-01-01');
		$value = new CaseEnrichmentBasis(
			caseTypeByKey: ['bouw' => ['id' => 'bouw']],
			today: $today
		);

		self::assertSame(expected: ['bouw' => ['id' => 'bouw']], actual: $value->caseTypeByKey);
		self::assertSame(expected: $today, actual: $value->today);
	}//end testEveryPropertyReadsBack()
}//end class
