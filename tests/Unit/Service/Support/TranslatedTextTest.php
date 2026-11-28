<?php

/**
 * TranslatedText unit tests
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

use OCA\Dossiq\Service\Support\TranslatedText;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\TranslatedText
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 */
class TranslatedTextTest extends TestCase {

	/**
	 * The signed-in reader's language picks the text from the map.
	 *
	 * @return void
	 */
	public function testTheReadersLanguagePicksTheText(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects(self::once())->method('getLanguageCode')->willReturn('en_GB');

		self::assertSame('Felling permit', (new TranslatedText(l10n: $l10n))->forReader(value: ['nl' => 'Kapvergunning', 'en' => 'Felling permit']));
	}//end testTheReadersLanguagePicksTheText()

	/**
	 * Without a translator the text is the Dutch one, the same for everyone.
	 *
	 * @return void
	 */
	public function testWithoutATranslatorTheTextIsDutch(): void {
		$text = new TranslatedText();

		self::assertSame('Kapvergunning', $text->forReader(value: ['en' => 'Felling permit', 'nl' => 'Kapvergunning']));
		self::assertSame('Plain', $text->forReader(value: ' Plain '));
		self::assertSame('', $text->forReader(value: 5));
	}//end testWithoutATranslatorTheTextIsDutch()
}//end class
