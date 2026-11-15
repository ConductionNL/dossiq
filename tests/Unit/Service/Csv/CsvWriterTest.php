<?php

/**
 * Unit tests for CsvWriter: every cell is quoted with inner quotes doubled, a
 * cell that a spreadsheet would run as a formula is neutralised with a leading
 * apostrophe, and a document is CRLF-terminated lines.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Csv;

use OCA\Dossiq\Service\Csv\CsvWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Csv\CsvWriter
 */
class CsvWriterTest extends TestCase {

	public function testCellsAreQuotedWithInnerQuotesDoubled(): void {
		$this->assertSame(
			'"Zaak ""A""","42","","1.5","a,b"',
			(new CsvWriter())->line(['Zaak "A"', 42, null, 1.5, 'a,b'])
		);
	}//end testCellsAreQuotedWithInnerQuotesDoubled()

	public function testFormulaLeadsAreNeutralised(): void {
		$writer = new CsvWriter();

		$this->assertSame('"\'=SUM(A1)"', $writer->line(['=SUM(A1)']));
		$this->assertSame('"\'+31 6"', $writer->line(['+31 6']));
		$this->assertSame('"\'-5"', $writer->line([-5]));
		$this->assertSame('"\'@cmd"', $writer->line(['@cmd']));
		$this->assertSame("\"'\tx\"", $writer->line(["\tx"]));
		$this->assertSame("\"'\ry\"", $writer->line(["\ry"]));
	}//end testFormulaLeadsAreNeutralised()

	public function testALeadOnlyCountsAtTheStart(): void {
		$this->assertSame('"a=b","x-1"," =1"', (new CsvWriter())->line(['a=b', 'x-1', ' =1']));
	}//end testALeadOnlyCountsAtTheStart()

	public function testADocumentIsCrlfTerminatedLinesHeaderFirst(): void {
		$this->assertSame(
			"\"Zaak\",\"Status\"\r\n\"Z-1\",\"Open\"\r\n\"Z-2\",\"'=1+1\"\r\n",
			(new CsvWriter())->document(['Zaak', 'Status'], [['Z-1', 'Open'], ['Z-2', '=1+1']])
		);
		$this->assertSame("\"Zaak\"\r\n", (new CsvWriter())->document(['Zaak'], []));
	}//end testADocumentIsCrlfTerminatedLinesHeaderFirst()
}//end class
