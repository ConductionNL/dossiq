<?php

/**
 * Unit tests for AiPiiRedactor: BSNs, IBANs, Dutch phone numbers and postcodes
 * are found with their byte offsets in text order, and stripped from a prompt
 * as a typed placeholder.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\AiPiiRedactor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Ai\AiPiiRedactor
 */
class AiPiiRedactorTest extends TestCase {

	private const TEXT = 'Bel 0612345678 over 1234 AB, BSN 123456782, rekening NL91ABNA0417164300.';

	public function testSpansAreFoundWithOffsetsInTextOrder(): void {
		$this->assertSame(
			[
				['start' => 4, 'end' => 14, 'category' => 'phone', 'text' => '0612345678'],
				['start' => 20, 'end' => 27, 'category' => 'postcode', 'text' => '1234 AB'],
				['start' => 33, 'end' => 42, 'category' => 'bsn', 'text' => '123456782'],
				['start' => 53, 'end' => 71, 'category' => 'iban', 'text' => 'NL91ABNA0417164300'],
			],
			(new AiPiiRedactor())->detectSpans(self::TEXT)
		);
	}//end testSpansAreFoundWithOffsetsInTextOrder()

	public function testOffsetsAreBytesAfterMultibyteText(): void {
		$spans = (new AiPiiRedactor())->detectSpans('Café 1234AB');

		$this->assertSame([['start' => 6, 'end' => 12, 'category' => 'postcode', 'text' => '1234AB']], $spans);
	}//end testOffsetsAreBytesAfterMultibyteText()

	public function testLongerDigitRunsAndLowercasePostcodesAreNothing(): void {
		$this->assertSame([], (new AiPiiRedactor())->detectSpans('Zaaknummer 12345678901234 en 1234 ab'));
	}//end testLongerDigitRunsAndLowercasePostcodesAreNothing()

	public function testAPromptIsStrippedToTypedPlaceholders(): void {
		$this->assertSame(
			'Bel [PHONE_REMOVED] over [POSTCODE_REMOVED], BSN [BSN_REMOVED], rekening [IBAN_REMOVED].',
			(new AiPiiRedactor())->strip(self::TEXT)
		);
		$this->assertSame('Geen persoonsgegevens.', (new AiPiiRedactor())->strip('Geen persoonsgegevens.'));
	}//end testAPromptIsStrippedToTypedPlaceholders()
}//end class
