<?php

/**
 * Folding a raw SPF/DKIM/DMARC token into the four results intake understands.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\AuthenticationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\AuthenticationResult
 */
class AuthenticationResultTest extends TestCase {

	/**
	 * Raw tokens and the result each folds to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function tokens(): array {
		return [
			'pass' => ['pass', AuthenticationResult::PASS],
			'padded upper pass' => ['  PASS ', AuthenticationResult::PASS],
			'fail' => ['fail', AuthenticationResult::FAIL],
			'none' => ['None', AuthenticationResult::NONE],
			'softfail is a fail' => ['softfail', AuthenticationResult::FAIL],
			'hardfail is a fail' => ['HardFail', AuthenticationResult::FAIL],
			'neutral is not known' => ['neutral', AuthenticationResult::UNAVAILABLE],
			'temperror is not known' => ['temperror', AuthenticationResult::UNAVAILABLE],
			'empty is not known' => ['', AuthenticationResult::UNAVAILABLE],
		];
	}//end tokens()

	/**
	 * Each token folds to its result.
	 *
	 * @param string $raw      The raw token.
	 * @param string $expected The folded result.
	 *
	 * @return void
	 */
	#[DataProvider('tokens')]
	public function testFromToken(string $raw, string $expected): void {
		self::assertSame($expected, AuthenticationResult::fromToken(raw: $raw));
	}//end testFromToken()

	public function testIsKnownAcceptsOnlyTheFourResultsExactly(): void {
		foreach (AuthenticationResult::ALL as $value) {
			self::assertTrue(AuthenticationResult::isKnown(value: $value), $value);
		}

		self::assertFalse(AuthenticationResult::isKnown(value: 'softfail'));
		self::assertFalse(AuthenticationResult::isKnown(value: 'PASS'));
		self::assertSame(['pass', 'fail', 'none', 'unavailable'], AuthenticationResult::ALL);
	}//end testIsKnownAcceptsOnlyTheFourResultsExactly()

	public function testOnlyFailIsAFailure(): void {
		self::assertTrue(AuthenticationResult::isFailure(value: AuthenticationResult::FAIL));
		self::assertFalse(AuthenticationResult::isFailure(value: AuthenticationResult::UNAVAILABLE));
		self::assertFalse(AuthenticationResult::isFailure(value: AuthenticationResult::NONE));
		self::assertFalse(AuthenticationResult::isFailure(value: 'softfail'));
	}//end testOnlyFailIsAFailure()
}//end class
