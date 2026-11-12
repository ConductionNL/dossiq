<?php

/**
 * The awarded legal-costs total of an objection decision: points times point value, or nothing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-decision/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\DecisionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\DecisionValidator
 */
class ProceskostenTotalTest extends TestCase {

	/**
	 * Decisions and the total each answers.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: float|null}>
	 */
	public static function decisions(): array {
		return [
			'awarded points times value' => [['legalCostsCompensation' => ['awarded' => true, 'awardedPoints' => 2, 'pointValue' => 647]], 1294.0],
			'fractional points' => [['legalCostsCompensation' => ['awarded' => true, 'awardedPoints' => '0.5', 'pointValue' => '647']], 323.5],
			'not awarded' => [['legalCostsCompensation' => ['awarded' => false, 'awardedPoints' => 2, 'pointValue' => 647]], null],
			'awarded as text is not awarded' => [['legalCostsCompensation' => ['awarded' => 'true', 'awardedPoints' => 2, 'pointValue' => 647]], null],
			'no points' => [['legalCostsCompensation' => ['awarded' => true, 'awardedPoints' => 0, 'pointValue' => 647]], null],
			'negative value' => [['legalCostsCompensation' => ['awarded' => true, 'awardedPoints' => 1, 'pointValue' => -5]], null],
			'missing value' => [['legalCostsCompensation' => ['awarded' => true, 'awardedPoints' => 1]], null],
			'no block at all' => [[], null],
		];
	}//end decisions()

	/**
	 * Each decision answers its total.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param float|null           $expected The total.
	 *
	 * @return void
	 */
	#[DataProvider('decisions')]
	public function testComputeProceskostenTotal(array $decision, ?float $expected): void {
		self::assertSame($expected, (new DecisionValidator())->computeProceskostenTotal(decision: $decision));
	}//end testComputeProceskostenTotal()
}//end class
