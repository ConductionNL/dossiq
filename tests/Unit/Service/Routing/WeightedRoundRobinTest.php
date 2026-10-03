<?php

/**
 * Round robin, in proportion to what each member is actually there for.
 *
 * 🔴 THE PARITY ASSERTION IS THE IMPORTANT ONE. Every gemeente on this app has
 * pools with no weights on them, and a rotation that merely HAPPENED to be
 * equivalent for equal weights would reshuffle a caseload the day it shipped,
 * with nothing to say why. So the unweighted pool is asserted to produce the
 * participants unchanged, not merely a fair result.
 *
 * 🔴 THE SHARE IS COUNTED OVER A FULL CYCLE, NOT AT ONE POSITION. Asserting
 * "the third case goes to Bea" pins an implementation; asserting "over
 * fourteen cases Aad gets ten and Bea four" pins the requirement, and it is
 * what the scenario says.
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
use OCA\Dossiq\Service\Routing\Strategy\RoundRobinStrategy;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The weighted rotation.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class WeightedRoundRobinTest extends TestCase {
	/**
	 * An app config that remembers, so a rotation can be turned through.
	 *
	 * @return IAppConfig The double.
	 */
	private function rememberingConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$store = [];

		$config->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0) use (&$store): int {
				return ($store[$key] ?? $default);
			}
		);
		$config->method('setValueInt')->willReturnCallback(
			static function (string $app, string $key, int $value) use (&$store): bool {
				$store[$key] = $value;

				return true;
			}
		);

		return $config;
	}//end rememberingConfig()

	/**
	 * One role row.
	 *
	 * @param string $participant The member.
	 * @param float|null $weight Their weight, or null to declare none.
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
	 * Route a number of cases and tally who got them.
	 *
	 * @param array<int, array<string, mixed>> $roles The pool.
	 * @param int $cases How many to route.
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return array<string, int> The tally.
	 */
	private function tally(array $roles, int $cases, array $rule = ['roleType' => 'behandelaar']): array {
		$strategy = new RoundRobinStrategy($this->rememberingConfig(), new PoolMembership());
		$tally = [];
		for ($i = 0; $i < $cases; $i++) {
			$picked = $strategy->resolve($rule, ['caseType' => 'ct-1'], $roles);
			foreach ($picked as $participant) {
				$tally[$participant] = (($tally[$participant] ?? 0) + 1);
			}
		}

		return $tally;
	}//end tally()

	/**
	 * A part-time colleague gets a smaller share, over a full cycle.
	 *
	 * @return void
	 */
	public function testAPartTimeMemberGetsASmallerShare(): void {
		$roles = [$this->member('aad', 1.0), $this->member('bea', 0.4)];

		// Five slots per cycle, two full cycles plus a partial: what the
		// requirement asks is the proportion, and 10/4 over fourteen is it.
		$this->assertSame(['aad' => 10, 'bea' => 4], $this->tally($roles, 14));
	}//end testAPartTimeMemberGetsASmallerShare()

	/**
	 * A pool nobody weighted routes exactly as it did before weights existed.
	 *
	 * @return void
	 */
	public function testAnUnweightedPoolIsUnchanged(): void {
		$pool = new PoolMembership();
		$members = $pool->membersOf(
			roles: [$this->member('aad'), $this->member('bea'), $this->member('cor')],
			roleType: 'behandelaar',
		);

		// The participants, in binding order, not a sequence that happens to
		// be equivalent.
		$this->assertSame(['aad', 'bea', 'cor'], $pool->rotation(members: $members));
		$this->assertSame(
			['aad' => 2, 'bea' => 2, 'cor' => 2],
			$this->tally([$this->member('aad'), $this->member('bea'), $this->member('cor')], 6)
		);
	}//end testAnUnweightedPoolIsUnchanged()

	/**
	 * A pool where everyone was explicitly given weight one is the same pool.
	 *
	 * @return void
	 */
	public function testWeightsOfOneAreTheSameAsNoWeights(): void {
		$pool = new PoolMembership();
		$explicit = $pool->membersOf(
			roles: [$this->member('aad', 1.0), $this->member('bea', 1.0)],
			roleType: 'behandelaar',
		);

		$this->assertSame(['aad', 'bea'], $pool->rotation(members: $explicit));
	}//end testWeightsOfOneAreTheSameAsNoWeights()

	/**
	 * The rotation interleaves, because three in a row and then a week of
	 * nothing reads as broken even when the totals are right.
	 *
	 * @return void
	 */
	public function testTheRotationInterleavesRatherThanBlocking(): void {
		$pool = new PoolMembership();
		$members = $pool->membersOf(
			roles: [$this->member('aad', 3.0), $this->member('bea', 1.0)],
			roleType: 'behandelaar',
		);

		$this->assertSame(['aad', 'bea', 'aad', 'aad'], $pool->rotation(members: $members));
	}//end testTheRotationInterleavesRatherThanBlocking()

	/**
	 * Weight zero keeps a member in the pool and out of the rotation.
	 *
	 * @return void
	 */
	public function testAMemberAtZeroTakesNoNewWork(): void {
		$tally = $this->tally([$this->member('aad', 1.0), $this->member('bea', 0.0)], 4);

		$this->assertSame(['aad' => 4], $tally);
	}//end testAMemberAtZeroTakesNoNewWork()

	/**
	 * A pool where everybody is at zero routes to nobody, rather than being
	 * quietly rescued by handing the work to somebody anyway.
	 *
	 * @return void
	 */
	public function testAPoolEntirelyAtZeroRoutesToNobody(): void {
		$this->assertSame([], $this->tally([$this->member('aad', 0.0), $this->member('bea', 0.0)], 3));
	}//end testAPoolEntirelyAtZeroRoutesToNobody()

	/**
	 * A weight somebody typed a word into routes as it did yesterday rather
	 * than dropping its member out of the pool.
	 *
	 * @return void
	 */
	public function testAMalformedWeightReadsAsOne(): void {
		$pool = new PoolMembership();

		$this->assertSame(1.0, $pool->weightOf(['weight' => 'zwaar']));
		$this->assertSame(1.0, $pool->weightOf(['weight' => -2]));
		$this->assertSame(1.0, $pool->weightOf([]));
		$this->assertSame(0.0, $pool->weightOf(['weight' => 0]));
	}//end testAMalformedWeightReadsAsOne()
}//end class
