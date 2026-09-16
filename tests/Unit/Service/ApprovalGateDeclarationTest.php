<?php

/**
 * What a case type may say about the acts that wait for an approval.
 *
 * 🔴 WHAT IS WORTH PINNING IS NOT THAT A DECLARATION READS BACK. It is the
 * three readings that would each fail open. A gate naming no act would apply to
 * everything or to nothing; a disabled gate left active would refuse an act
 * somebody deliberately released; and an act with no reference recorded would
 * read as "not gated" rather than "the approval was never raised", which is the
 * exact shape of a case proceeding because nobody asked.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration;
use PHPUnit\Framework\TestCase;

class ApprovalGateDeclarationTest extends TestCase {

	/**
	 * A case type gating two acts, one of them released.
	 *
	 * @return array<string, mixed> The case type row.
	 */
	private function caseType(): array {
		return [
			'title' => 'Omgevingsvergunning',
			ApprovalGateDeclaration::DECLARATION => [
				[
					'act' => 'send-besluit',
					'decisionType' => 'besluit-approval',
					'label' => 'Approval by the teamleider',
				],
				[
					'act' => 'publish',
					'decisionType' => 'publication-approval',
					'label' => 'Approval by the communications team',
					'enabled' => false,
				],
				['decisionType' => 'nameless'],
			],
		];
	}//end caseType()

	/**
	 * The gates read back in order, with the nameless and the released dropped.
	 *
	 * @return void
	 */
	public function testDeclaredGatesDropTheNamelessAndTheReleased(): void {
		$gates = ApprovalGateDeclaration::declaredOn(caseType: $this->caseType());

		self::assertCount(1, $gates);
		self::assertSame('send-besluit', $gates[0]['act']);
		self::assertSame('besluit-approval', $gates[0]['decisionType']);
		self::assertSame('Approval by the teamleider', $gates[0]['label']);
	}//end testDeclaredGatesDropTheNamelessAndTheReleased()

	/**
	 * A gate with no label of its own is named by its decision type.
	 *
	 * The refusal has to name the approval, so an empty label cannot become an
	 * empty name in the sentence a handler reads.
	 *
	 * @return void
	 */
	public function testAGateWithNoLabelIsNamedByItsDecisionType(): void {
		$gates = ApprovalGateDeclaration::declaredOn(
			caseType: [ApprovalGateDeclaration::DECLARATION => [
				['act' => 'send-besluit', 'decisionType' => 'besluit-approval'],
			]]
		);

		self::assertSame('besluit-approval', $gates[0]['label']);
	}//end testAGateWithNoLabelIsNamedByItsDecisionType()

	/**
	 * The gate on one act, and nothing for an act that carries none.
	 *
	 * @return void
	 */
	public function testGateForAnswersOnlyTheActItGates(): void {
		$caseType = $this->caseType();

		self::assertSame(
			'besluit-approval',
			ApprovalGateDeclaration::gateFor(caseType: $caseType, act: 'send-besluit')['decisionType']
		);
		self::assertSame([], ApprovalGateDeclaration::gateFor(caseType: $caseType, act: 'assign'));
		// The released gate answers nothing, not a gate with `enabled` false:
		// a caller that had to re-check the flag would be the second place the
		// release could be forgotten.
		self::assertSame([], ApprovalGateDeclaration::gateFor(caseType: $caseType, act: 'publish'));
	}//end testGateForAnswersOnlyTheActItGates()

	/**
	 * A declaration stored as a JSON string reads the same as an array.
	 *
	 * An export round trip encodes it. Reading only the array shape would make
	 * an imported case type behave as though it gated nothing.
	 *
	 * @return void
	 */
	public function testAJsonEncodedDeclarationReadsTheSame(): void {
		$encoded = json_encode([['act' => 'send-besluit', 'decisionType' => 'besluit-approval']]);

		$gates = ApprovalGateDeclaration::declaredOn(
			caseType: [ApprovalGateDeclaration::DECLARATION => $encoded]
		);

		self::assertSame('send-besluit', $gates[0]['act']);
	}//end testAJsonEncodedDeclarationReadsTheSame()

	/**
	 * The reference a case carries for a gated act, and the empty answer.
	 *
	 * @return void
	 */
	public function testTheReferenceIsReadPerAct(): void {
		$case = [
			ApprovalGateDeclaration::REFERENCES => [
				['act' => 'send-besluit', 'decisionRef' => '7f3c-approval'],
			],
		];

		self::assertSame(
			'7f3c-approval',
			ApprovalGateDeclaration::referenceFor(case: $case, act: 'send-besluit')
		);
		// Empty, and the caller reads that as "never raised". It is not the
		// same answer as "this act is not gated", which comes from the case
		// type and not from here.
		self::assertSame('', ApprovalGateDeclaration::referenceFor(case: $case, act: 'publish'));
	}//end testTheReferenceIsReadPerAct()

	/**
	 * Recording a reference replaces the act's earlier one and keeps the rest.
	 *
	 * @return void
	 */
	public function testRecordingAReferenceReplacesOnlyThatAct(): void {
		$case = [
			ApprovalGateDeclaration::REFERENCES => [
				['act' => 'send-besluit', 'decisionRef' => 'old', 'raisedAt' => '2026-09-01T10:00:00+00:00'],
				['act' => 'publish', 'decisionRef' => 'keep-me', 'raisedAt' => '2026-09-02T10:00:00+00:00'],
			],
		];

		$refs = ApprovalGateDeclaration::withReference(
			case: $case,
			act: 'send-besluit',
			decisionRef: 'new',
			raisedAt: '2026-09-16T09:00:00+00:00',
		);

		$byAct = array_column($refs, 'decisionRef', 'act');
		self::assertSame('new', $byAct['send-besluit']);
		self::assertSame('keep-me', $byAct['publish']);
		self::assertCount(2, $refs);
	}//end testRecordingAReferenceReplacesOnlyThatAct()
}//end class
