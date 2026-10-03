<?php

/**
 * The senior handler of team zuid, not the senior handler of everywhere.
 *
 * 🔴 THE SECOND HALF OF THE SCENARIO IS THE ONE THAT CATCHES THE DEFECT. That
 * the case reaches the senior of team zuid is easy to satisfy by accident, by
 * a rule that simply reached the first senior in the list. That the senior of
 * the OTHER team is not a candidate is what proves the team narrowed the pool.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\PoolMembership;
use OCA\Dossiq\Service\Routing\Strategy\LeastLoadedStrategy;
use PHPUnit\Framework\TestCase;

/**
 * A position inside a team as a routing target.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class PositionTargetTest extends TestCase {
	/**
	 * Two teams, each with a senior and a handler.
	 *
	 * @return array<int, array<string, mixed>> The role rows.
	 */
	private function twoTeams(): array {
		return [
			['roleType' => 'senior', 'participant' => 'sanne', 'team' => 'zuid'],
			['roleType' => 'senior', 'participant' => 'mo', 'team' => 'noord'],
			['roleType' => 'behandelaar', 'participant' => 'aad', 'team' => 'zuid'],
			['roleType' => 'behandelaar', 'participant' => 'bea', 'team' => 'noord'],
		];
	}//end twoTeams()

	/**
	 * The pool a rule sees when it names a team.
	 *
	 * @return void
	 */
	public function testNamingATeamNarrowsThePoolToThatTeam(): void {
		$pool = new PoolMembership();

		$members = $pool->membersOf(roles: $this->twoTeams(), roleType: 'senior', team: 'zuid');

		$this->assertSame(['sanne'], array_column($members, 'participant'));
		// The senior of the other team is not a candidate. Without this the
		// rule could be satisfied by reaching the first senior in the list.
		$this->assertNotContains('mo', array_column($members, 'participant'));
	}//end testNamingATeamNarrowsThePoolToThatTeam()

	/**
	 * A rule naming no team keeps every member of the role, which is what
	 * every existing rule does.
	 *
	 * @return void
	 */
	public function testARuleWithNoTeamTakesTheWholePool(): void {
		$pool = new PoolMembership();

		$members = $pool->membersOf(roles: $this->twoTeams(), roleType: 'senior');

		$this->assertSame(['sanne', 'mo'], array_column($members, 'participant'));
	}//end testARuleWithNoTeamTakesTheWholePool()

	/**
	 * And the strategies route to the narrowed pool.
	 *
	 * @return void
	 */
	public function testAStrategyRoutesToThePositionInThatTeam(): void {
		$strategy = new LeastLoadedStrategy(new PoolMembership());
		$case = ['openTaskCountsByParticipant' => ['sanne' => 9, 'mo' => 0]];

		// Mo holds nothing and is still not chosen: the team decided the pool
		// before the load decided the member.
		$this->assertSame(
			['sanne'],
			$strategy->resolve(['roleType' => 'senior', 'team' => 'zuid'], $case, $this->twoTeams())
		);
	}//end testAStrategyRoutesToThePositionInThatTeam()

	/**
	 * A team nobody is in routes to nobody, rather than silently widening to
	 * the whole organisation.
	 *
	 * @return void
	 */
	public function testAnEmptyTeamRoutesToNobody(): void {
		$pool = new PoolMembership();

		$this->assertSame([], $pool->membersOf(roles: $this->twoTeams(), roleType: 'senior', team: 'oost'));
	}//end testAnEmptyTeamRoutesToNobody()
}//end class
