<?php

/**
 * Unit tests for ApprovalGateDeclaration: which acts a case type gates, and the decisions a case waits on.
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
 * Unit tests for ApprovalGateDeclaration.
 *
 * @covers \OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration
 * @uses   \OCA\Dossiq\Service\Support\RowList
 */
class ApprovalGateDeclarationTest extends TestCase {

	/**
	 * Only enabled rows that name an act are gates; the label falls back to the decision type.
	 *
	 * @return void
	 */
	public function testDeclaredOnKeepsEnabledRowsWithAnAct(): void {
		$caseType = [
			ApprovalGateDeclaration::DECLARATION => [
				['act' => ' close ', 'decisionType' => ' dt-close ', 'label' => ' Sluiten '],
				['act' => 'reopen', 'decisionType' => 'dt-reopen'],
				['act' => '   ', 'decisionType' => 'dt-blank'],
				['act' => 'archive', 'decisionType' => 'dt-archive', 'enabled' => false],
				'not a row',
				['act' => 'extend', 'decisionType' => 'dt-extend', 'enabled' => true, 'label' => '  '],
			],
		];

		$this->assertSame(
			expected: [
				['act' => 'close', 'decisionType' => 'dt-close', 'label' => 'Sluiten'],
				['act' => 'reopen', 'decisionType' => 'dt-reopen', 'label' => 'dt-reopen'],
				['act' => 'extend', 'decisionType' => 'dt-extend', 'label' => 'dt-extend'],
			],
			actual: ApprovalGateDeclaration::declaredOn(caseType: $caseType)
		);
	}//end testDeclaredOnKeepsEnabledRowsWithAnAct()

	/**
	 * A declaration stored as a JSON string is read like a list.
	 *
	 * @return void
	 */
	public function testDeclaredOnReadsAJsonString(): void {
		$caseType = [ApprovalGateDeclaration::DECLARATION => '[{"act":"close","decisionType":"dt-1","label":"Close"}]'];

		$this->assertSame(
			expected: [['act' => 'close', 'decisionType' => 'dt-1', 'label' => 'Close']],
			actual: ApprovalGateDeclaration::declaredOn(caseType: $caseType)
		);
	}//end testDeclaredOnReadsAJsonString()

	/**
	 * A case type without a declaration, or with a non-list one, gates nothing.
	 *
	 * @return void
	 */
	public function testNoDeclarationGatesNothing(): void {
		$this->assertSame(expected: [], actual: ApprovalGateDeclaration::declaredOn(caseType: []));
		$this->assertSame(expected: [], actual: ApprovalGateDeclaration::declaredOn(caseType: [ApprovalGateDeclaration::DECLARATION => 42]));
		$this->assertSame(expected: [], actual: ApprovalGateDeclaration::gateFor(caseType: [], act: 'close'));
	}//end testNoDeclarationGatesNothing()

	/**
	 * An act the type does not gate has no gate, even when other acts are gated.
	 *
	 * @return void
	 */
	public function testAnUngatedActHasNoGate(): void {
		$caseType = [ApprovalGateDeclaration::DECLARATION => [['act' => 'close', 'decisionType' => 'dt-1']]];

		$this->assertSame(expected: [], actual: ApprovalGateDeclaration::gateFor(caseType: $caseType, act: 'reopen'));
	}//end testAnUngatedActHasNoGate()

	/**
	 * The reference for an act is the trimmed decision of the first row naming it.
	 *
	 * @return void
	 */
	public function testReferenceForReturnsTheDecisionOfThatAct(): void {
		$case = [
			ApprovalGateDeclaration::REFERENCES => [
				['act' => 'reopen', 'decisionRef' => 'dec-reopen'],
				['act' => ' close ', 'decisionRef' => ' dec-close '],
				['act' => 'close', 'decisionRef' => 'dec-later'],
			],
		];

		$this->assertSame(expected: 'dec-close', actual: ApprovalGateDeclaration::referenceFor(case: $case, act: 'close'));
		$this->assertSame(expected: '', actual: ApprovalGateDeclaration::referenceFor(case: $case, act: 'archive'));
		$this->assertSame(expected: '', actual: ApprovalGateDeclaration::referenceFor(case: [], act: 'close'));
	}//end testReferenceForReturnsTheDecisionOfThatAct()

	/**
	 * A row naming the act but no decision answers the empty reference.
	 *
	 * @return void
	 */
	public function testAReferenceRowWithoutADecisionAnswersEmpty(): void {
		$case = [ApprovalGateDeclaration::REFERENCES => [['act' => 'close']]];

		$this->assertSame(expected: '', actual: ApprovalGateDeclaration::referenceFor(case: $case, act: 'close'));
	}//end testAReferenceRowWithoutADecisionAnswersEmpty()

	/**
	 * A new reference replaces the act's old one and keeps the other acts', normalised.
	 *
	 * @return void
	 */
	public function testWithReferenceReplacesTheActsOldReference(): void {
		$case = [
			ApprovalGateDeclaration::REFERENCES => [
				['act' => ' reopen ', 'decisionRef' => ' dec-reopen ', 'raisedAt' => ' 2026-01-02 '],
				['act' => 'close', 'decisionRef' => 'dec-old', 'raisedAt' => '2026-01-01'],
				['act' => '', 'decisionRef' => 'dec-orphan'],
				['act' => 'extend'],
			],
		];

		$this->assertSame(
			expected: [
				['act' => 'reopen', 'decisionRef' => 'dec-reopen', 'raisedAt' => '2026-01-02'],
				['act' => 'extend', 'decisionRef' => '', 'raisedAt' => ''],
				['act' => 'close', 'decisionRef' => 'dec-new', 'raisedAt' => '2026-03-04'],
			],
			actual: ApprovalGateDeclaration::withReference(case: $case, act: ' close ', decisionRef: ' dec-new ', raisedAt: ' 2026-03-04 ')
		);
	}//end testWithReferenceReplacesTheActsOldReference()

	/**
	 * A blank act adds no reference; the other acts' references are kept.
	 *
	 * @return void
	 */
	public function testWithReferenceForABlankActAddsNothing(): void {
		$case = [ApprovalGateDeclaration::REFERENCES => '[{"act":"close","decisionRef":"dec-1","raisedAt":"2026-01-01"}]'];

		$this->assertSame(
			expected: [['act' => 'close', 'decisionRef' => 'dec-1', 'raisedAt' => '2026-01-01']],
			actual: ApprovalGateDeclaration::withReference(case: $case, act: '  ', decisionRef: 'dec-2', raisedAt: '2026-02-02')
		);
	}//end testWithReferenceForABlankActAddsNothing()
}//end class
