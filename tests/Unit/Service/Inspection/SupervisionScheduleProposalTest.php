<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use DateTimeImmutable;
use OCA\Dossiq\Service\Inspection\SupervisionScheduleProposal;
use PHPUnit\Framework\TestCase;

/**
 * The proposal rule: demand from risk frequency, cut to capacity, dated on working days, nothing written.
 */
class SupervisionScheduleProposalTest extends TestCase {

	/**
	 * Hospitality asks 4 high-risk and 2 low-risk visits at 5 hours; construction 4 unrated at 10 hours.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function categories(): array {
		return [
			['id' => 'hos', 'name' => 'Hospitality', 'planned' => 6, 'hoursPerInspection' => 5, 'riskFrequency' => [['profile' => 'low', 'objects' => 4, 'visitsPerYear' => 0.5], ['profile' => 'high', 'objects' => 2, 'visitsPerYear' => 2]]],
			['id' => 'con', 'name' => 'Construction', 'planned' => 4, 'hoursPerInspection' => 10],
		];
	}//end categories()

	/**
	 * Scenario "The proposal waits for approval": dates per category, and the proposal says nothing was written.
	 *
	 * @return void
	 */
	public function testTheProposalShowsDatesPerCategoryAndWritesNothing(): void {
		$proposal = (new SupervisionScheduleProposal())->propose(
			plan: ['year' => 2026, 'capacityHours' => 1600],
			categories: $this->categories(),
			from: new DateTimeImmutable('2026-01-01')
		);

		$this->assertFalse(condition: $proposal['written']);
		$this->assertFalse(condition: $proposal['cut']);
		$this->assertSame(expected: 70.0, actual: $proposal['demandHours']);
		$this->assertSame(expected: 70.0, actual: $proposal['proposedHours']);
		$this->assertSame(expected: 0.0, actual: $proposal['shortfallHours']);
		$this->assertSame(expected: [6, 4], actual: array_column($proposal['categories'], 'proposed'));

		$hospitality = $proposal['categories'][0]['visits'];
		$this->assertSame(expected: ['high', 'high', 'high', 'high', 'low', 'low'], actual: array_column($hospitality, 'riskProfile'));
		// 1 January 2026 is a Thursday: the first working day of the year.
		$this->assertSame(expected: '2026-01-01', actual: $hospitality[0]['date']);
		$this->assertSame(expected: ['unrated', 'unrated', 'unrated', 'unrated'], actual: array_column($proposal['categories'][1]['visits'], 'riskProfile'));
	}//end testTheProposalShowsDatesPerCategoryAndWritesNothing()

	/**
	 * Every proposed date is a working day in the plan's year, in ascending order.
	 *
	 * @return void
	 */
	public function testDatesAreWorkingDaysInOrder(): void {
		$proposal = (new SupervisionScheduleProposal())->propose(plan: ['year' => 2026], categories: $this->categories(), from: new DateTimeImmutable('2026-06-06'));

		foreach ($proposal['categories'] as $category) {
			$dates = array_column($category['visits'], 'date');
			$this->assertSame(expected: $dates, actual: array_values(array_unique($dates)));
			$sorted = $dates;
			sort($sorted);
			$this->assertSame(expected: $sorted, actual: $dates);
			foreach ($dates as $date) {
				$day = new DateTimeImmutable($date);
				$this->assertLessThanOrEqual(expected: 5, actual: (int)$day->format('N'), message: $date.' is a weekend day');
				$this->assertGreaterThanOrEqual(expected: '2026-06-08', actual: $date);
				$this->assertLessThanOrEqual(expected: '2026-12-31', actual: $date);
			}
		}
	}//end testDatesAreWorkingDaysInOrder()

	/**
	 * Demand above capacity is cut by the same share in every category, and the shortfall is reported.
	 *
	 * @return void
	 */
	public function testDemandAboveCapacityIsCutEvenly(): void {
		$proposal = (new SupervisionScheduleProposal())->propose(plan: ['year' => 2026, 'capacityHours' => 35], categories: $this->categories(), from: new DateTimeImmutable('2026-01-01'));

		$this->assertTrue(condition: $proposal['cut']);
		$this->assertSame(expected: 35.0, actual: $proposal['shortfallHours']);
		// Half fits: hospitality 4 high → 2, 2 low → 1; construction 4 → 2.
		$this->assertSame(expected: [3, 2], actual: array_column($proposal['categories'], 'proposed'));
		$this->assertSame(expected: 35.0, actual: $proposal['proposedHours']);
		$this->assertSame(expected: [6, 4], actual: array_column($proposal['categories'], 'demand'));
	}//end testDemandAboveCapacityIsCutEvenly()

	/**
	 * A plan with no capacity set is not cut to nothing.
	 *
	 * @return void
	 */
	public function testAnUnsetCapacityCutsNothing(): void {
		$proposal = (new SupervisionScheduleProposal())->propose(plan: ['year' => 2026], categories: $this->categories(), from: new DateTimeImmutable('2026-01-01'));

		$this->assertFalse(condition: $proposal['cut']);
		$this->assertSame(expected: [6, 4], actual: array_column($proposal['categories'], 'proposed'));
	}//end testAnUnsetCapacityCutsNothing()

	/**
	 * A start date after the plan's year leaves no working days, so nothing is dated.
	 *
	 * @return void
	 */
	public function testAYearThatIsOverProposesNoDates(): void {
		$proposal = (new SupervisionScheduleProposal())->propose(plan: ['year' => 2025], categories: $this->categories(), from: new DateTimeImmutable('2026-03-01'));

		$this->assertSame(expected: [0, 0], actual: array_column($proposal['categories'], 'proposed'));
		$this->assertSame(expected: 0.0, actual: $proposal['proposedHours']);
	}//end testAYearThatIsOverProposesNoDates()
}//end class
