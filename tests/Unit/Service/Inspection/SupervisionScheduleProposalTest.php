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
use OCA\Dossiq\Service\Inspection\SupervisionPlanProgress;
use OCA\Dossiq\Service\Inspection\SupervisionScheduleProposal;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The proposal rule: demand from risk frequency, cut to capacity, dated on working days, nothing written.
 */
class SupervisionScheduleProposalTest extends TestCase {

	/**
	 * The proposal, with today on 24 November 2026.
	 *
	 * @return SupervisionScheduleProposal The proposal.
	 */
	private function proposal(): SupervisionScheduleProposal {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-24T10:00:00+01:00'));

		return new SupervisionScheduleProposal(progress: new SupervisionPlanProgress(), time: $time);
	}//end proposal()

	/**
	 * Hospitality asks 4 high-risk and 2 low-risk visits at 5 hours; construction 4 unrated at 10 hours.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function categories(): array {
		$risk = [
			['profile' => 'low', 'objects' => 4, 'visitsPerYear' => 0.5],
			['profile' => 'high', 'objects' => 2, 'visitsPerYear' => 2],
		];

		return [
			['id' => 'hos', 'name' => 'Hospitality', 'planned' => 6, 'hoursPerInspection' => 5, 'riskFrequency' => $risk],
			['id' => 'con', 'name' => 'Construction', 'planned' => 4, 'hoursPerInspection' => 10],
		];
	}//end categories()

	/**
	 * The proposal for a plan.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param string               $from The first day.
	 *
	 * @return array<string, mixed> The proposal.
	 */
	private function propose(array $plan, string $from='2026-01-01'): array {
		return $this->proposal()->propose(plan: $plan, categories: $this->categories(), from: $from);
	}//end propose()

	/**
	 * Scenario "The proposal waits for approval": dates per category, and the proposal says nothing was written.
	 *
	 * @return void
	 */
	public function testTheProposalShowsDatesPerCategoryAndWritesNothing(): void {
		$proposal = $this->propose(plan: ['year' => 2026, 'capacityHours' => 1600]);

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
		$this->assertSame(expected: array_fill(0, 4, 'unrated'), actual: array_column($proposal['categories'][1]['visits'], 'riskProfile'));
	}//end testTheProposalShowsDatesPerCategoryAndWritesNothing()

	/**
	 * Every proposed date is a working day in the plan's year, in ascending order.
	 *
	 * @return void
	 */
	public function testDatesAreWorkingDaysInOrder(): void {
		$proposal = $this->propose(plan: ['year' => 2026], from: '2026-06-06');

		foreach ($proposal['categories'] as $category) {
			$dates  = array_column($category['visits'], 'date');
			$sorted = $dates;
			sort($sorted);
			$this->assertSame(expected: $sorted, actual: $dates);
			$this->assertSame(expected: $dates, actual: array_values(array_unique($dates)));
			foreach ($dates as $date) {
				$this->assertLessThanOrEqual(expected: 5, actual: (int)(new DateTimeImmutable($date))->format('N'), message: $date.' is a weekend day');
				$this->assertGreaterThanOrEqual(expected: '2026-06-08', actual: $date);
				$this->assertLessThanOrEqual(expected: '2026-12-31', actual: $date);
			}
		}
	}//end testDatesAreWorkingDaysInOrder()

	/**
	 * Each category names the span of its visits, first and last date, so a table row says it without the visits;
	 * a category with no visit names none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
	 */
	public function testEachCategoryNamesTheSpanOfItsVisits(): void {
		$proposal = $this->propose(plan: ['year' => 2026, 'capacityHours' => 1600]);

		foreach ($proposal['categories'] as $category) {
			$dates = array_column($category['visits'], 'date');
			$this->assertSame(expected: $dates[0], actual: $category['firstDate']);
			$this->assertSame(expected: $dates[count($dates) - 1], actual: $category['lastDate']);
		}

		$this->assertSame(expected: '2026-01-01', actual: $proposal['categories'][0]['firstDate']);

		$late = $this->propose(plan: ['year' => 2026], from: '2027-01-04');
		foreach ($late['categories'] as $category) {
			$this->assertSame(expected: [], actual: $category['visits']);
			$this->assertSame(expected: '', actual: $category['firstDate']);
			$this->assertSame(expected: '', actual: $category['lastDate']);
		}
	}//end testEachCategoryNamesTheSpanOfItsVisits()

	/**
	 * Demand above capacity is cut by the same share in every category, and the shortfall is reported.
	 *
	 * @return void
	 */
	public function testDemandAboveCapacityIsCutEvenly(): void {
		$proposal = $this->propose(plan: ['year' => 2026, 'capacityHours' => 35]);

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
		$proposal = $this->propose(plan: ['year' => 2026]);

		$this->assertFalse(condition: $proposal['cut']);
		$this->assertSame(expected: [6, 4], actual: array_column($proposal['categories'], 'proposed'));
	}//end testAnUnsetCapacityCutsNothing()

	/**
	 * Without a valid first day the proposal starts today; a year that is over proposes no dates.
	 *
	 * @return void
	 */
	public function testTheFirstDayIsTodayUnlessGiven(): void {
		$today = $this->propose(plan: ['year' => 2026], from: '2026-02-30');
		$over  = $this->propose(plan: ['year' => 2025], from: '');

		$this->assertSame(expected: '2026-11-24', actual: $today['categories'][0]['visits'][0]['date']);
		$this->assertSame(expected: [0, 0], actual: array_column($over['categories'], 'proposed'));
		$this->assertSame(expected: 0.0, actual: $over['proposedHours']);
	}//end testTheFirstDayIsTodayUnlessGiven()
}//end class
