<?php

/**
 * Every closing status is withheld, not the one the case type calls closed.
 *
 * This exercises the seam one level up from `TransitionPreconditions`: the
 * engine asks `CaseResultWriter` whether the DESTINATION closes the case, at
 * the moment it asks, and hands that answer down. The failure it guards is the
 * one that looks like the feature working: a case type with three ways out
 * where the declaration named one, so two doors stay open and the case leaves
 * by whichever the handler happened to pick.
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
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\FourEyesRule;
use OCA\Dossiq\Service\Transitions\TransitionDeclarations;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\TransitionDeclarations
 * @uses \OCA\Dossiq\Service\Status\DerivedStatusEvaluator
 * @uses \OCA\Dossiq\Service\Transitions\TransitionPreconditions
 * @uses   \OCA\Dossiq\Service\Obligations\ObligationDeclaration
 * @uses   \OCA\Dossiq\Service\Status\StatusDeclaration
 * @uses   \OCA\Dossiq\Service\Transitions\FourEyesRule
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */
class ClosingWithheldTest extends TestCase {

	/**
	 * The case type's three ways out. All of them close the case.
	 *
	 * @var array<int, string>
	 */
	private const CLOSING_STATUSES = ['afgehandeld', 'ingetrokken', 'niet-ontvankelijk'];

	/**
	 * Declarations over a result writer that knows which statuses are final.
	 *
	 * @return TransitionDeclarations
	 */
	private function declarations(): TransitionDeclarations {
		$obligations = $this->createMock(originalClassName: ObligationService::class);
		$obligations->method('blocking')->willReturnCallback(
			static function (string $caseId, string $statusId, bool $isClosing): array {
				// The obligation blocks `closing`, so what it withholds is
				// decided by the flag the caller resolved, not by this double.
				if ($isClosing === false) {
					return [];
				}

				return [['kind' => 'advice', 'title' => 'the advice request', 'state' => 'open', 'blocks' => ['closing']]];
			},
		);

		$resultWriter = $this->createMock(originalClassName: CaseResultWriter::class);
		$resultWriter->method('isFinalStatus')->willReturnCallback(
			static fn (string $statusTypeId): bool => in_array($statusTypeId, self::CLOSING_STATUSES, true),
		);

		$declaration = new StatusDeclaration();

		return new TransitionDeclarations(
			preconditions: new TransitionPreconditions(
				obligations: $obligations,
				declaration: new ObligationDeclaration(),
				evaluator: new DerivedStatusEvaluator(declaration: $declaration),
			),
			fourEyes: new FourEyesRule(),
			resultWriter: $resultWriter,
			store: $this->createMock(originalClassName: CaseStatusStore::class),
		);
	}//end declarations()

	/**
	 * Every closing status is withheld, not only the one somebody named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testAnOpenObligationWithholdsAllThreeWaysOut(): void {
		$declarations = $this->declarations();

		foreach (self::CLOSING_STATUSES as $closing) {
			self::assertSame(
				expected: ['the advice request'],
				actual: $declarations->withheldReasons(
					transition: [
						'toStatus' => $closing,
						'requiresSettled' => [['kind' => 'obligationOpen', 'obligationKind' => 'advice']],
					],
					case: ['id' => 'case-1'],
				),
				message: $closing . ' closes the case and must be withheld',
			);
		}
	}//end testAnOpenObligationWithholdsAllThreeWaysOut()

	/**
	 * A move that does not close the case is not withheld by a closing obligation.
	 *
	 * The other half of the rule. Without it, "withhold everything" would pass
	 * the test above and stop a case moving through its own workflow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testAMoveThatDoesNotCloseTheCaseIsOffered(): void {
		self::assertSame(
			expected: [],
			actual: $this->declarations()->withheldReasons(
				transition: [
					'toStatus' => 'in-behandeling',
					'requiresSettled' => [['kind' => 'obligationOpen', 'obligationKind' => 'advice']],
				],
				case: ['id' => 'case-1'],
			),
		);
	}//end testAMoveThatDoesNotCloseTheCaseIsOffered()

	/**
	 * The explanation is read from `explanation`, and `description` is its
	 * older spelling rather than a second field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheExplanationPrefersItsOwnPropertyAndFallsBack(): void {
		$declarations = $this->declarations();

		self::assertSame(
			expected: 'Check the drawings before you send this out.',
			actual: $declarations->explanationOf(
				transition: ['explanation' => 'Check the drawings before you send this out.'],
			),
		);
		self::assertSame(
			expected: 'An older template wrote this.',
			actual: $declarations->explanationOf(transition: ['description' => 'An older template wrote this.']),
		);
		// An empty explanation renders nothing rather than an empty space.
		self::assertSame(expected: '', actual: $declarations->explanationOf(transition: []));
		self::assertSame(expected: '', actual: $declarations->explanationOf(transition: ['explanation' => '   ']));
	}//end testTheExplanationPrefersItsOwnPropertyAndFallsBack()

	/**
	 * The refusal names the act, its date and who may be asked instead.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheRefusalNamesTheActItsDateAndTheColleague(): void {
		$sentence = $this->declarations()->refusalSentence(
			refusal: [
				'act' => 'Draft the decision',
				'actor' => 'saskia',
				'at' => '2026-03-03T10:00:00+01:00',
				'askInstead' => 'the team lead',
			],
		);

		self::assertStringContainsString(needle: 'Draft the decision', haystack: $sentence);
		self::assertStringContainsString(needle: '2026-03-03', haystack: $sentence);
		self::assertStringContainsString(needle: 'the team lead', haystack: $sentence);
	}//end testTheRefusalNamesTheActItsDateAndTheColleague()

	/**
	 * A case type that names nobody to ask still says what happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testARefusalWithNobodyToAskStillNamesTheAct(): void {
		$sentence = $this->declarations()->refusalSentence(
			refusal: ['act' => 'Draft the decision', 'actor' => 'saskia', 'at' => '', 'askInstead' => ''],
		);

		self::assertStringContainsString(needle: 'Draft the decision', haystack: $sentence);
		self::assertStringNotContainsString(needle: 'Ask .', haystack: $sentence);
	}//end testARefusalWithNobodyToAskStillNamesTheAct()
}//end class
