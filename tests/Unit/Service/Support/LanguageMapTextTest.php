<?php

/**
 * LanguageMapText unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\LanguageMapText;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\LanguageMapText
 */
class LanguageMapTextTest extends TestCase {

	/**
	 * A plain string is answered trimmed; a non-string, non-map is empty.
	 *
	 * @return void
	 */
	public function testAStringIsItselfAndAScalarIsNothing(): void {
		$text = new LanguageMapText();

		self::assertSame('Kapvergunning', $text->textOf(value: '  Kapvergunning '));
		self::assertSame('', $text->textOf(value: 42));
		self::assertSame('', $text->textOf(value: null));
	}//end testAStringIsItselfAndAScalarIsNothing()

	/**
	 * The reader's language wins, and a regional code also accepts its base.
	 *
	 * @return void
	 */
	public function testTheReadersLanguageWins(): void {
		$text = new LanguageMapText();
		$map  = ['nl' => 'Kapvergunning', 'en' => 'Felling permit', 'de' => 'Fällgenehmigung'];

		self::assertSame('Felling permit', $text->textOf(value: $map, language: 'en'));
		self::assertSame('Felling permit', $text->textOf(value: $map, language: 'en_GB'));
		self::assertSame('Fällgenehmigung', $text->textOf(value: $map, language: 'DE-at'));
		self::assertSame('Regional', $text->textOf(value: ['en' => 'Base', 'en_GB' => 'Regional'], language: 'en_GB'));
	}//end testTheReadersLanguageWins()

	/**
	 * Without the reader's language, Dutch; without Dutch, the first text.
	 *
	 * @return void
	 */
	public function testDutchThenTheFirstText(): void {
		$text = new LanguageMapText();

		self::assertSame('Kapvergunning', $text->textOf(value: ['en' => 'Felling permit', 'nl' => 'Kapvergunning'], language: 'fr'));
		self::assertSame('Kapvergunning', $text->textOf(value: ['en' => 'Felling permit', 'nl' => 'Kapvergunning']));
		self::assertSame('Permis', $text->textOf(value: ['fr' => ' Permis ', 'en' => 'Permit'], language: 'de'));
	}//end testDutchThenTheFirstText()

	/**
	 * Empty or non-string entries are passed over, also for the preferred code.
	 *
	 * @return void
	 */
	public function testEmptyEntriesArePassedOver(): void {
		$text = new LanguageMapText();

		self::assertSame('Permit', $text->textOf(value: ['nl' => '  ', 'x' => ['nested'], 'en' => 'Permit'], language: 'nl'));
		self::assertSame('', $text->textOf(value: ['nl' => '', 'en' => 3]));
		self::assertSame('', $text->textOf(value: []));
	}//end testEmptyEntriesArePassedOver()

	/**
	 * The fallback answers only when the value holds no text.
	 *
	 * @return void
	 */
	public function testTextOrFallsBackOnlyWhenEmpty(): void {
		$text = new LanguageMapText();

		self::assertSame('case-1', $text->textOr(value: [], fallback: 'case-1'));
		self::assertSame('case-1', $text->textOr(value: null, fallback: 'case-1'));
		self::assertSame('Felling permit', $text->textOr(value: ['nl' => 'Kap', 'en' => 'Felling permit'], fallback: 'x', language: 'en'));
		self::assertSame('Kap', $text->textOr(value: 'Kap', fallback: 'x'));
	}//end testTextOrFallsBackOnlyWhenEmpty()
}//end class
