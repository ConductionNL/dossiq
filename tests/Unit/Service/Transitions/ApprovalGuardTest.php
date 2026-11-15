<?php

/**
 * Unit tests for ApprovalGuard: the guard passes when the approval gate allows
 * the act, and otherwise fails with the gate's sentence and its rule, status,
 * approval label and approvers as details.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Cases\ApprovalGate;
use OCA\Dossiq\Service\Transitions\ApprovalGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\ApprovalGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class ApprovalGuardTest extends TestCase {

	private ApprovalGate&MockObject $gate;

	protected function setUp(): void {
		parent::setUp();
		$this->gate = $this->createMock(ApprovalGate::class);
	}//end setUp()

	public function testAnAllowedActPasses(): void {
		$case = ['id' => 'c-1'];
		$this->gate->expects($this->once())->method('verdictFor')
			->with($case, 'decide', 'alice')
			->willReturn(['allowed' => true]);

		$result = (new ApprovalGuard($this->gate))->evaluate(['act' => 'decide'], $case, 'alice');

		$this->assertTrue($result->passed);
		$this->assertNull($result->failureMessage);
		$this->assertSame([], $result->details);
	}//end testAnAllowedActPasses()

	public function testARefusedActFailsWithTheGatesSentenceAndDetails(): void {
		$this->gate->method('verdictFor')->willReturn(
			[
				'allowed' => false,
				'sentence' => 'The decision waits for approval by the team lead.',
				'rule' => 'approval_pending',
				'status' => '423',
				'label' => 'Team lead approval',
				'approvers' => ['bob', 'carol'],
			]
		);

		$result = (new ApprovalGuard($this->gate))->evaluate(['act' => 'decide'], ['id' => 'c-1'], 'alice');

		$this->assertFalse($result->passed);
		$this->assertSame('The decision waits for approval by the team lead.', $result->failureMessage);
		$this->assertSame(
			['rule' => 'approval_pending', 'status' => 423, 'approval' => 'Team lead approval', 'approvers' => ['bob', 'carol']],
			$result->details
		);
	}//end testARefusedActFailsWithTheGatesSentenceAndDetails()

	public function testAnythingButTrueIsARefusalAndAMissingActIsAskedAsEmpty(): void {
		$this->gate->expects($this->once())->method('verdictFor')
			->with(['id' => 'c-1'], '', 'alice')
			->willReturn(['allowed' => 1, 'sentence' => 'No.', 'rule' => 'r', 'status' => 403, 'label' => 'l', 'approvers' => 'bob']);

		$result = (new ApprovalGuard($this->gate))->evaluate([], ['id' => 'c-1'], 'alice');

		$this->assertFalse($result->passed);
		$this->assertSame(['bob'], $result->details['approvers']);
	}//end testAnythingButTrueIsARefusalAndAMissingActIsAskedAsEmpty()
}//end class
