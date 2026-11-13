<?php

/**
 * Unit tests for StatusMove: a setStatus action names its target by status
 * name or role, is refused without either or without a case type, and says
 * which lookups it tried.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\StatusMove;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\StatusMove
 */
class StatusMoveTest extends TestCase {

	public function testItTrimsTheTargetAndReadsTheCaseType(): void {
		$case = ['id' => 'c-1', 'caseType' => 'ct-9'];
		$move = new StatusMove(['status' => '  In behandeling ', 'role' => ' intake '], $case);

		$this->assertSame('In behandeling', $move->name);
		$this->assertSame('intake', $move->role);
		$this->assertSame('ct-9', $move->caseTypeId);
		$this->assertSame($case, $move->case);
		$this->assertNull($move->refusal());
	}//end testItTrimsTheTargetAndReadsTheCaseType()

	public function testOnlyAnExplicitFalseMakesTheMoveOptional(): void {
		$case = ['caseType' => 'ct-9'];

		$this->assertFalse((new StatusMove(['status' => 'Open'], $case))->optional);
		$this->assertFalse((new StatusMove(['status' => 'Open', 'required' => true], $case))->optional);
		$this->assertFalse((new StatusMove(['status' => 'Open', 'required' => 0], $case))->optional);
		$this->assertTrue((new StatusMove(['status' => 'Open', 'required' => false], $case))->optional);
	}//end testOnlyAnExplicitFalseMakesTheMoveOptional()

	public function testWithoutNameOrRoleItIsRefusedFirst(): void {
		$this->assertSame('set_status_missing_status', (new StatusMove(['status' => '  ', 'role' => ''], []))->refusal());
	}//end testWithoutNameOrRoleItIsRefusedFirst()

	public function testWithATargetButNoCaseTypeItIsRefused(): void {
		$this->assertSame('case_has_no_case_type', (new StatusMove(['role' => 'intake'], ['caseType' => '']))->refusal());
		$this->assertSame('case_has_no_case_type', (new StatusMove(['status' => 'Open'], []))->refusal());
	}//end testWithATargetButNoCaseTypeItIsRefused()

	public function testTriedListsRoleBeforeName(): void {
		$case = ['caseType' => 'ct-9'];

		$this->assertSame('role "intake" or name "Open"', (new StatusMove(['status' => 'Open', 'role' => 'intake'], $case))->tried());
		$this->assertSame('name "Open"', (new StatusMove(['status' => 'Open'], $case))->tried());
		$this->assertSame('role "intake"', (new StatusMove(['role' => 'intake'], $case))->tried());
		$this->assertSame('', (new StatusMove([], $case))->tried());
	}//end testTriedListsRoleBeforeName()
}//end class
