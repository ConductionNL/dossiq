<?php

/**
 * Draft, in use and retired, derived from the three fields that already answer it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseType\CaseTypeLifecycleState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseTypeLifecycleState.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeLifecycleState
 */
class CaseTypeLifecycleStateTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var CaseTypeLifecycleState
	 */
	private CaseTypeLifecycleState $state;

	/**
	 * The day every case below is judged on.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $today;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->state = new CaseTypeLifecycleState();
		$this->today = new DateTimeImmutable('2026-09-15');
	}//end setUp()

	/**
	 * A draft is a draft whatever its dates say.
	 *
	 * @return void
	 */
	public function testADraftIsADraftEvenInsideItsValidityWindow(): void {
		$caseType = ['isDraft' => true, 'validFrom' => '2026-01-01', 'validUntil' => '2027-01-01'];

		self::assertSame(
			expected: CaseTypeLifecycleState::DRAFT,
			actual: $this->state->of(caseType: $caseType, on: $this->today)
		);
		self::assertFalse(condition: $this->state->acceptsNewCases(caseType: $caseType, on: $this->today));
	}//end testADraftIsADraftEvenInsideItsValidityWindow()

	/**
	 * A published type with no dates takes cases.
	 *
	 * @return void
	 */
	public function testAPublishedTypeWithNoDatesIsInUse(): void {
		$caseType = ['isDraft' => false];

		self::assertSame(
			expected: CaseTypeLifecycleState::IN_USE,
			actual: $this->state->of(caseType: $caseType, on: $this->today)
		);
		self::assertTrue(condition: $this->state->acceptsNewCases(caseType: $caseType, on: $this->today));
	}//end testAPublishedTypeWithNoDatesIsInUse()

	/**
	 * A regeling that ended stops taking new cases.
	 *
	 * @return void
	 */
	public function testARegelingThatEndedIsRetired(): void {
		$caseType = ['isDraft' => false, 'validUntil' => '2026-09-14'];

		self::assertSame(
			expected: CaseTypeLifecycleState::RETIRED,
			actual: $this->state->of(caseType: $caseType, on: $this->today)
		);
	}//end testARegelingThatEndedIsRetired()

	/**
	 * The end date is inclusive: the last day still takes cases.
	 *
	 * An off-by-one here retires a case type a day early, and the only symptom
	 * is a handler being told a type they can see does not exist.
	 *
	 * @return void
	 */
	public function testTheLastValidDayStillTakesCases(): void {
		$caseType = ['isDraft' => false, 'validUntil' => '2026-09-15'];

		self::assertSame(
			expected: CaseTypeLifecycleState::IN_USE,
			actual: $this->state->of(caseType: $caseType, on: $this->today)
		);
	}//end testTheLastValidDayStillTakesCases()

	/**
	 * A type whose window has not opened is not in use either.
	 *
	 * @return void
	 */
	public function testATypeWhoseWindowHasNotOpenedIsRetired(): void {
		$caseType = ['isDraft' => false, 'validFrom' => '2026-10-01'];

		self::assertSame(
			expected: CaseTypeLifecycleState::RETIRED,
			actual: $this->state->of(caseType: $caseType, on: $this->today)
		);
	}//end testATypeWhoseWindowHasNotOpenedIsRetired()

	/**
	 * A date that arrived with a time and a zone is still read as its day.
	 *
	 * Comparing the raw strings would sort `2026-09-15T00:00:00+02:00` after
	 * `2026-09-15` and retire the type a day early.
	 *
	 * @return void
	 */
	public function testADateWithATimeIsReadAsItsDay(): void {
		$caseType = ['isDraft' => false, 'validUntil' => '2026-09-15T09:30:00+02:00'];

		self::assertSame(
			expected: CaseTypeLifecycleState::IN_USE,
			actual: $this->state->of(caseType: $caseType, on: $this->today)
		);
	}//end testADateWithATimeIsReadAsItsDay()

	/**
	 * An empty date is no date, not the first of January.
	 *
	 * @return void
	 */
	public function testAnEmptyDateDoesNotRetireTheType(): void {
		$caseType = ['isDraft' => false, 'validFrom' => '', 'validUntil' => ''];

		self::assertSame(
			expected: CaseTypeLifecycleState::IN_USE,
			actual: $this->state->of(caseType: $caseType, on: $this->today)
		);
	}//end testAnEmptyDateDoesNotRetireTheType()
}//end class
