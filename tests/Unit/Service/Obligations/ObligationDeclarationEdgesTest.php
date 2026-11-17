<?php

/**
 * Tests for how loosely-typed obligation declarations are read.
 *
 * @category Test
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
 * @spec openspec/specs/consultation-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Obligations;

use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * A declaration in an unexpected shape reads as the safe default, never as an error.
 *
 * @covers \OCA\Dossiq\Service\Obligations\ObligationDeclaration
 *
 * @spec openspec/specs/consultation-management/spec.md
 */
class ObligationDeclarationEdgesTest extends TestCase {

	/**
	 * The declaration under test.
	 *
	 * @var ObligationDeclaration
	 */
	private ObligationDeclaration $declaration;

	/**
	 * Build the declaration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->declaration = new ObligationDeclaration();
	}//end setUp()

	/**
	 * A case type with no list of kinds declares none, and non-array rows are skipped.
	 *
	 * @return void
	 */
	public function testAMissingOrMalformedListDeclaresNothing(): void {
		self::assertSame([], $this->declaration->kindsFor(caseType: []));
		self::assertSame([], $this->declaration->kindsFor(caseType: ['obligationKinds' => 'advice']));
		self::assertSame(
			['fee'],
			array_keys($this->declaration->kindsFor(caseType: ['obligationKinds' => ['advice', null, ['kind' => 'fee']]]))
		);
	}//end testAMissingOrMalformedListDeclaresNothing()

	/**
	 * A minimal row is completed with its defaults, and its kind is trimmed.
	 *
	 * @return void
	 */
	public function testAMinimalRowGetsItsDefaults(): void {
		$kinds = $this->declaration->kindsFor(caseType: ['obligationKinds' => [['kind' => '  fee  ']]]);

		self::assertSame(
			['fee' => [
				'kind' => 'fee',
				'title' => 'fee',
				'placedOnRole' => '',
				'settledBy' => '',
				'blocks' => ['closing'],
				'term' => null,
			]],
			$kinds
		);
	}//end testAMinimalRowGetsItsDefaults()

	/**
	 * A later row with the same kind replaces the earlier one.
	 *
	 * @return void
	 */
	public function testALaterRowOfTheSameKindWins(): void {
		$kinds = $this->declaration->kindsFor(
			caseType: ['obligationKinds' => [['kind' => 'fee', 'title' => 'first'], ['kind' => 'fee', 'title' => 'second']]]
		);

		self::assertCount(1, $kinds);
		self::assertSame('second', $kinds['fee']['title']);
	}//end testALaterRowOfTheSameKindWins()

	/**
	 * Blocks may be one string or a list; blanks are dropped, and an empty or unusable list blocks closing.
	 *
	 * @return void
	 */
	public function testBlocksAreNormalised(): void {
		$kinds = $this->declaration->kindsFor(
			caseType: ['obligationKinds' => [
				['kind' => 'a', 'blocks' => 'decision'],
				['kind' => 'b', 'blocks' => [' decision ', '', '  ', 'closing']],
				['kind' => 'c', 'blocks' => ['', ' ']],
				['kind' => 'd', 'blocks' => []],
				['kind' => 'e', 'blocks' => 42],
			]]
		);

		self::assertSame(['decision'], $kinds['a']['blocks']);
		self::assertSame(['decision', 'closing'], $kinds['b']['blocks']);
		self::assertSame(['closing'], $kinds['c']['blocks']);
		self::assertSame(['closing'], $kinds['d']['blocks']);
		self::assertSame(['closing'], $kinds['e']['blocks']);
	}//end testBlocksAreNormalised()

	/**
	 * A numeric string is a term, truncated to whole days; a negative or non-numeric one is none.
	 *
	 * @return void
	 */
	public function testATermIsReadFromNumericStrings(): void {
		$kinds = $this->declaration->kindsFor(
			caseType: ['obligationKinds' => [
				['kind' => 'a', 'term' => '15'],
				['kind' => 'b', 'term' => '2.9'],
				['kind' => 'c', 'term' => -3],
				['kind' => 'd', 'term' => 'ten'],
				['kind' => 'e', 'term' => '0.5'],
			]]
		);

		self::assertSame(15, $kinds['a']['term']);
		self::assertSame(2, $kinds['b']['term']);
		self::assertNull($kinds['c']['term']);
		self::assertNull($kinds['d']['term']);
		self::assertNull($kinds['e']['term']);
	}//end testATermIsReadFromNumericStrings()

	/**
	 * A state is compared trimmed, so a padded "met" releases.
	 *
	 * @return void
	 */
	public function testAPaddedStateIsTrimmed(): void {
		self::assertFalse($this->declaration->isBlocking(obligation: ['state' => ' met ']));
		self::assertTrue($this->declaration->isBlocking(obligation: ['state' => 'MET']));
	}//end testAPaddedStateIsTrimmed()

	/**
	 * A settled obligation blocks nothing, not even closing.
	 *
	 * @return void
	 */
	public function testASettledObligationBlocksNothing(): void {
		foreach (['met', 'withdrawn'] as $state) {
			self::assertFalse(
				$this->declaration->blocksStatus(obligation: ['state' => $state, 'blocks' => ['closing', 's1']], statusId: 's1', isClosing: true)
			);
		}
	}//end testASettledObligationBlocksNothing()

	/**
	 * An obligation with no usable blocks list blocks exactly the closing moves.
	 *
	 * @return void
	 */
	public function testNoBlocksListMeansClosingOnly(): void {
		foreach ([[], ['blocks' => []], ['blocks' => 'decision']] as $extra) {
			$obligation = array_merge(['state' => 'open'], $extra);
			self::assertTrue($this->declaration->blocksStatus(obligation: $obligation, statusId: 'decision', isClosing: true));
			self::assertFalse($this->declaration->blocksStatus(obligation: $obligation, statusId: 'decision', isClosing: false));
		}
	}//end testNoBlocksListMeansClosingOnly()

	/**
	 * Entries are trimmed, and a blank entry never matches a blank status id.
	 *
	 * @return void
	 */
	public function testEntriesAreTrimmedAndBlanksNeverMatch(): void {
		self::assertTrue(
			$this->declaration->blocksStatus(obligation: ['state' => 'open', 'blocks' => [' decision ']], statusId: 'decision', isClosing: false)
		);
		self::assertFalse(
			$this->declaration->blocksStatus(obligation: ['state' => 'open', 'blocks' => ['', ' ']], statusId: '', isClosing: false)
		);
	}//end testEntriesAreTrimmedAndBlanksNeverMatch()
}//end class
