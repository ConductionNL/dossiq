<?php

/**
 * A transition declares what must be settled first.
 *
 * The assertion that earns its place is the SECOND KIND of dependency needing
 * no new service. That is the whole row: `ConsultationService` already knew an
 * open advice request blocks a case, and it was the only thing that knew
 * anything of the kind. If a fee costs a class, nothing has been generalised.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Obligations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Obligations;

use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Status\StatusDeclaration;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\TransitionPreconditions
 * @uses \OCA\Dossiq\Service\Status\DerivedStatusEvaluator
 * @uses \OCA\Dossiq\Service\Status\StatusDeclaration
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */
class TransitionPreconditionTest extends TestCase {

	/**
	 * Preconditions over an obligation service that answers the given rows.
	 *
	 * @param array<int, array<string, mixed>> $blocking What is open on the case.
	 *
	 * @return TransitionPreconditions
	 */
	private function preconditions(array $blocking = []): TransitionPreconditions {
		$obligations = $this->createMock(originalClassName: ObligationService::class);
		$obligations->method('blocking')->willReturn($blocking);

		$declaration = new StatusDeclaration();

		return new TransitionPreconditions(
			obligations: $obligations,
			declaration: new ObligationDeclaration(),
			evaluator: new DerivedStatusEvaluator(declaration: $declaration),
		);
	}//end preconditions()

	/**
	 * A case with an open advice request cannot be closed, and the list says why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testAnOpenAdviceRequestWithholdsTheClosingTransition(): void {
		$reasons = $this->preconditions(
			blocking: [['kind' => 'advice', 'title' => 'the advice request', 'state' => 'open']],
		)->withheldReasons(
			transition: [
				'toStatus' => 'afgehandeld',
				'requiresSettled' => [['kind' => 'obligationOpen', 'obligationKind' => 'advice']],
			],
			case: ['id' => 'case-1'],
			toIsClosing: true,
		);

		self::assertSame(expected: ['the advice request'], actual: $reasons);
	}//end testAnOpenAdviceRequestWithholdsTheClosingTransition()

	/**
	 * Settling the dependency restores the transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testSettlingTheDependencyRestoresTheTransition(): void {
		$reasons = $this->preconditions(blocking: [])->withheldReasons(
			transition: [
				'toStatus' => 'afgehandeld',
				'requiresSettled' => [['kind' => 'obligationOpen', 'obligationKind' => 'advice']],
			],
			case: ['id' => 'case-1'],
			toIsClosing: true,
		);

		self::assertSame(expected: [], actual: $reasons);
	}//end testSettlingTheDependencyRestoresTheTransition()

	/**
	 * A second kind of dependency needs no new service.
	 *
	 * The unpaid fee below goes through the SAME mechanism the advice request
	 * does, and the only thing that differs is the declaration. If this needed
	 * a class, the row this change closes would still be open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testASecondKindOfDependencyNeedsNoNewService(): void {
		$preconditions = $this->preconditions(
			blocking: [['kind' => 'fee', 'title' => 'the outstanding fee', 'state' => 'open']],
		);

		$reasons = $preconditions->withheldReasons(
			transition: [
				'toStatus' => 'afgehandeld',
				'requiresSettled' => [['kind' => 'obligationOpen', 'obligationKind' => 'fee']],
			],
			case: ['id' => 'case-1'],
			toIsClosing: true,
		);

		self::assertSame(expected: ['the outstanding fee'], actual: $reasons);
	}//end testASecondKindOfDependencyNeedsNoNewService()

	/**
	 * A dependency naming a kind is not satisfied by a DIFFERENT open kind.
	 *
	 * The failure this catches is the one that looks like the feature working:
	 * every open obligation withholding every declared transition, so the
	 * declaration reads as though it is being honoured when it is being
	 * ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testAnotherKindsObligationDoesNotWithholdThisTransition(): void {
		$reasons = $this->preconditions(
			blocking: [['kind' => 'inspection', 'title' => 'the inspection', 'state' => 'open']],
		)->withheldReasons(
			transition: [
				'toStatus' => 'afgehandeld',
				'requiresSettled' => [['kind' => 'obligationOpen', 'obligationKind' => 'advice']],
			],
			case: ['id' => 'case-1'],
			toIsClosing: true,
		);

		self::assertSame(expected: [], actual: $reasons);
	}//end testAnotherKindsObligationDoesNotWithholdThisTransition()

	/**
	 * The field and document kinds are the SAME evaluation a derived status runs.
	 *
	 * One evaluator rather than two is what stops "the file is complete"
	 * meaning two different things on the same case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testAFieldDependencyReadsTheCaseTheWayADerivationDoes(): void {
		$transition = [
			'toStatus' => 'afgehandeld',
			'requiresSettled' => [
				['kind' => 'fieldPresent', 'field' => 'besluitDocument', 'label' => 'the decision document'],
			],
		];

		self::assertSame(
			expected: ['the decision document'],
			actual: $this->preconditions()->withheldReasons(
				transition: $transition,
				case: ['id' => 'case-1'],
				toIsClosing: true,
			),
		);
		self::assertSame(
			expected: [],
			actual: $this->preconditions()->withheldReasons(
				transition: $transition,
				case: ['id' => 'case-1', 'besluitDocument' => 'doc-1'],
				toIsClosing: true,
			),
		);
	}//end testAFieldDependencyReadsTheCaseTheWayADerivationDoes()

	/**
	 * A transition that declares nothing is never withheld.
	 *
	 * Which is every transition shipped before this change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testATransitionDeclaringNothingIsNeverWithheld(): void {
		$preconditions = $this->preconditions(
			blocking: [['kind' => 'advice', 'title' => 'the advice request', 'state' => 'open']],
		);

		self::assertSame(
			expected: [],
			actual: $preconditions->withheldReasons(
				transition: ['toStatus' => 'afgehandeld'],
				case: ['id' => 'case-1'],
				toIsClosing: true,
			),
		);
	}//end testATransitionDeclaringNothingIsNeverWithheld()

	/**
	 * A dependency kind this install does not know is dropped, not failed.
	 *
	 * A declaration written for a later vocabulary would otherwise withhold
	 * the transition for ever with a reason nobody can act on: the move gone
	 * and the sentence useless.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testAnUnknownDependencyKindIsDropped(): void {
		$declared = $this->preconditions()->declaredFor(
			transition: ['requiresSettled' => [
				['kind' => 'moonPhase'],
				['kind' => 'fieldPresent', 'field' => 'title'],
			]],
		);

		self::assertCount(expectedCount: 1, haystack: $declared);
		self::assertSame(expected: 'fieldPresent', actual: $declared[0]['kind']);
	}//end testAnUnknownDependencyKindIsDropped()
}//end class
