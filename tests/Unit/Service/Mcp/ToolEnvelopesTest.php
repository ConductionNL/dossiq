<?php

/**
 * The error envelope every MCP tool answers with, and how a refusal fills it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Mcp
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use InvalidArgumentException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Mcp\ToolEnvelopes;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\Dossiq\Service\Mcp\ToolEnvelopes
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class ToolEnvelopesTest extends TestCase {

	/**
	 * A tool using the trait, exposing its two private helpers.
	 *
	 * @return object
	 */
	private function tool(): object {
		return new class {
			use ToolEnvelopes;

			/**
			 * The error envelope.
			 *
			 * @param string $code    The code.
			 * @param string $message The message.
			 *
			 * @return array<string, string>
			 */
			public function errorOf(string $code, string $message): array {
				return $this->error(code: $code, message: $message);
			}

			/**
			 * The refusal envelope.
			 *
			 * @param Throwable $e The exception.
			 *
			 * @return array<string, string>
			 */
			public function refusalOf(Throwable $e): array {
				return $this->refusal(e: $e);
			}
		};
	}//end tool()

	public function testTheErrorEnvelopeCarriesTheCodeAndMessage(): void {
		self::assertSame(['error' => 'not_found', 'message' => 'No such case'], $this->tool()->errorOf(code: 'not_found', message: 'No such case'));
	}//end testTheErrorEnvelopeCarriesTheCodeAndMessage()

	public function testARefusalAnswersItsRuleAndSentenceNotItsMessage(): void {
		$e = new RefusedException(rule: 'case-closed', sentence: 'The case is closed.');

		self::assertSame(['error' => 'case-closed', 'message' => 'The case is closed.'], $this->tool()->refusalOf(e: $e));
	}//end testARefusalAnswersItsRuleAndSentenceNotItsMessage()

	public function testAnyOtherExceptionIsAGenericRefusalWithItsMessage(): void {
		self::assertSame(
			['error' => 'refused', 'message' => 'bad input'],
			$this->tool()->refusalOf(e: new InvalidArgumentException('bad input'))
		);
	}//end testAnyOtherExceptionIsAGenericRefusalWithItsMessage()
}//end class
