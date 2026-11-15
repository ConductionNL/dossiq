<?php

/**
 * PlannedFlowQuery carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\PlannedFlowQuery;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Flow\PlannedFlowQuery
 */
class PlannedFlowQueryTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$today = new \DateTimeImmutable('2026-01-01');
		$value = new PlannedFlowQuery(
			caseId: 'case-1',
			today: $today
		);

		self::assertSame(expected: 'case-1', actual: $value->caseId);
		self::assertSame(expected: $today, actual: $value->today);
	}//end testEveryPropertyReadsBack()
}//end class
