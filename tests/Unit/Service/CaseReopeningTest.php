<?php

/**
 * CaseReopening carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseReopening;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseReopening
 */
class CaseReopeningTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'value-1';
		$initial = 'value-2';
		$fromStatus = 'value-3';
		$reason = 'value-4';

		$value = new CaseReopening(
			caseId: $caseId,
			initial: $initial,
			fromStatus: $fromStatus,
			reason: $reason,
		);

		self::assertSame(expected: $caseId, actual: $value->caseId);
		self::assertSame(expected: $initial, actual: $value->initial);
		self::assertSame(expected: $fromStatus, actual: $value->fromStatus);
		self::assertSame(expected: $reason, actual: $value->reason);
	}//end testEveryPropertyReadsBack()
}//end class
