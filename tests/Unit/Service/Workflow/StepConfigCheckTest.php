<?php

/**
 * Unit tests for StepConfigCheck: every object step of a workflow definition
 * has its config validated under its own index, and the errors of all steps
 * are returned together.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\StepConfigCheck;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Workflow\StepConfigCheck
 *
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowJsonProperty
 * @uses \OCA\Dossiq\Service\StepConfigValidator
 * @uses \OCA\Dossiq\Service\StepConfigScope
 * @uses \OCA\Dossiq\Service\StepConfig\EscalationRuleValidator
 */
class StepConfigCheckTest extends TestCase {

	public function testErrorsOfEveryStepAreCollectedUnderTheirOwnIndex(): void {
		$definition = [
			'steps' => json_encode(
				[
					['id' => 'intake'],
					['id' => 'review', 'config' => 'broken'],
					'junk',
					['id' => 'decide', 'config' => ['sla' => ['value' => 0, 'unit' => 'weeks']]],
				]
			),
		];

		$this->assertSame(
			[
				['path' => 'steps[1].config', 'code' => 'malformed_config', 'message' => 'config must be an object'],
				['path' => 'steps[3].config.sla.value', 'code' => 'out_of_range', 'message' => 'sla.value must be a positive integer not greater than 10000'],
				['path' => 'steps[3].config.sla.unit', 'code' => 'unknown_sla_unit', 'message' => 'sla.unit must be one of: hours, businessDays, calendarDays'],
			],
			(new StepConfigCheck())->errorsFor($definition)
		);
	}//end testErrorsOfEveryStepAreCollectedUnderTheirOwnIndex()

	public function testValidOrAbsentStepsHaveNoErrors(): void {
		$check = new StepConfigCheck();

		$this->assertSame([], $check->errorsFor(['steps' => [['id' => 'decide', 'config' => ['sla' => ['value' => 5, 'unit' => 'businessDays']]]]]));
		$this->assertSame([], $check->errorsFor(['steps' => '']));
		$this->assertSame([], $check->errorsFor([]));
	}//end testValidOrAbsentStepsHaveNoErrors()
}//end class
