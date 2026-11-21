<?php

/**
 * PauseStep unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Pause
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pause;

use OCA\Dossiq\Service\Pause\PauseStep;
use PHPUnit\Framework\TestCase;

/**
 * What a reminder and an escalation read as, and the basis they are recorded under.
 *
 * @covers \OCA\Dossiq\Service\Pause\PauseStep
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class PauseStepTest extends TestCase {

	/**
	 * A reminder names its number, the budget and the reason; a nameless reason reads by its key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAReminderReadsItsNumberBudgetAndReason(): void {
		$step = $this->step(reason: ['name' => ' Aanvulling gevraagd ', 'key' => 'aanvulling', 'chaseBudget' => '3']);
		$step->number = 2;

		$this->assertSame(expected: 'Reminder 2 of 3 sent: Aanvulling gevraagd', actual: $step->chasedSentence());

		$nameless = $this->step(reason: ['name' => '  ', 'key' => 'aanvulling', 'chaseBudget' => 3]);
		$nameless->number = 1;

		$this->assertSame(expected: 'Reminder 1 of 3 sent: aanvulling', actual: $nameless->chasedSentence());
	}//end testAReminderReadsItsNumberBudgetAndReason()

	/**
	 * The escalation names who it went to only when the reason says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAnEscalationNamesItsTargetOnlyWhenDeclared(): void {
		$this->assertSame(
			expected: 'No reply after 3 reminders. Escalated to teamlead.',
			actual: $this->step(reason: ['escalateTo' => ' teamlead '])->escalatedSentence(sent: 3)
		);
		$this->assertSame(
			expected: 'No reply after 0 reminders.',
			actual: $this->step(reason: ['escalateTo' => ''])->escalatedSentence(sent: 0)
		);
	}//end testAnEscalationNamesItsTargetOnlyWhenDeclared()

	/**
	 * A declared basis is kept; none falls back to Awb 4:5.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheBasisFallsBackToAwbFourFive(): void {
		$this->assertSame(expected: 'Wabo 3.9', actual: $this->step(reason: ['legalBasis' => ' Wabo 3.9 '])->basis());
		$this->assertSame(expected: 'Awb 4:5', actual: $this->step(reason: ['legalBasis' => ''])->basis());
	}//end testTheBasisFallsBackToAwbFourFive()

	/**
	 * A step carrying a reason.
	 *
	 * @param array<string, mixed> $reason The normalised reason.
	 *
	 * @return PauseStep The step.
	 */
	private function step(array $reason): PauseStep {
		$step = new PauseStep();
		$step->reason = $reason;

		return $step;
	}//end step()
}//end class
