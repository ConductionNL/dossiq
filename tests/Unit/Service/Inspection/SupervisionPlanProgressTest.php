<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\SupervisionPlanProgress;
use PHPUnit\Framework\TestCase;

/**
 * The plan's figures are counted from its inspections, never stored.
 */
class SupervisionPlanProgressTest extends TestCase {

	/**
	 * The three 2026 categories of the scenario.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function categories(): array {
		$risk = [
			['profile' => 'high', 'objects' => 10, 'visitsPerYear' => 2],
			['profile' => 'low', 'objects' => 39, 'visitsPerYear' => 0.5],
		];

		return [
			['id' => 'env', 'name' => 'Environment', 'planned' => 50, 'order' => 3, 'hoursPerInspection' => 10],
			['id' => 'hos', 'name' => 'Hospitality', 'planned' => 40, 'order' => 1, 'hoursPerInspection' => 6, 'riskFrequency' => $risk],
			['@self' => ['id' => 'con'], 'name' => 'Construction', 'planned' => 60, 'order' => 2, 'hoursPerInspection' => 12],
		];
	}//end categories()

	/**
	 * Inspections in a category: some completed, some cancelled, the rest open.
	 *
	 * @param string $category  The category uuid.
	 * @param int    $completed How many completed.
	 * @param int    $cancelled How many cancelled.
	 * @param int    $open      How many still open.
	 * @param string $scheduled The scheduled date.
	 *
	 * @return list<array<string, mixed>> The inspections.
	 */
	private function inspections(string $category, int $completed, int $cancelled=0, int $open=0, string $scheduled='2026-02-10T09:00:00+01:00'): array {
		$base = ['planCategory' => $category, 'scheduledAt' => $scheduled];

		return array_merge(
			array_fill(0, $completed, array_merge($base, ['completedAt' => '2026-02-10T11:00:00+01:00'])),
			array_fill(0, $cancelled, array_merge($base, ['cancelledAt' => '2026-02-01T08:00:00+01:00'])),
			array_fill(0, $open, array_merge($base, ['status' => 'planned']))
		);
	}//end inspections()

	/**
	 * The figures of the categories over these inspections.
	 *
	 * @param list<array<string, mixed>> $inspections The inspections.
	 * @param array<string, mixed>       $plan        The plan.
	 *
	 * @return array<string, mixed> The figures.
	 */
	private function measure(array $inspections, array $plan=[]): array {
		return (new SupervisionPlanProgress())->measure(plan: $plan, categories: $this->categories(), inspections: $inspections);
	}//end measure()

	/**
	 * Scenario "The plan shows its progress per category": Scheduled 150, Completed 55, "15 of 40", "22 of 60", "18 of 50".
	 *
	 * @return void
	 */
	public function testThePlanShowsItsProgressPerCategory(): void {
		$inspections = array_merge(
			$this->inspections(category: 'hos', completed: 15),
			$this->inspections(category: 'con', completed: 22),
			$this->inspections(category: 'env', completed: 18)
		);

		$figures = $this->measure(inspections: $inspections, plan: ['year' => 2026, 'capacityHours' => 1600]);

		$this->assertSame(expected: 150, actual: $figures['scheduled']);
		$this->assertSame(expected: 55, actual: $figures['completed']);
		$this->assertSame(expected: 37, actual: $figures['completion']);
		$this->assertSame(expected: 95, actual: $figures['remaining']);
		$this->assertSame(expected: ['15 of 40', '22 of 60', '18 of 50'], actual: array_column($figures['categories'], 'label'));
		$this->assertSame(expected: ['Hospitality', 'Construction', 'Environment'], actual: array_column($figures['categories'], 'name'));
		$this->assertSame(expected: 1600.0, actual: $figures['capacityHours']);
	}//end testThePlanShowsItsProgressPerCategory()

	/**
	 * Scenario "A completed inspection counts at once": completing one moves it from remaining to completed.
	 *
	 * @return void
	 */
	public function testACompletedInspectionCountsAtOnce(): void {
		$open = ['planCategory' => 'con', 'scheduledAt' => '2026-05-04T09:00:00+02:00', 'status' => 'planned'];

		$before = $this->measure(inspections: [$open]);
		$after  = $this->measure(inspections: [array_merge($open, ['completedAt' => '2026-05-04T11:00:00+02:00'])]);

		$this->assertSame(expected: 0, actual: $before['categories'][1]['completed']);
		$this->assertSame(expected: 1, actual: $after['categories'][1]['completed']);
		$this->assertSame(expected: '1 of 60', actual: $after['categories'][1]['label']);
	}//end testACompletedInspectionCountsAtOnce()

	/**
	 * A cancelled inspection counts as cancelled, not as remaining; cancelling wins over a completion date.
	 *
	 * @return void
	 */
	public function testACancelledInspectionIsNotRemaining(): void {
		$inspections   = $this->inspections(category: 'hos', completed: 2, cancelled: 3, open: 1);
		$inspections[] = ['planCategory' => 'hos', 'completedAt' => '2026-03-01', 'cancelledAt' => '2026-02-28'];

		$row = $this->measure(inspections: $inspections)['categories'][0];

		$this->assertSame(expected: 2, actual: $row['completed']);
		$this->assertSame(expected: 4, actual: $row['cancelled']);
		$this->assertSame(expected: 34, actual: $row['remaining']);
	}//end testACancelledInspectionIsNotRemaining()

	/**
	 * The quarterly overview places an inspection by its scheduled date and forecasts completed plus open.
	 *
	 * @return void
	 */
	public function testTheQuartersFollowTheScheduledDate(): void {
		$inspections = array_merge(
			$this->inspections(category: 'hos', completed: 2, cancelled: 1, open: 1),
			$this->inspections(category: 'con', completed: 0, cancelled: 0, open: 3, scheduled: '2026-11-03'),
			[['planCategory' => 'env', 'status' => 'planned']],
			[['planCategory' => 'other-plan', 'scheduledAt' => '2026-06-01']]
		);

		$quarters = $this->measure(inspections: $inspections)['quarters'];

		$first = ['quarter' => 1, 'scheduled' => 4, 'completed' => 2, 'cancelled' => 1, 'remaining' => 1, 'forecast' => 3];
		$this->assertSame(expected: $first, actual: $quarters[0]);
		$this->assertSame(expected: 0, actual: $quarters[1]['scheduled']);
		$this->assertSame(expected: 3, actual: $quarters[3]['scheduled']);
		$this->assertSame(expected: 3, actual: $quarters[3]['forecast']);
	}//end testTheQuartersFollowTheScheduledDate()

	/**
	 * Demand is objects times visits rounded up per profile, in hours by the category's hours per inspection.
	 *
	 * @return void
	 */
	public function testDemandComesFromTheRiskFrequency(): void {
		$figures = $this->measure(inspections: []);

		// Hospitality: 10 × 2 = 20, 39 × 0.5 = 19.5 → 20, so 40 at 6 hours.
		$this->assertSame(expected: 40, actual: $figures['categories'][0]['demand']);
		$this->assertSame(expected: 240.0, actual: $figures['categories'][0]['demandHours']);
		// The others name no frequency: their planned count is their demand.
		$this->assertSame(expected: 60, actual: $figures['categories'][1]['demand']);
		$this->assertSame(expected: 240.0 + 720.0 + 500.0, actual: $figures['demandHours']);
		$this->assertSame(expected: 0, actual: $figures['completion']);
	}//end testDemandComesFromTheRiskFrequency()

	/**
	 * A plan without categories answers zeros, not a division by zero.
	 *
	 * @return void
	 */
	public function testAnEmptyPlanAnswersZeros(): void {
		$figures = (new SupervisionPlanProgress())->measure(plan: ['year' => 2026], categories: [], inspections: []);

		$this->assertSame(expected: 0, actual: $figures['scheduled']);
		$this->assertSame(expected: 0, actual: $figures['completion']);
		$this->assertSame(expected: [], actual: $figures['categories']);
		$this->assertCount(expectedCount: 4, haystack: $figures['quarters']);
	}//end testAnEmptyPlanAnswersZeros()

	/**
	 * The uuid is read from the top level, then `@self`, then `uuid`.
	 *
	 * @return void
	 */
	public function testIdOfReadsEveryShape(): void {
		$progress = new SupervisionPlanProgress();

		$this->assertSame(expected: 'a', actual: $progress->idOf(record: ['id' => 'a']));
		$this->assertSame(expected: 'b', actual: $progress->idOf(record: ['@self' => ['id' => 'b']]));
		$this->assertSame(expected: 'c', actual: $progress->idOf(record: ['uuid' => 'c', '@self' => 'x']));
		$this->assertSame(expected: '', actual: $progress->idOf(record: []));
	}//end testIdOfReadsEveryShape()
}//end class
