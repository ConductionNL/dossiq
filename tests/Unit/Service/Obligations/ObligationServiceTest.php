<?php

/**
 * An obligation is one declared mechanism.
 *
 * The assertions that earn their place here are the two that decide whether a
 * case is released. What BLOCKS, which has to cover a closing status the
 * declaration never named, and what SETTLES, which must never record a
 * withdrawal as a settlement: an inspection nobody carried out is not an
 * inspection that passed, and six months later that difference is the whole
 * record.
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
 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Obligations;

use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Obligations\ObligationDeclaration
 *
 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
 */
class ObligationServiceTest extends TestCase {

	/**
	 * The reader under test.
	 *
	 * @var ObligationDeclaration
	 */
	private ObligationDeclaration $declaration;

	/**
	 * Build the reader. It touches nothing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->declaration = new ObligationDeclaration();
	}//end setUp()

	/**
	 * An obligation has three parts, and a case type declares them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function testACaseTypeDeclaresWhatItsObligationsAre(): void {
		$kinds = $this->declaration->kindsFor(
			caseType: ['obligationKinds' => [
				[
					'kind' => 'inspection',
					'title' => 'the fire safety inspection',
					'placedOnRole' => 'inspector',
					'settledBy' => 'recording the inspection',
					'blocks' => ['closing'],
					'term' => 10,
				],
			]],
		);

		self::assertArrayHasKey(key: 'inspection', array: $kinds);
		self::assertSame(expected: 'the fire safety inspection', actual: $kinds['inspection']['title']);
		self::assertSame(expected: ['closing'], actual: $kinds['inspection']['blocks']);
		self::assertSame(expected: 10, actual: $kinds['inspection']['term']);
	}//end testACaseTypeDeclaresWhatItsObligationsAre()

	/**
	 * A kind that names nothing to block blocks the closing statuses.
	 *
	 * That is what an obligation is for in almost every case, and an empty
	 * declaration that blocked NOTHING would be a row somebody wrote that
	 * silently does not hold the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function testAKindThatNamesNothingBlocksClosing(): void {
		$kinds = $this->declaration->kindsFor(
			caseType: ['obligationKinds' => [['kind' => 'fee']]],
		);

		self::assertSame(
			expected: [ObligationDeclaration::BLOCKS_CLOSING],
			actual: $kinds['fee']['blocks'],
		);
	}//end testAKindThatNamesNothingBlocksClosing()

	/**
	 * The closing token covers a closing status the declaration never named.
	 *
	 * A case type with Afgehandeld, Ingetrokken and Niet ontvankelijk has
	 * three ways out. A declaration listing ids would have to be kept complete
	 * by hand, and the door somebody forgot is the one the case leaves by.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheClosingTokenCoversEveryClosingStatus(): void {
		$obligation = ['state' => 'open', 'blocks' => ['closing']];

		foreach (['afgehandeld', 'ingetrokken', 'niet-ontvankelijk'] as $closing) {
			self::assertTrue(
				condition: $this->declaration->blocksStatus(
					obligation: $obligation,
					statusId: $closing,
					isClosing: true,
				),
				message: $closing . ' closes the case and must be withheld',
			);
		}

		// A status that does not close the case is not withheld by it.
		self::assertFalse(
			condition: $this->declaration->blocksStatus(
				obligation: $obligation,
				statusId: 'in-behandeling',
				isClosing: false,
			),
		);
	}//end testTheClosingTokenCoversEveryClosingStatus()

	/**
	 * A declaration naming one status blocks that status and no other.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testANamedStatusIsBlockedAndItsNeighboursAreNot(): void {
		$obligation = ['state' => 'open', 'blocks' => ['decision']];

		self::assertTrue(
			condition: $this->declaration->blocksStatus(
				obligation: $obligation,
				statusId: 'decision',
				isClosing: false,
			),
		);
		// Naming a status is NOT also naming the closing ones: a declaration
		// that blocked closing for free would withhold moves nobody declared.
		self::assertFalse(
			condition: $this->declaration->blocksStatus(
				obligation: $obligation,
				statusId: 'afgehandeld',
				isClosing: true,
			),
		);
	}//end testANamedStatusIsBlockedAndItsNeighboursAreNot()

	/**
	 * Met and withdrawn both release, and nothing else does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function testMetAndWithdrawnReleaseAndOpenDoesNot(): void {
		self::assertTrue(condition: $this->declaration->isBlocking(obligation: ['state' => 'open']));
		self::assertFalse(condition: $this->declaration->isBlocking(obligation: ['state' => 'met']));
		self::assertFalse(condition: $this->declaration->isBlocking(obligation: ['state' => 'withdrawn']));
	}//end testMetAndWithdrawnReleaseAndOpenDoesNot()

	/**
	 * A state nobody recognises BLOCKS.
	 *
	 * The direction matters: releasing a case on the strength of a value this
	 * app does not understand is the failure that has no symptom. A row nobody
	 * can say is settled is a row nobody can say is settled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function testAnUnknownStateStillBlocks(): void {
		self::assertTrue(condition: $this->declaration->isBlocking(obligation: ['state' => 'escalated']));
		self::assertTrue(condition: $this->declaration->isBlocking(obligation: []));
	}//end testAnUnknownStateStillBlocks()

	/**
	 * A term is a positive whole number of working days, or nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function testATermIsPositiveOrAbsent(): void {
		$kinds = $this->declaration->kindsFor(
			caseType: ['obligationKinds' => [
				['kind' => 'a', 'term' => 10],
				['kind' => 'b', 'term' => 0],
				['kind' => 'c'],
			]],
		);

		self::assertSame(expected: 10, actual: $kinds['a']['term']);
		self::assertNull(actual: $kinds['b']['term']);
		self::assertNull(actual: $kinds['c']['term']);
	}//end testATermIsPositiveOrAbsent()

	/**
	 * A row with no kind is dropped rather than keyed on the empty string.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function testARowWithNoKindIsDropped(): void {
		$kinds = $this->declaration->kindsFor(
			caseType: ['obligationKinds' => [['title' => 'nameless'], ['kind' => 'fee']]],
		);

		self::assertSame(expected: ['fee'], actual: array_keys($kinds));
	}//end testARowWithNoKindIsDropped()
}//end class
