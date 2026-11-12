<?php

/**
 * Case steps history test
 *
 * The date of a step comes from the case's own status history. A history that
 * arrives as JSON, carries other shapes of entry, names a status twice or
 * holds a moment that cannot be read must still date the steps it can and
 * leave the rest undated.
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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\CaseSteps;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Portal\CaseSteps
 * @uses   \OCA\Dossiq\Service\Transitions\StatusPublicLabels
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseStepsHistoryTest extends TestCase {

	/**
	 * Three statuses, three steps.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function statuses(): array {
		return [
			['id' => 's1', 'order' => 1, 'publicLabel' => 'Ontvangen'],
			['id' => 's2', 'order' => 2, 'publicLabel' => 'In behandeling'],
			['id' => 's3', 'order' => 3, 'publicLabel' => 'Besluit'],
		];
	}//end statuses()

	/**
	 * A JSON history is read; odd entries, repeats and unreadable moments are passed over.
	 *
	 * @return void
	 */
	public function testAJsonHistoryDatesWhatItCanRead(): void {
		$history = json_encode(
			[
				'not an entry',
				['status' => 's1', 'enteredAt' => '2026-03-01T10:00:00Z'],
				['status' => 's1', 'enteredAt' => '2026-03-09T10:00:00Z'],
				['statusType' => 's2', 'at' => 'not a moment'],
				['to' => 's3', 'date' => ''],
				['enteredAt' => '2026-03-05T10:00:00Z'],
			]
		);

		$steps = (new CaseSteps())->forCase(['status' => 's3', 'statusHistory' => $history], $this->statuses());

		$this->assertSame(
			[
				['label' => 'Ontvangen', 'state' => 'done', 'date' => '2026-03-01'],
				['label' => 'In behandeling', 'state' => 'done'],
				['label' => 'Besluit', 'state' => 'current'],
			],
			$steps
		);
	}//end testAJsonHistoryDatesWhatItCanRead()

	/**
	 * The current step falls back to the case's own entry moment.
	 *
	 * @return void
	 */
	public function testTheCurrentStepFallsBackToTheCasesEntryMoment(): void {
		$steps = (new CaseSteps())->forCase(
			['status' => 's2', 'statusHistory' => 'not json', 'currentStatusEnteredAt' => '2026-04-02T23:30:00Z'],
			$this->statuses()
		);

		$this->assertSame(['label' => 'In behandeling', 'state' => 'current', 'date' => '2026-04-02'], $steps[1]);
		$this->assertArrayNotHasKey('date', $steps[0]);
	}//end testTheCurrentStepFallsBackToTheCasesEntryMoment()

	/**
	 * Status types without any id are dropped; an id may come from uuid or @self.
	 *
	 * @return void
	 */
	public function testAStatusTypeWithoutAnIdIsNoStep(): void {
		$steps = (new CaseSteps())->forCase(
			['status' => 'u2'],
			[
				['order' => 1, 'publicLabel' => 'Zonder id'],
				['uuid' => 'u2', 'order' => 2, 'publicLabel' => 'Met uuid'],
				['@self' => ['id' => 7], 'order' => 3, 'publicLabel' => 'Met self'],
				'not a row',
			]
		);

		$this->assertSame(
			[
				['label' => 'Met uuid', 'state' => 'current'],
				['label' => 'Met self', 'state' => 'todo'],
			],
			$steps
		);
	}//end testAStatusTypeWithoutAnIdIsNoStep()

	/**
	 * A case with an end date stops after its current step.
	 *
	 * @return void
	 */
	public function testAnEndedCaseDoesNotPromiseTheRest(): void {
		$steps = (new CaseSteps())->forCase(['status' => 's1', 'endDate' => '2026-05-01'], $this->statuses());

		$this->assertSame([['label' => 'Ontvangen', 'state' => 'current']], $steps);
	}//end testAnEndedCaseDoesNotPromiseTheRest()
}//end class
