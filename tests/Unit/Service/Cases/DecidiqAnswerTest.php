<?php

/**
 * Tests for DecidiqAnswer: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\DecidiqAnswer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DecidiqAnswer.
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
 */
class DecidiqAnswerTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new DecidiqAnswer(
			gate: ['gate' => 1],
			reference: 'reference-value',
			approvers: ['approvers' => 1]
		);

		self::assertSame(expected: ['gate' => 1], actual: $subject->gate);
		self::assertSame(expected: 'reference-value', actual: $subject->reference);
		self::assertSame(expected: ['approvers' => 1], actual: $subject->approvers);
	}//end testPropertiesReadBack()
}//end class
