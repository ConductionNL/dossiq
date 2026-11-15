<?php

/**
 * Unit tests for MandaatCsvParser: a mandate register CSV needs its required
 * columns, becomes one row per non-blank line padded to the header, and its
 * yes/no and list cells are read leniently.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mandaat;

use OCA\Dossiq\Service\Mandaat\MandaatCsvParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Mandaat\MandaatCsvParser
 */
class MandaatCsvParserTest extends TestCase {

	public function testEveryNonBlankLineBecomesARowPaddedToTheHeader(): void {
		$csv = "mandaatNummer,omschrijving,rolNaam,plafondCents,actief\r\n"
			. "M-1,\"Vergunningen, klein\",Teamleider,500000,ja\n"
			. "\n"
			. "M-2,Subsidies,Directeur,0\r"
			. "   \n";

		$this->assertSame(
			[
				['mandaatNummer' => 'M-1', 'omschrijving' => 'Vergunningen, klein', 'rolNaam' => 'Teamleider', 'plafondCents' => '500000', 'actief' => 'ja'],
				['mandaatNummer' => 'M-2', 'omschrijving' => 'Subsidies', 'rolNaam' => 'Directeur', 'plafondCents' => '0', 'actief' => ''],
			],
			(new MandaatCsvParser())->parse($csv)
		);
	}//end testEveryNonBlankLineBecomesARowPaddedToTheHeader()

	public function testAHeaderWithoutRowsIsNoRows(): void {
		$this->assertSame([], (new MandaatCsvParser())->parse("mandaatNummer,omschrijving,rolNaam,plafondCents\n"));
		$this->assertSame([], (new MandaatCsvParser())->parse(''));
	}//end testAHeaderWithoutRowsIsNoRows()

	public function testMissingRequiredColumnsAreNamed(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Missing required CSV columns: rolNaam, plafondCents');

		(new MandaatCsvParser())->parse("mandaatNummer,omschrijving\nM-1,x");
	}//end testMissingRequiredColumnsAreNamed()

	public function testYesIsReadInDutchAndEnglishInAnyCase(): void {
		$parser = new MandaatCsvParser();

		foreach (['1', 'true', 'JA', ' yes ', 'Y'] as $yes) {
			$this->assertTrue($parser->parseBool($yes), $yes);
		}

		foreach (['0', 'nee', 'no', '', 'waar'] as $no) {
			$this->assertFalse($parser->parseBool($no), $no);
		}
	}//end testYesIsReadInDutchAndEnglishInAnyCase()

	public function testAListIsSemicolonSeparatedWithBlanksDropped(): void {
		$parser = new MandaatCsvParser();

		$this->assertSame(['vergunning', 'subsidie'], $parser->parseList(' vergunning ; ;subsidie; '));
		$this->assertSame(['melding'], $parser->parseList('melding'));
		$this->assertSame([], $parser->parseList('  '));
	}//end testAListIsSemicolonSeparatedWithBlanksDropped()
}//end class
