<?php

/**
 * CreatedCase carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CreatedCase;
use OCA\Dossiq\Listener\NewCaseIdentity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\CreatedCase
 */
class CreatedCaseTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$identity = new NewCaseIdentity(caseId: 'case-1', caseTypeRef: 'type-1', caseType: 'bouw');
		$value = new CreatedCase(
			identity: $identity,
			payload: ['startDate' => '2026-01-01']
		);

		self::assertSame(expected: $identity, actual: $value->identity);
		self::assertSame(expected: ['startDate' => '2026-01-01'], actual: $value->payload);
	}//end testEveryPropertyReadsBack()
}//end class
