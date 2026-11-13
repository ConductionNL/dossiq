<?php

/**
 * Unit tests for RefusedException: a refusal carries its rule, the sentence a
 * person reads and an HTTP status (409 unless said otherwise), and its message
 * is the rule in snake case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Exception;

use OCA\Dossiq\Exception\RefusedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Exception\RefusedException
 */
class RefusedExceptionTest extends TestCase {

	public function testARefusalIsAConflictWithItsRuleAsSnakeCaseMessage(): void {
		$refusal = new RefusedException(rule: 'case-type-not-published', sentence: 'This case type is not published yet.');

		$this->assertSame('case-type-not-published', $refusal->getRule());
		$this->assertSame('This case type is not published yet.', $refusal->getSentence());
		$this->assertSame(409, $refusal->getStatus());
		$this->assertSame('case_type_not_published', $refusal->getMessage());
		$this->assertSame(0, $refusal->getCode());
		$this->assertNull($refusal->getPrevious());
	}//end testARefusalIsAConflictWithItsRuleAsSnakeCaseMessage()

	public function testAStatusAndCauseCanBeGiven(): void {
		$cause = new RuntimeException('db');
		$refusal = new RefusedException(rule: 'not-yours', sentence: 'You may not do this.', status: RefusedException::STATUS_FORBIDDEN, previous: $cause);

		$this->assertSame(403, $refusal->getStatus());
		$this->assertSame($cause, $refusal->getPrevious());
	}//end testAStatusAndCauseCanBeGiven()

	public function testAnIndeterminateRefusalIsAServiceUnavailable(): void {
		$cause = new RuntimeException('timeout');
		$refusal = RefusedException::indeterminate(rule: 'register-unreachable', sentence: 'The register did not answer.', previous: $cause);

		$this->assertSame(503, $refusal->getStatus());
		$this->assertSame('register-unreachable', $refusal->getRule());
		$this->assertSame('register_unreachable', $refusal->getMessage());
		$this->assertSame('The register did not answer.', $refusal->getSentence());
		$this->assertSame($cause, $refusal->getPrevious());
		$this->assertNull(RefusedException::indeterminate(rule: 'x', sentence: 'y')->getPrevious());
	}//end testAnIndeterminateRefusalIsAServiceUnavailable()
}//end class
