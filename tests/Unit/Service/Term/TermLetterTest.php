<?php

/**
 * Dossiq TermLetter test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\TermLetter;
use PHPUnit\Framework\TestCase;

/**
 * One letter: its language and the trimmed context texts it is written from.
 *
 * @covers \OCA\Dossiq\Service\Term\TermLetter
 */
class TermLetterTest extends TestCase {
	/**
	 * Only the `en` locale writes the English letter.
	 *
	 * @return void
	 */
	public function testOnlyEnIsEnglish(): void {
		$this->assertTrue($this->letter('en', [])->isEnglish());
		$this->assertFalse($this->letter('nl', [])->isEnglish());
		$this->assertFalse($this->letter('en_GB', [])->isEnglish());
		$this->assertFalse($this->letter('EN', [])->isEnglish());
	}//end testOnlyEnIsEnglish()

	/**
	 * A context text is trimmed and cast; a missing or null one is empty.
	 *
	 * @return void
	 */
	public function testTextIsTrimmedAndMissingIsEmpty(): void {
		$letter = $this->letter('nl', ['reason' => "  wacht op stukken \n", 'days' => 14, 'none' => null]);

		$this->assertSame('wacht op stukken', $letter->text('reason'));
		$this->assertSame('14', $letter->text('days'));
		$this->assertSame('', $letter->text('none'));
		$this->assertSame('', $letter->text('absent'));
		$this->assertSame(['Z-1', '2026-12-01'], [$letter->reference, $letter->end]);
	}//end testTextIsTrimmedAndMissingIsEmpty()

	/**
	 * A letter for a case.
	 *
	 * @param string               $locale  The language.
	 * @param array<string, mixed> $context The texts.
	 *
	 * @return TermLetter The letter.
	 */
	private function letter(string $locale, array $context): TermLetter {
		return new TermLetter(reference: 'Z-1', end: '2026-12-01', locale: $locale, context: $context);
	}//end letter()
}//end class
