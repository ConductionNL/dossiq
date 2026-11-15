<?php

/**
 * Unit tests for CaseHeldException: a case held by OpenRegister's delete hook
 * is read from the hook's errors only when they name the held code and at
 * least one known rule, and answers the same body back.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Exception;

use OCA\Dossiq\Exception\CaseHeldException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Exception\CaseHeldException
 */
class CaseHeldExceptionTest extends TestCase {

	public function testHookErrorsNamingKnownRulesBecomeAHeldCase(): void {
		$held = CaseHeldException::fromHookErrors(
			['error' => 'case.held', 'blockedBy' => ['open-term', 'made-up', 'legal-hold'], 'message' => 'This case is held.']
		);

		$this->assertInstanceOf(CaseHeldException::class, $held);
		$this->assertSame(['open-term', 'legal-hold'], $held->getRules());
		$this->assertSame('This case is held.', $held->getMessage());
		$this->assertSame(409, $held->getCode());
		$this->assertSame(
			['message' => 'This case is held.', 'error' => 'case.held', 'blockedBy' => ['open-term', 'legal-hold']],
			$held->toResponseBody()
		);
	}//end testHookErrorsNamingKnownRulesBecomeAHeldCase()

	public function testOtherErrorsOrNoKnownRuleAreNotAHeldCase(): void {
		$this->assertNull(CaseHeldException::fromHookErrors(['error' => 'case.locked', 'blockedBy' => ['open-term']]));
		$this->assertNull(CaseHeldException::fromHookErrors(['error' => 'case.held', 'blockedBy' => ['made-up']]));
		$this->assertNull(CaseHeldException::fromHookErrors(['error' => 'case.held']));
		$this->assertNull(CaseHeldException::fromHookErrors([]));
	}//end testOtherErrorsOrNoKnownRuleAreNotAHeldCase()

	public function testASingleRuleStringAndAMissingMessageAreRead(): void {
		$held = CaseHeldException::fromHookErrors(['error' => 'case.held', 'blockedBy' => 'in-retention']);

		$this->assertSame(['in-retention'], $held->getRules());
		$this->assertSame('', $held->getMessage());
	}//end testASingleRuleStringAndAMissingMessageAreRead()
}//end class
