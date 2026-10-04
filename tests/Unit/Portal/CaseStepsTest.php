<?php

/**
 * CaseSteps (site-resident-portal-design D3): the public steps of one case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\CaseSteps;
use PHPUnit\Framework\TestCase;

/**
 * Folding a case type's statuses into what a resident reads.
 */
class CaseStepsTest extends TestCase {
	/**
	 * The seeded Woo type: eight internal statuses, five public steps. "In
	 * behandeling" is four consecutive statuses and is said once.
	 *
	 * @return array<int, array<string, mixed>> The status types.
	 */
	private function wooStatuses(): array {
		return [
			['id' => 's1', 'order' => 1, 'name' => 'Ontvangen', 'publicLabel' => 'Ontvangen', 'publicDescription' => 'Wij hebben uw verzoek binnen.'],
			['id' => 's2', 'order' => 2, 'name' => 'Toetsing', 'publicLabel' => 'In behandeling', 'publicDescription' => 'Wij zoeken de documenten.'],
			['id' => 's3', 'order' => 3, 'name' => 'Zoeken', 'publicLabel' => 'In behandeling'],
			['id' => 's4', 'order' => 4, 'name' => 'Beoordelen', 'publicLabel' => 'In behandeling'],
			['id' => 's5', 'order' => 5, 'name' => 'Derden horen', 'publicLabel' => 'In behandeling'],
			['id' => 's6', 'order' => 6, 'name' => 'Besluit', 'publicLabel' => 'Besluit'],
			['id' => 's7', 'order' => 7, 'name' => 'Besluit genomen', 'publicLabel' => 'Besluit genomen'],
			['id' => 's8', 'order' => 8, 'name' => 'Afgehandeld', 'publicLabel' => 'Afgehandeld'],
		];
	}//end wooStatuses()

	/**
	 * The seeded Woo type folds to five steps, the current one is the one the
	 * case sits in, and the date is when it entered that step.
	 *
	 * @return void
	 */
	public function testTheSeededWooTypeFoldsToFiveSteps(): void {
		$steps = (new CaseSteps())->forCase(
			case: [
				'status' => 's4',
				'statusHistory' => json_encode(
					[
						['status' => 's1', 'enteredAt' => '2026-10-01T09:00:00+00:00'],
						['status' => 's2', 'enteredAt' => '2026-10-02T11:30:00+00:00'],
						['status' => 's4', 'enteredAt' => '2026-10-06T08:00:00+00:00'],
					]
				),
			],
			statusTypes: $this->wooStatuses()
		);

		$this->assertSame(
			['Ontvangen', 'In behandeling', 'Besluit', 'Besluit genomen', 'Afgehandeld'],
			array_column($steps, 'label'),
			'four consecutive statuses that share a public label are one step'
		);
		$this->assertSame(
			[CaseSteps::DONE, CaseSteps::CURRENT, CaseSteps::TODO, CaseSteps::TODO, CaseSteps::TODO],
			array_column($steps, 'state')
		);
		$this->assertSame('2026-10-01', $steps[0]['date']);
		// The date of a folded step is when the case entered its FIRST status,
		// not the one it happens to sit in now.
		$this->assertSame('2026-10-02', $steps[1]['date']);
		$this->assertSame('Wij hebben uw verzoek binnen.', $steps[0]['description']);
		$this->assertSame('Wij zoeken de documenten.', $steps[1]['description']);
		$this->assertArrayNotHasKey('description', $steps[2]);
		$this->assertArrayNotHasKey('date', $steps[2], 'a step still to come has no date');
	}//end testTheSeededWooTypeFoldsToFiveSteps()

	/**
	 * A withdrawn case marks the step it stopped at and promises nothing
	 * after it.
	 *
	 * @return void
	 */
	public function testAWithdrawnCaseStopsAtItsStep(): void {
		$steps = (new CaseSteps())->forCase(
			case: ['status' => 's2', 'isFinalStatus' => true, 'currentStatusEnteredAt' => '2026-10-03T10:00:00+00:00'],
			statusTypes: $this->wooStatuses()
		);

		$this->assertSame(['Ontvangen', 'In behandeling'], array_column($steps, 'label'));
		$this->assertSame([CaseSteps::DONE, CaseSteps::CURRENT], array_column($steps, 'state'));
		$this->assertSame('2026-10-03', $steps[1]['date'], 'the current step falls back to the case clock');

		$closed = (new CaseSteps())->forCase(
			case: ['status' => 's8', 'endDate' => '2026-11-01'],
			statusTypes: $this->wooStatuses()
		);
		$this->assertSame(CaseSteps::CURRENT, end($closed)['state']);
		$this->assertCount(5, $closed, 'a case that ran its course shows every step');
	}//end testAWithdrawnCaseStopsAtItsStep()

	/**
	 * The label is the public one, or the status name when it declares none.
	 * A status with neither is no step at all.
	 *
	 * @return void
	 */
	public function testTheLabelFallsBackToTheStatusName(): void {
		$steps = (new CaseSteps())->forCase(
			case: ['status' => 'b'],
			statusTypes: [
				['id' => 'a', 'order' => 1, 'name' => 'Ontvangen'],
				['id' => 'b', 'order' => 2, 'name' => 'Intern overleg', 'publicLabel' => 'In behandeling'],
				['id' => 'c', 'order' => 3, 'name' => ''],
			]
		);

		$this->assertSame(['Ontvangen', 'In behandeling'], array_column($steps, 'label'));
	}//end testTheLabelFallsBackToTheStatusName()

	/**
	 * The order is the case type's `order`, whatever order the rows arrive
	 * in; a case whose status is none of them has no current step.
	 *
	 * @return void
	 */
	public function testTheOrderIsTheCaseTypesAndAnUnknownStatusHasNoCurrentStep(): void {
		$shuffled = array_reverse($this->wooStatuses());
		$steps = (new CaseSteps())->forCase(case: ['status' => 'gone'], statusTypes: $shuffled);

		$this->assertSame(
			['Ontvangen', 'In behandeling', 'Besluit', 'Besluit genomen', 'Afgehandeld'],
			array_column($steps, 'label')
		);
		$this->assertSame(
			[CaseSteps::TODO, CaseSteps::TODO, CaseSteps::TODO, CaseSteps::TODO, CaseSteps::TODO],
			array_column($steps, 'state'),
			'a status the case type no longer has marks nothing as current'
		);
	}//end testTheOrderIsTheCaseTypesAndAnUnknownStatusHasNoCurrentStep()

	/**
	 * A case type without status types has no steps, and a history that
	 * cannot be read costs the dates and not the steps.
	 *
	 * @return void
	 */
	public function testNoStatusTypesMeansNoStepsAndABadHistoryCostsOnlyTheDates(): void {
		$this->assertSame([], (new CaseSteps())->forCase(case: ['status' => 's1'], statusTypes: []));

		$steps = (new CaseSteps())->forCase(
			case: ['status' => 's1', 'statusHistory' => 'not json'],
			statusTypes: $this->wooStatuses()
		);
		$this->assertCount(5, $steps);
		$this->assertArrayNotHasKey('date', $steps[0]);
	}//end testNoStatusTypesMeansNoStepsAndABadHistoryCostsOnlyTheDates()
}//end class
