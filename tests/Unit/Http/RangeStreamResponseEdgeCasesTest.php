<?php

/**
 * RangeStreamResponse edge-case unit tests
 *
 * Pins the range parser's boundaries: malformed and unsatisfiable headers
 * serve the whole body, an end past the file is clamped, a suffix longer than
 * the file serves all of it, and the download headers are always set.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Http;

use OCA\Dossiq\Http\RangeStreamResponse;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of RangeStreamResponse.
 *
 * @covers \OCA\Dossiq\Http\RangeStreamResponse
 */
class RangeStreamResponseEdgeCasesTest extends TestCase {

	/**
	 * The ten-byte body every case slices.
	 *
	 * @var string
	 */
	private const BODY = '0123456789';

	/**
	 * A response for the body under this range header.
	 *
	 * @param string $range The Range header.
	 *
	 * @return RangeStreamResponse
	 */
	private static function respond(string $range): RangeStreamResponse {
		return new RangeStreamResponse(content: self::BODY, fileName: 'besluit.pdf', contentType: 'application/pdf', rangeHeader: $range);
	}//end respond()

	/**
	 * Headers that ask for no satisfiable single byte range.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function fullBodyRanges(): array {
		return [
			'both bounds absent' => ['bytes=-'],
			'zero suffix' => ['bytes=-0'],
			'start after end' => ['bytes=5-2'],
			'start past the file' => ['bytes=10-12'],
			'other unit' => ['items=0-3'],
			'multiple ranges' => ['bytes=0-1,4-5'],
			'negative start' => ['bytes=-3-5'],
			'not a number' => ['bytes=a-b'],
		];
	}//end fullBodyRanges()

	/**
	 * Each of those serves the whole body with status 200 and no Content-Range.
	 *
	 * @param string $range The Range header.
	 *
	 * @return void
	 *
	 * @dataProvider fullBodyRanges
	 */
	public function testAnUnusableRangeServesTheWholeBody(string $range): void {
		$response = self::respond(range: $range);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(self::BODY, $response->render());
		self::assertSame('10', $response->getHeaders()['Content-Length']);
		self::assertArrayNotHasKey('Content-Range', $response->getHeaders());
	}//end testAnUnusableRangeServesTheWholeBody()

	/**
	 * Satisfiable ranges, with the slice and Content-Range each gives.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function partialRanges(): array {
		return [
			'end past the file is clamped' => ['bytes=7-99', '789', 'bytes 7-9/10'],
			'single byte' => ['bytes=4-4', '4', 'bytes 4-4/10'],
			'last byte' => ['bytes=9-', '9', 'bytes 9-9/10'],
			'suffix longer than the file' => ['bytes=-25', self::BODY, 'bytes 0-9/10'],
			'surrounding whitespace' => ['  bytes=0-1 ', '01', 'bytes 0-1/10'],
		];
	}//end partialRanges()

	/**
	 * Each serves status 206 with the slice, its length and its range.
	 *
	 * @param string $range        The Range header.
	 * @param string $slice        The expected body.
	 * @param string $contentRange The expected Content-Range.
	 *
	 * @return void
	 *
	 * @dataProvider partialRanges
	 */
	public function testASatisfiableRangeServesTheSlice(string $range, string $slice, string $contentRange): void {
		$response = self::respond(range: $range);

		self::assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		self::assertSame($slice, $response->render());
		self::assertSame($contentRange, $response->getHeaders()['Content-Range']);
		self::assertSame((string)strlen($slice), $response->getHeaders()['Content-Length']);
	}//end testASatisfiableRangeServesTheSlice()

	/**
	 * An empty body ignores any range and serves nothing with status 200.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyIgnoresTheRange(): void {
		$response = new RangeStreamResponse(content: '', fileName: 'leeg.txt', contentType: 'text/plain', rangeHeader: 'bytes=0-5');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('', $response->render());
		self::assertSame('0', $response->getHeaders()['Content-Length']);
	}//end testAnEmptyBodyIgnoresTheRange()

	/**
	 * The download headers are set on every response, the file name URL-encoded.
	 *
	 * @return void
	 */
	public function testTheDownloadHeadersAreAlwaysSet(): void {
		foreach (['', 'bytes=0-1'] as $range) {
			$headers = (new RangeStreamResponse(
				content: self::BODY,
				fileName: 'besluit "definitief" 2026.pdf',
				contentType: 'application/pdf',
				rangeHeader: $range
			))->getHeaders();

			self::assertSame('bytes', $headers['Accept-Ranges'], $range);
			self::assertSame('application/pdf', $headers['Content-Type'], $range);
			self::assertSame('attachment; filename="besluit%20%22definitief%22%202026.pdf"', $headers['Content-Disposition'], $range);
		}
	}//end testTheDownloadHeadersAreAlwaysSet()
}//end class
