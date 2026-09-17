<?php

/**
 * The close guard on a verwijdering: what it withholds, and what it names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use PHPUnit\Framework\TestCase;

/**
 * `erasureComplete` reads OpenRegister's run report off the case.
 */
class ErasureCompletePreconditionTest extends TestCase {

	/**
	 * The subject under test.
	 *
	 * @var TransitionPreconditions
	 */
	private TransitionPreconditions $preconditions;

	/**
	 * Wire the preconditions with doubles of the collaborators this kind never
	 * reaches: `erasureComplete` answers off the case alone, and a test that
	 * needed an obligation service to prove it would be testing the wrong seam.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->preconditions = new TransitionPreconditions(
			obligations: $this->createMock(ObligationService::class),
			declaration: $this->createMock(ObligationDeclaration::class),
			evaluator: $this->createMock(DerivedStatusEvaluator::class),
		);
	}//end setUp()

	/**
	 * The closing transition, declaring the kind.
	 *
	 * @param string $label The author's own words, when they wrote any.
	 *
	 * @return array<string, mixed>
	 */
	private function closing(string $label = ''): array {
		$dependency = ['kind' => 'erasureComplete'];
		if ($label !== '') {
			$dependency['label'] = $label;
		}

		return ['toStatus' => 'afgerond', 'requiresSettled' => [$dependency]];
	}//end closing()

	/**
	 * The kind is in the vocabulary, so a declaration is not silently dropped.
	 *
	 * @return void
	 */
	public function testTheKindIsDeclarable(): void {
		self::assertContains('erasureComplete', TransitionPreconditions::DEPENDENCY_KINDS);
		self::assertCount(1, $this->preconditions->declaredFor(transition: $this->closing()));
	}//end testTheKindIsDeclarable()

	/**
	 * A case whose erasure never ran cannot close, which is the fail-closed
	 * half: the alternative reports an erasure that never touched an object.
	 *
	 * @return void
	 */
	public function testACaseWithNoRunCannotClose(): void {
		$reasons = $this->preconditions->withheldReasons(
			transition: $this->closing(),
			case: ['id' => 'c1'],
			toIsClosing: true,
		);

		self::assertSame(['the erasure, which has not run yet'], $reasons);
	}//end testACaseWithNoRunCannotClose()

	/**
	 * An incomplete run names the records it did not reach, because that is
	 * what the handler has to answer the data subject about.
	 *
	 * @return void
	 */
	public function testTheReasonNamesWhatWasWithheld(): void {
		$reasons = $this->preconditions->withheldReasons(
			transition: $this->closing(),
			case: [
				'id' => 'c1',
				'erasureOutcome' => [
					'complete' => false,
					'withheld' => [['name' => 'Subsidiedossier 2019']],
					'refused' => [['name' => 'Hoorzittingopname']],
					'failed' => [],
				],
			],
			toIsClosing: true,
		);

		self::assertSame(
			['the erasure, which did not reach Subsidiedossier 2019, Hoorzittingopname'],
			$reasons
		);
	}//end testTheReasonNamesWhatWasWithheld()

	/**
	 * A long list is capped and counted, so the reason stays readable.
	 *
	 * @return void
	 */
	public function testALongListIsCappedAndCounted(): void {
		$reasons = $this->preconditions->withheldReasons(
			transition: $this->closing(),
			case: [
				'id' => 'c1',
				'erasureOutcome' => [
					'complete' => false,
					'withheld' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c'], ['name' => 'd'], ['name' => 'e']],
				],
			],
			toIsClosing: true,
		);

		self::assertSame(['the erasure, which did not reach a, b, c and 2 more'], $reasons);
	}//end testALongListIsCappedAndCounted()

	/**
	 * A complete run settles the dependency, so the move is offered. The other
	 * side of the same guard: a test that only pinned the refusal would pass on
	 * a guard that refuses everything.
	 *
	 * @return void
	 */
	public function testACompleteRunLetsTheCaseClose(): void {
		$reasons = $this->preconditions->withheldReasons(
			transition: $this->closing(),
			case: ['id' => 'c1', 'erasureOutcome' => ['complete' => true, 'withheld' => []]],
			toIsClosing: true,
		);

		self::assertSame([], $reasons);
	}//end testACompleteRunLetsTheCaseClose()

	/**
	 * An incomplete run that names nothing still withholds, in the author's own
	 * words when they wrote any.
	 *
	 * @return void
	 */
	public function testAnUnnamedIncompleteRunFallsBackToTheLabel(): void {
		$reasons = $this->preconditions->withheldReasons(
			transition: $this->closing(label: 'the erasure of this file'),
			case: ['id' => 'c1', 'erasureOutcome' => ['complete' => false]],
			toIsClosing: true,
		);

		self::assertSame(['the erasure of this file'], $reasons);
	}//end testAnUnnamedIncompleteRunFallsBackToTheLabel()

	/**
	 * A bucket entry that is a bare uuid is named by it rather than dropped.
	 *
	 * @return void
	 */
	public function testABareIdentifierIsStillNamed(): void {
		$reasons = $this->preconditions->withheldReasons(
			transition: $this->closing(),
			case: ['id' => 'c1', 'erasureOutcome' => ['complete' => false, 'failed' => ['obj-7']]],
			toIsClosing: true,
		);

		self::assertSame(['the erasure, which did not reach obj-7'], $reasons);
	}//end testABareIdentifierIsStillNamed()
}//end class
