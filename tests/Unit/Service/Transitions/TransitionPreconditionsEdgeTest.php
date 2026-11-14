<?php

/**
 * TransitionPreconditions edge-case tests
 *
 * Verifies the less travelled roads through the precondition reader: junk
 * dependency entries, an obligation dependency on a case with no id, the
 * author's label and the obligation kind as reasons, run-report entries that
 * name nothing, and the blocking-state delegation.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\TransitionPreconditions
 *
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
 */
class TransitionPreconditionsEdgeTest extends TestCase {

	/**
	 * Preconditions over mocked collaborators.
	 *
	 * @param ObligationService|null     $obligations The obligation service, or a silent mock.
	 * @param ObligationDeclaration|null $declaration The declaration, or a silent mock.
	 *
	 * @return TransitionPreconditions
	 */
	private function preconditions(
		?ObligationService $obligations = null,
		?ObligationDeclaration $declaration = null,
	): TransitionPreconditions {
		return new TransitionPreconditions(
			obligations: ($obligations ?? $this->createMock(ObligationService::class)),
			declaration: ($declaration ?? $this->createMock(ObligationDeclaration::class)),
			evaluator: $this->createMock(DerivedStatusEvaluator::class),
		);
	}//end preconditions()

	/**
	 * An obligation service that answers the given rows.
	 *
	 * @param array<int, array<string, mixed>> $blocking The open obligations.
	 *
	 * @return ObligationService&MockObject
	 */
	private function obligations(array $blocking): ObligationService {
		$obligations = $this->createMock(ObligationService::class);
		$obligations->method('blocking')->willReturn($blocking);

		return $obligations;
	}//end obligations()

	/**
	 * Non-array entries and unknown kinds in `requiresSettled` are dropped.
	 *
	 * @return void
	 */
	public function testJunkDependencyEntriesAreDropped(): void {
		$declared = $this->preconditions()->declaredFor(transition: [
			'requiresSettled' => ['obligationOpen', 42, ['kind' => 'laterKind'], ['kind' => ' fieldPresent ', 'field' => 'besluit']],
		]);

		self::assertSame(
			[[
				'kind' => 'fieldPresent',
				'obligationKind' => '',
				'field' => 'besluit',
				'value' => '',
				'documentType' => '',
				'label' => '',
			]],
			$declared
		);
	}//end testJunkDependencyEntriesAreDropped()

	/**
	 * A case with no id withholds nothing on an obligation, and the service is not asked.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdIsNotAskedAboutObligations(): void {
		$obligations = $this->createMock(ObligationService::class);
		$obligations->expects($this->never())->method('blocking');

		$reasons = $this->preconditions(obligations: $obligations)->withheldReasons(
			transition: ['toStatus' => 's-9', 'requiresSettled' => [['kind' => 'obligationOpen']]],
			case: [],
			toIsClosing: true,
		);

		self::assertSame([], $reasons);
	}//end testACaseWithoutAnIdIsNotAskedAboutObligations()

	/**
	 * The author's own label is the reason, ahead of the obligation's title.
	 *
	 * @return void
	 */
	public function testTheAuthorsLabelIsTheReason(): void {
		$obligations = $this->createMock(ObligationService::class);
		$obligations->expects($this->once())
			->method('blocking')
			->with('case-1', 's-9', true)
			->willReturn([['kind' => 'fee', 'title' => 'the fee']]);

		$reasons = $this->preconditions(obligations: $obligations)->withheldReasons(
			transition: [
				'toStatus' => 's-9',
				'requiresSettled' => [['kind' => 'obligationOpen', 'label' => ' the leges are unpaid ']],
			],
			case: ['@self' => ['id' => 'case-1']],
			toIsClosing: true,
		);

		self::assertSame(['the leges are unpaid'], $reasons);
	}//end testTheAuthorsLabelIsTheReason()

	/**
	 * An obligation with no title is named by its kind; one of another kind is skipped.
	 *
	 * @return void
	 */
	public function testAnUntitledObligationIsNamedByItsKind(): void {
		$reasons = $this->preconditions(
			obligations: $this->obligations([
				['kind' => 'advice', 'title' => 'the advice'],
				['kind' => ' fee ', 'title' => ''],
			]),
		)->withheldReasons(
			transition: ['requiresSettled' => [['kind' => 'obligationOpen', 'obligationKind' => 'fee']]],
			case: ['id' => 'case-1'],
			toIsClosing: false,
		);

		self::assertSame(['fee'], $reasons);
	}//end testAnUntitledObligationIsNamedByItsKind()

	/**
	 * Run-report entries that name nothing are skipped; a report naming nothing
	 * falls back to the generic sentence.
	 *
	 * @return void
	 */
	public function testRunReportEntriesThatNameNothingAreSkipped(): void {
		$preconditions = $this->preconditions();
		$transition = ['requiresSettled' => [['kind' => 'erasureComplete']]];

		self::assertSame(
			['the erasure, which did not reach the 2019 file'],
			$preconditions->withheldReasons(
				transition: $transition,
				case: ['erasureOutcome' => [
					'complete' => false,
					'withheld' => [42, ['size' => 3], ['title' => 'the 2019 file']],
				]],
				toIsClosing: true,
			)
		);

		self::assertSame(
			['the erasure, which did not finish'],
			$preconditions->withheldReasons(
				transition: $transition,
				case: ['erasureOutcome' => ['complete' => false, 'failed' => [null, ['other' => 'x']]]],
				toIsClosing: true,
			)
		);
	}//end testRunReportEntriesThatNameNothingAreSkipped()

	/**
	 * Whether an obligation blocks is the declaration's answer, passed through.
	 *
	 * @return void
	 */
	public function testIsBlockingDelegatesToTheDeclaration(): void {
		$row = ['state' => 'met'];
		$declaration = $this->createMock(ObligationDeclaration::class);
		$declaration->expects($this->exactly(2))
			->method('isBlocking')
			->with($row)
			->willReturnOnConsecutiveCalls(false, true);

		$preconditions = $this->preconditions(declaration: $declaration);

		self::assertFalse($preconditions->isBlocking(obligation: $row));
		self::assertTrue($preconditions->isBlocking(obligation: $row));
	}//end testIsBlockingDelegatesToTheDeclaration()
}//end class
