<?php

/**
 * RoutedCase carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\RoutedCase;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Routing\RoutedCase
 */
class RoutedCaseTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$routedAt = new \DateTimeImmutable('2026-01-01');
		$value = new RoutedCase(
			caseId: 'case-1',
			routedTo: 'alice',
			routedAt: $routedAt
		);

		self::assertSame(expected: 'case-1', actual: $value->caseId);
		self::assertSame(expected: 'alice', actual: $value->routedTo);
		self::assertSame(expected: $routedAt, actual: $value->routedAt);
	}//end testEveryPropertyReadsBack()
}//end class
