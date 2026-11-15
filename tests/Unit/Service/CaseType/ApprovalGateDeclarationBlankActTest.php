<?php

/**
 * A blank act is never gated, and never waits on a decision.
 *
 * 🔴 A gate that matched the blank act would refuse a caller that forgot to
 * name what it is doing, and a reference read for it would hand back whatever
 * row happened to name no act. Both questions answer "nothing" before a row is
 * read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApprovalGateDeclaration on a blank act.
 *
 * @covers \OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration
 */
class ApprovalGateDeclarationBlankActTest extends TestCase {

	/**
	 * A blank act has no gate, even beside a gate on a real act.
	 *
	 * @return void
	 */
	public function testABlankActHasNoGate(): void {
		$caseType = [ApprovalGateDeclaration::DECLARATION => [['act' => 'close', 'decisionType' => 'dt-1']]];

		$this->assertSame([], ApprovalGateDeclaration::gateFor(caseType: $caseType, act: '   '));
		$this->assertSame(
			['act' => 'close', 'decisionType' => 'dt-1', 'label' => 'dt-1'],
			ApprovalGateDeclaration::gateFor(caseType: $caseType, act: ' close ')
		);
	}//end testABlankActHasNoGate()

	/**
	 * A blank act waits on no decision, even when a stored row names no act.
	 *
	 * @return void
	 */
	public function testABlankActHasNoReference(): void {
		$case = [ApprovalGateDeclaration::REFERENCES => [
			['act' => '', 'decisionRef' => 'dec-stray'],
			['act' => 'close', 'decisionRef' => ' dec-1 '],
		]];

		$this->assertSame('', ApprovalGateDeclaration::referenceFor(case: $case, act: ''));
		$this->assertSame('dec-1', ApprovalGateDeclaration::referenceFor(case: $case, act: 'close'));
	}//end testABlankActHasNoReference()
}//end class
