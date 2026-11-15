<?php

/**
 * A validity date that is no date bounds nothing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseType\CaseTypeLifecycleState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseTypeLifecycleState on unparseable dates.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeLifecycleState
 */
class CaseTypeLifecycleStateUnparseableDateTest extends TestCase {

	/**
	 * Text that is not a date is read as no bound, not as a day that retires the type.
	 *
	 * A raw string comparison would sort "gisteren" after every `Y-m-d` and
	 * retire the case type on both sides of its window; an unparseable
	 * bound is no bound, and a parseable one beside it still applies.
	 *
	 * @return void
	 */
	public function testAnUnparseableBoundIsNoBound(): void {
		$state = new CaseTypeLifecycleState();
		$day = new DateTimeImmutable('2026-09-15');

		$this->assertSame(
			CaseTypeLifecycleState::IN_USE,
			$state->stateOf(caseType: ['isDraft' => false, 'validFrom' => 'gisteren', 'validUntil' => 'morgen ooit'], onDay: $day)
		);
		$this->assertTrue($state->acceptsNewCases(caseType: ['validUntil' => 'nooit'], onDay: $day));
		$this->assertSame(
			CaseTypeLifecycleState::RETIRED,
			$state->stateOf(caseType: ['validFrom' => 'gisteren', 'validUntil' => '2026-09-14'], onDay: $day)
		);
	}//end testAnUnparseableBoundIsNoBound()
}//end class
