<?php

/**
 * Tests for CaseUnderJudgement: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseUnderJudgement;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CaseUnderJudgement.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseUnderJudgementTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$now = new DateTimeImmutable();
		$subject = new CaseUnderJudgement(
			case: ['case' => 1],
			now: $now,
			context: ['context' => 1],
			standing: ['standing' => 1]
		);

		self::assertSame(expected: ['case' => 1], actual: $subject->case);
		self::assertSame(expected: $now, actual: $subject->now);
		self::assertSame(expected: ['context' => 1], actual: $subject->context);
		self::assertSame(expected: ['standing' => 1], actual: $subject->standing);
	}//end testPropertiesReadBack()
}//end class
