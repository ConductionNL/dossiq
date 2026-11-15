<?php

/**
 * LeastLoadedStrategy edge-case tests.
 *
 * Pins how the open-task counts are read off the case, the team filter, and
 * a rule that cannot route.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\PoolMembership;
use OCA\Dossiq\Service\Routing\Strategy\LeastLoadedStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the least-loaded strategy.
 *
 * @covers \OCA\Dossiq\Service\Routing\Strategy\LeastLoadedStrategy
 *
 * @uses \OCA\Dossiq\Service\Routing\PoolMembership
 */
class LeastLoadedStrategyEdgeCasesTest extends TestCase {

	/**
	 * The strategy under test.
	 *
	 * @var LeastLoadedStrategy
	 */
	private LeastLoadedStrategy $strategy;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->strategy = new LeastLoadedStrategy(new PoolMembership());
	}//end setUp()

	/**
	 * The roles on the case: three handlers, two of them in team zuid.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function roles(): array {
		return [
			['roleType' => 'behandelaar', 'participant' => 'anouk', 'team' => 'zuid'],
			['roleType' => 'behandelaar', 'participant' => 'ben'],
			['roleType' => 'behandelaar', 'participant' => 'cor', 'team' => 'zuid'],
		];
	}//end roles()

	/**
	 * The strategy registers as least-loaded.
	 *
	 * @return void
	 */
	public function testTheStrategyIsNamedLeastLoaded(): void {
		self::assertSame('least-loaded', $this->strategy->name());
	}//end testTheStrategyIsNamedLeastLoaded()

	/**
	 * Numeric-string counts are read as numbers and a member without a count holds none.
	 *
	 * @return void
	 */
	public function testCountsAreReadAsNumbersAndAMissingCountIsZero(): void {
		$case = ['openTaskCountsByParticipant' => ['anouk' => '3', 'ben' => '10']];

		self::assertSame(['cor'], $this->strategy->resolve(['roleType' => 'behandelaar'], $case, self::roles()));

		$case['openTaskCountsByParticipant']['cor'] = 4;
		self::assertSame(['anouk'], $this->strategy->resolve(['roleType' => 'behandelaar'], $case, self::roles()));
	}//end testCountsAreReadAsNumbersAndAMissingCountIsZero()

	/**
	 * Counts that are not a map read as nobody holding anything, so the first member wins.
	 *
	 * @return void
	 */
	public function testMalformedCountsReadAsAnEmptyLoad(): void {
		self::assertSame(
			['anouk'],
			$this->strategy->resolve(['roleType' => 'behandelaar'], ['openTaskCountsByParticipant' => 'ben=0'], self::roles())
		);
	}//end testMalformedCountsReadAsAnEmptyLoad()

	/**
	 * A team-narrowed rule only considers that team, even when someone outside it is lighter.
	 *
	 * @return void
	 */
	public function testATeamRuleOnlyConsidersTheTeam(): void {
		$case = ['openTaskCountsByParticipant' => ['anouk' => 5, 'ben' => 0, 'cor' => 2]];

		self::assertSame(['cor'], $this->strategy->resolve(['roleType' => 'behandelaar', 'team' => ' zuid '], $case, self::roles()));
		self::assertSame([], $this->strategy->resolve(['roleType' => 'behandelaar', 'team' => 'noord'], $case, self::roles()));
	}//end testATeamRuleOnlyConsidersTheTeam()

	/**
	 * A rule without a role type routes to nobody.
	 *
	 * @return void
	 */
	public function testARuleWithoutARoleTypeRoutesToNobody(): void {
		self::assertSame([], $this->strategy->resolve(['roleType' => ''], [], self::roles()));
		self::assertSame([], $this->strategy->resolve([], [], self::roles()));
	}//end testARuleWithoutARoleTypeRoutesToNobody()
}//end class
