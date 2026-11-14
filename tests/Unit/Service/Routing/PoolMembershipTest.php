<?php

/**
 * Unit tests for PoolMembership: a pool is the distinct holders of one role
 * type, optionally of one team, each with a weight that defaults to one; load
 * is relative to that weight and a member at zero carries infinite load.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\PoolMembership;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Routing\PoolMembership
 */
class PoolMembershipTest extends TestCase {

	public function testMembersAreTheDistinctHoldersOfTheRoleType(): void {
		$roles = [
			['roleType' => 'behandelaar', 'participant' => 'alice', 'weight' => '2', 'team' => ' zuid '],
			['roleType' => 'behandelaar', 'participant' => 'alice', 'weight' => 5],
			['roleType' => 'teamleider', 'participant' => 'bob'],
			['roleType' => 'behandelaar', 'participant' => ''],
			'not a role',
			['roleType' => 'behandelaar', 'participant' => 'carol'],
		];

		$this->assertSame(
			[
				['participant' => 'alice', 'weight' => 2.0, 'team' => 'zuid'],
				['participant' => 'carol', 'weight' => 1.0, 'team' => ''],
			],
			(new PoolMembership())->membersOf($roles, 'behandelaar')
		);
	}//end testMembersAreTheDistinctHoldersOfTheRoleType()

	public function testATeamNarrowsThePool(): void {
		$roles = [
			['roleType' => 'behandelaar', 'participant' => 'alice', 'team' => 'zuid'],
			['roleType' => 'behandelaar', 'participant' => 'bob', 'team' => 'noord'],
			['roleType' => 'behandelaar', 'participant' => 'carol'],
		];
		$pool = new PoolMembership();

		$this->assertSame(['alice'], array_column($pool->membersOf($roles, 'behandelaar', 'zuid'), 'participant'));
		$this->assertSame([], $pool->membersOf($roles, 'behandelaar', 'west'));
		$this->assertSame([], $pool->membersOf($roles, ''));
	}//end testATeamNarrowsThePool()

	public function testAWeightIsANonNegativeNumberOrOne(): void {
		$pool = new PoolMembership();

		$this->assertSame(0.5, $pool->weightOf(['weight' => '0.5']));
		$this->assertSame(0.0, $pool->weightOf(['weight' => 0]));
		$this->assertSame(1.0, $pool->weightOf(['weight' => -2]));
		$this->assertSame(1.0, $pool->weightOf(['weight' => 'full']));
		$this->assertSame(1.0, $pool->weightOf([]));
	}//end testAWeightIsANonNegativeNumberOrOne()

	public function testAPoolIsUnweightedOnlyWhenEveryWeightIsOne(): void {
		$pool = new PoolMembership();

		$this->assertTrue($pool->isUnweighted([]));
		$this->assertTrue($pool->isUnweighted([['participant' => 'a', 'weight' => 1.0], ['participant' => 'b', 'weight' => 1.00001]]));
		$this->assertFalse($pool->isUnweighted([['participant' => 'a', 'weight' => 1.0], ['participant' => 'b', 'weight' => 0.5]]));
	}//end testAPoolIsUnweightedOnlyWhenEveryWeightIsOne()

	public function testLoadIsRelativeToWeightAndInfiniteAtZero(): void {
		$pool = new PoolMembership();

		$this->assertSame(3.0, $pool->relativeLoad(6.0, 2.0));
		$this->assertSame(4.0, $pool->relativeLoad(4.0, 1.0));
		$this->assertSame(INF, $pool->relativeLoad(1.0, 0.0));
		$this->assertSame(INF, $pool->relativeLoad(0.0, -1.0));
	}//end testLoadIsRelativeToWeightAndInfiniteAtZero()

	public function testARotationCapsAMembersShare(): void {
		$rotation = (new PoolMembership())->rotation([['participant' => 'a', 'weight' => 100.0], ['participant' => 'b', 'weight' => 1.0]]);

		$this->assertSame(20, count(array_keys($rotation, 'a', true)));
		$this->assertSame(['a', 'b'], array_slice($rotation, 0, 2));
		$this->assertCount(21, $rotation);
	}//end testARotationCapsAMembersShare()
}//end class
