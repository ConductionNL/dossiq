<?php

/**
 * ReachableStatuses carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\ReachableStatuses;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseType\ReachableStatuses
 */
class ReachableStatusesTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$value = new ReachableStatuses(
			statuses: ['open' => ['title' => 'Open', 'final' => false]],
			initial: 'open',
			reached: ['open' => true]
		);

		self::assertSame(expected: ['open' => ['title' => 'Open', 'final' => false]], actual: $value->statuses);
		self::assertSame(expected: 'open', actual: $value->initial);
		self::assertSame(expected: ['open' => true], actual: $value->reached);
	}//end testEveryPropertyReadsBack()
}//end class
