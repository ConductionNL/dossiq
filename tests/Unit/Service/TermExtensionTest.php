<?php

/**
 * TermExtension carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TermExtension;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\TermExtension
 */
class TermExtensionTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'value-1';
		$reason = 'value-2';
		$newEnd = 'value-3';

		$value = new TermExtension(
			caseId: $caseId,
			reason: $reason,
			newEnd: $newEnd,
		);

		self::assertSame(expected: $caseId, actual: $value->caseId);
		self::assertSame(expected: $reason, actual: $value->reason);
		self::assertSame(expected: $newEnd, actual: $value->newEnd);
	}//end testEveryPropertyReadsBack()
}//end class
