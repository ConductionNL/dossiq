<?php

/**
 * RangeStreamResponse Unit Tests
 *
 * Verifies HTTP Range handling for the ZGW DRC download response: full content
 * with Accept-Ranges on no/invalid range, 206 Partial Content with a correct
 * Content-Range and sliced body on a satisfiable range, suffix ranges, and
 * open-ended ranges.
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
 * Unit tests for RangeStreamResponse.
 *
 * @covers \OCA\Dossiq\Http\RangeStreamResponse
 */
class RangeStreamResponseTest extends TestCase {

	/**
	 * No range header serves the full body with status 200 and Accept-Ranges.
	 *
	 * @return void
	 */
	public function testFullContentWhenNoRange(): void {
		$content = str_repeat('A', 100);
		$response = new RangeStreamResponse($content, 'file.bin', 'application/octet-stream', '');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($content, $response->render());
		$headers = $response->getHeaders();
		$this->assertSame('bytes', $headers['Accept-Ranges']);
		$this->assertSame('100', $headers['Content-Length']);

	}//end testFullContentWhenNoRange()

	/**
	 * A satisfiable range yields 206 with a sliced body and Content-Range.
	 *
	 * @return void
	 */
	public function testPartialContentForRange(): void {
		$content = '';
		for ($i = 0; $i < 256; $i++) {
			$content .= chr($i % 256);
		}

		$response = new RangeStreamResponse($content, 'file.bin', 'application/octet-stream', 'bytes=0-9');

		$this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		$this->assertSame(10, strlen($response->render()));
		$this->assertSame(substr($content, 0, 10), $response->render());

		$headers = $response->getHeaders();
		$this->assertSame('bytes 0-9/256', $headers['Content-Range']);
		$this->assertSame('10', $headers['Content-Length']);

	}//end testPartialContentForRange()

	/**
	 * A mid-file range returns the correct slice and Content-Range.
	 *
	 * @return void
	 */
	public function testMidFileRange(): void {
		$content = str_repeat('X', 50) . str_repeat('Y', 50);
		$response = new RangeStreamResponse($content, 'f', 'text/plain', 'bytes=50-59');

		$this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		$this->assertSame('YYYYYYYYYY', $response->render());
		$this->assertSame('bytes 50-59/100', $response->getHeaders()['Content-Range']);

	}//end testMidFileRange()

	/**
	 * An open-ended range (start-) returns through end of file.
	 *
	 * @return void
	 */
	public function testOpenEndedRange(): void {
		$content = str_repeat('Z', 20);
		$response = new RangeStreamResponse($content, 'f', 'text/plain', 'bytes=10-');

		$this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		$this->assertSame(10, strlen($response->render()));
		$this->assertSame('bytes 10-19/20', $response->getHeaders()['Content-Range']);

	}//end testOpenEndedRange()

	/**
	 * A suffix range (-N) returns the last N bytes.
	 *
	 * @return void
	 */
	public function testSuffixRange(): void {
		$content = '0123456789';
		$response = new RangeStreamResponse($content, 'f', 'text/plain', 'bytes=-3');

		$this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		$this->assertSame('789', $response->render());
		$this->assertSame('bytes 7-9/10', $response->getHeaders()['Content-Range']);

	}//end testSuffixRange()

	/**
	 * An unsatisfiable range falls back to full content (status 200).
	 *
	 * @return void
	 */
	public function testUnsatisfiableRangeFallsBackToFull(): void {
		$content = '0123456789';
		$response = new RangeStreamResponse($content, 'f', 'text/plain', 'bytes=999-1200');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($content, $response->render());

	}//end testUnsatisfiableRangeFallsBackToFull()
}//end class
