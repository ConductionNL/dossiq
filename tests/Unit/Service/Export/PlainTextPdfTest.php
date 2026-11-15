<?php

/**
 * The plain-text PDF writer: structure, wrapping, paging and escaping.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Export
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Export;

use OCA\Dossiq\Service\Export\PlainTextPdf;
use PHPUnit\Framework\TestCase;

/**
 * A rendered PDF is well-formed, wraps and pages its lines, and escapes its text.
 *
 * @covers \OCA\Dossiq\Service\Export\PlainTextPdf
 */
class PlainTextPdfTest extends TestCase {

	/**
	 * The page objects' content streams, in page order.
	 *
	 * @param string $pdf The rendered PDF.
	 *
	 * @return string[]
	 */
	private static function streams(string $pdf): array {
		preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $matches);
		return $matches[1];
	}//end streams()

	/**
	 * The text shown on a page, one entry per Tj operator.
	 *
	 * @param string $stream A page content stream.
	 *
	 * @return string[]
	 */
	private static function shown(string $stream): array {
		preg_match_all('/^\((.*)\) Tj$/m', $stream, $matches);
		return $matches[1];
	}//end shown()

	/**
	 * The document opens with a PDF header and closes with a trailer pointing at an xref
	 * whose offsets land exactly on each object.
	 *
	 * @return void
	 */
	public function testTheCrossReferenceTablePointsAtEveryObject(): void {
		$pdf = (new PlainTextPdf())->render(title: 'Report', lines: ['one', 'two']);

		self::assertStringStartsWith("%PDF-1.4\n", $pdf);
		self::assertStringEndsWith("%%EOF\n", $pdf);

		self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $start));
		self::assertSame('xref', substr($pdf, (int)$start[1], 4));

		// Catalog, pages, two fonts, then one stream and one page per page: 6 objects.
		self::assertStringContainsString("xref\n0 7\n", $pdf);
		self::assertStringContainsString("<< /Size 7 /Root 1 0 R >>", $pdf);

		preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offsets);
		self::assertCount(6, $offsets[1]);
		foreach ($offsets[1] as $index => $offset) {
			self::assertSame(($index + 1) . ' 0 obj', substr($pdf, (int)$offset, strlen(($index + 1) . ' 0 obj')));
		}
	}//end testTheCrossReferenceTablePointsAtEveryObject()

	/**
	 * Each content stream declares its exact length.
	 *
	 * @return void
	 */
	public function testEachStreamDeclaresItsLength(): void {
		$pdf = (new PlainTextPdf())->render(title: 'T', lines: ['a line']);

		self::assertSame(1, preg_match('/<< \/Length (\d+) >>\nstream\n(.*?)\nendstream/s', $pdf, $match));
		self::assertSame((int)$match[1], strlen($match[2]));
	}//end testEachStreamDeclaresItsLength()

	/**
	 * No lines still renders one page carrying the title.
	 *
	 * @return void
	 */
	public function testNoLinesStillRendersOnePageWithTheTitle(): void {
		$pdf = (new PlainTextPdf())->render(title: 'Empty', lines: []);

		self::assertStringContainsString('<< /Type /Pages /Kids [6 0 R] /Count 1 >>', $pdf);
		self::assertSame(['Empty'], self::shown(self::streams($pdf)[0]));
	}//end testNoLinesStillRendersOnePageWithTheTitle()

	/**
	 * A long line wraps at 95 characters, breaking a word longer than that.
	 *
	 * @return void
	 */
	public function testALongLineWrapsAt95Characters(): void {
		$long = str_repeat('x', 100);
		$pdf = (new PlainTextPdf())->render(title: '', lines: [$long]);

		self::assertSame([str_repeat('x', 95), 'xxxxx'], self::shown(self::streams($pdf)[0]));
	}//end testALongLineWrapsAt95Characters()

	/**
	 * Fifty-five lines fill one page of 54 and spill onto a second; only the first page has the title.
	 *
	 * @return void
	 */
	public function testLinesBeyond54StartANewPageWithoutTheTitle(): void {
		$lines = array_map(static fn (int $i): string => 'line ' . $i, range(1, 55));
		$pdf = (new PlainTextPdf())->render(title: 'Heading', lines: $lines);
		$streams = self::streams($pdf);

		self::assertStringContainsString('<< /Type /Pages /Kids [6 0 R 8 0 R] /Count 2 >>', $pdf);
		self::assertCount(2, $streams);

		$first = self::shown($streams[0]);
		self::assertCount(55, $first);
		self::assertSame('Heading', $first[0]);
		self::assertSame('line 54', $first[54]);

		self::assertSame(['line 55'], self::shown($streams[1]));
	}//end testLinesBeyond54StartANewPageWithoutTheTitle()

	/**
	 * Backslashes and parentheses are escaped, carriage returns dropped, UTF-8 re-encoded as Windows-1252.
	 *
	 * @return void
	 */
	public function testTextIsEscapedAndEncoded(): void {
		$pdf = (new PlainTextPdf())->render(title: '', lines: ["a (b) c\\d\re", 'café']);

		self::assertSame(['a \\(b\\) c\\\\de', "caf\xE9"], self::shown(self::streams($pdf)[0]));
	}//end testTextIsEscapedAndEncoded()
}//end class
