<?php

/**
 * Dossiq ThresholdShares default ladder and bounds test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use DateTimeImmutable;
use OCA\Dossiq\Service\Term\ThresholdShares;
use PHPUnit\Framework\TestCase;

/**
 * The default ladder for a short term, and where a declared rung stops being placeable.
 *
 * @covers \OCA\Dossiq\Service\Term\ThresholdShares
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 */
final class ThresholdSharesDefaultsTest extends TestCase {
	/**
	 * The offsets and triggers of a rule list.
	 *
	 * @param array<int, array<string, mixed>> $rules The rules.
	 *
	 * @return array<int, string>
	 */
	private static function shape(array $rules): array {
		return array_map(static fn (array $rule): string => $rule['trigger'] . ':' . $rule['offset'], $rules);
	}//end shape()

	/**
	 * A term of fourteen days or more gets no default ladder.
	 *
	 * @return void
	 */
	public function testALongTermGetsNoDefaults(): void {
		$shares = new ThresholdShares();
		$start = new DateTimeImmutable('2026-03-01');

		$this->assertSame([], $shares->defaultRulesFor($start, new DateTimeImmutable('2026-03-15')));
		$this->assertSame([], $shares->defaultRulesFor($start, new DateTimeImmutable('2026-06-01')));
	}//end testALongTermGetsNoDefaults()

	/**
	 * A ten-day term keeps only the rungs that fit inside it, in ladder order, with unit and incident flag.
	 *
	 * @return void
	 */
	public function testAShortTermKeepsTheRungsThatFit(): void {
		$rules = (new ThresholdShares())->defaultRulesFor(new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-11'));

		$this->assertSame(['preBreach:7', 'preBreach:2', 'slaBreached:0'], self::shape($rules));
		$this->assertSame(
			[
				'trigger' => 'preBreach',
				'offset' => 7,
				'notifyRole' => ['handler', 'teamleader'],
				'escalateToRole' => ['teamleader'],
				'priority' => 'medium',
				'message' => 'termijn-7d',
				'offsetUnit' => 'calendarDays',
				'openIncident' => false,
			],
			$rules[0]
		);
		$this->assertSame('critical', $rules[2]['priority']);
	}//end testAShortTermKeepsTheRungsThatFit()

	/**
	 * The thirteen-day boundary and a same-day term.
	 *
	 * @return void
	 */
	public function testTheBoundaries(): void {
		$shares = new ThresholdShares();
		$start = new DateTimeImmutable('2026-03-01');

		$this->assertSame(['preBreach:7', 'preBreach:2', 'slaBreached:0'], self::shape($shares->defaultRulesFor($start, new DateTimeImmutable('2026-03-14'))));
		$this->assertSame(['preBreach:2', 'slaBreached:0'], self::shape($shares->defaultRulesFor($start, new DateTimeImmutable('2026-03-03'))));
		$this->assertSame(['slaBreached:0'], self::shape($shares->defaultRulesFor($start, new DateTimeImmutable('2026-03-01 18:00'))));
	}//end testTheBoundaries()

	/**
	 * No end, or an end before the start, is no ladder.
	 *
	 * @return void
	 */
	public function testNoOrAnInvertedEndIsNoLadder(): void {
		$shares = new ThresholdShares();
		$start = new DateTimeImmutable('2026-03-10');

		$this->assertSame([], $shares->defaultRulesFor($start, null));
		$this->assertSame([], $shares->defaultRulesFor($start, new DateTimeImmutable('2026-03-09')));
	}//end testNoOrAnInvertedEndIsNoLadder()

	/**
	 * Days win over a share; out-of-range days and shares and non-array rungs are dropped; share ends are inclusive.
	 *
	 * @return void
	 */
	public function testRungBounds(): void {
		$rules = (new ThresholdShares())->rulesFor(
			[
				['days' => 3, 'share' => 0.5],
				['days' => -1],
				['days' => 21],
				['days' => '20'],
				['share' => 1.0],
				['share' => 0.0],
				['share' => 1.01],
				['share' => -0.1],
				['share' => 'half'],
				'not a rung',
				[],
			],
			20
		);

		$this->assertSame(['preBreach:20', 'preBreach:20', 'preBreach:3', 'preBreach:0'], self::shape($rules));
		$this->assertSame('termijn-drempel', $rules[0]['message']);
		$this->assertSame(['handler'], $rules[0]['notifyRole']);
	}//end testRungBounds()

	/**
	 * A share rounds to the nearest whole elapsed day.
	 *
	 * @return void
	 */
	public function testAShareRoundsToWholeDays(): void {
		$shares = new ThresholdShares();

		$this->assertSame(['preBreach:2'], self::shape($shares->rulesFor([['share' => 0.5]], 5)));
		$this->assertSame(['preBreach:3'], self::shape($shares->rulesFor([['share' => 0.4]], 5)));
		$this->assertSame(['preBreach:2'], self::shape($shares->rulesFor([['share' => 0.7]], 7)));
		$this->assertSame([], $shares->rulesFor([['share' => 0.5]], -3));
	}//end testAShareRoundsToWholeDays()
}//end class
