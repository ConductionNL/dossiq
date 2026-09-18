<?php

/**
 * Least loaded, where a weight is capacity rather than a tie-break.
 *
 * 🔴 THE ASSERTION THAT MATTERS IS THE ONE THAT GOES THE OTHER WAY. A member
 * at weight 2 holding SIX cases is less loaded than a member at weight 1
 * holding four, and a strategy comparing raw counts picks the wrong one every
 * time while looking like it is working. That case is asserted first.
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
 * The weighted load comparison.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class WeightedLeastLoadedTest extends TestCase {
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
	 * One role row.
	 *
	 * @param string $participant The member.
	 * @param float|null $weight Their weight.
	 * @param string $team Their team.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function member(string $participant, ?float $weight = null, string $team = ''): array {
		$row = ['roleType' => 'behandelaar', 'participant' => $participant];
		if ($weight !== null) {
			$row['weight'] = $weight;
		}

		if ($team !== '') {
			$row['team'] = $team;
		}

		return $row;
	}//end member()

	/**
	 * The one the raw count gets backwards.
	 *
	 * @return void
	 */
	public function testAMemberAtDoubleWeightHoldingMoreIsStillLessLoaded(): void {
		$case = ['openTaskCountsByParticipant' => ['aad' => 6, 'bea' => 4]];
		$roles = [$this->member('aad', 2.0), $this->member('bea', 1.0)];

		$this->assertSame(['aad'], $this->strategy->resolve(['roleType' => 'behandelaar'], $case, $roles));
	}//end testAMemberAtDoubleWeightHoldingMoreIsStillLessLoaded()

	/**
	 * An unweighted pool compares counts exactly as it always has.
	 *
	 * @return void
	 */
	public function testAnUnweightedPoolIsUnchanged(): void {
		$case = ['openTaskCountsByParticipant' => ['aad' => 6, 'bea' => 4]];
		$roles = [$this->member('aad'), $this->member('bea')];

		$this->assertSame(['bea'], $this->strategy->resolve(['roleType' => 'behandelaar'], $case, $roles));
	}//end testAnUnweightedPoolIsUnchanged()

	/**
	 * A tie is still broken by binding order, which is today's behaviour.
	 *
	 * @return void
	 */
	public function testATieGoesToTheFirstBinding(): void {
		$case = ['openTaskCountsByParticipant' => ['aad' => 2, 'bea' => 2]];
		$roles = [$this->member('aad'), $this->member('bea')];

		$this->assertSame(['aad'], $this->strategy->resolve(['roleType' => 'behandelaar'], $case, $roles));
	}//end testATieGoesToTheFirstBinding()

	/**
	 * A member at zero is not offered the next case however empty their desk.
	 *
	 * @return void
	 */
	public function testAMemberAtZeroIsNotOfferedWork(): void {
		$case = ['openTaskCountsByParticipant' => ['aad' => 9, 'bea' => 0]];
		$roles = [$this->member('aad', 1.0), $this->member('bea', 0.0)];

		$this->assertSame(['aad'], $this->strategy->resolve(['roleType' => 'behandelaar'], $case, $roles));
	}//end testAMemberAtZeroIsNotOfferedWork()

	/**
	 * A pool where everybody is at zero routes to nobody.
	 *
	 * @return void
	 */
	public function testAPoolEntirelyAtZeroRoutesToNobody(): void {
		$case = ['openTaskCountsByParticipant' => []];
		$roles = [$this->member('aad', 0.0), $this->member('bea', 0.0)];

		$this->assertSame([], $this->strategy->resolve(['roleType' => 'behandelaar'], $case, $roles));
	}//end testAPoolEntirelyAtZeroRoutesToNobody()
}//end class
