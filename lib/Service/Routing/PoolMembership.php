<?php

/**
 * Who is in the pool, how large a share each takes, and which team they are in.
 *
 * 🔑 THE WEIGHT IS ON THE MEMBERSHIP, NOT ON THE PERSON (D-1). The same
 * handler may carry a full share of intake and a quarter share of objections.
 * One number on the person would be wrong for one of the two, and an
 * administrator could not defend either.
 *
 * 🔴 AN UNWEIGHTED POOL MUST ROUTE EXACTLY AS IT DID BEFORE WEIGHTS EXISTED.
 * Every gemeente on this app has pools with no weights on them, and a rounding
 * difference in the sequence would reshuffle a caseload with nothing to say
 * why. That is why the expansion below returns the participants UNCHANGED when
 * every weight is one, rather than returning a sequence that happens to be
 * equivalent.
 *
 * 🔴 A WEIGHT OF ZERO IS A MEMBER WHO TAKES NO NEW WORK, NOT A MEMBER WHO IS
 * GONE. They keep their cases and stay in the pool; they are simply not
 * offered the next one. A pool where EVERY weight is zero routes to nobody,
 * which is a configuration a caller must surface rather than one this class
 * quietly rescues by handing the work to somebody anyway.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing;

/**
 * Reads a routing pool out of the role rows bound to a case.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class PoolMembership {
	/**
	 * The weight a membership takes when it declares none.
	 *
	 * @var float
	 */
	public const DEFAULT_WEIGHT = 1.0;

	/**
	 * How many slots one member may take in an expanded rotation.
	 *
	 * A cap, because a weight is a share and not a queue length: a pool of
	 * 1 and 0.001 would otherwise expand to a thousand slots and the rotation
	 * would take a thousand cases to come back round.
	 *
	 * @var int
	 */
	private const MAX_SLOTS_PER_MEMBER = 20;

	/**
	 * The members of a pool, in the order the roles were bound.
	 *
	 * A rule may narrow the pool to one team, which is how a position INSIDE
	 * a team is named without an organisation-wide role for it (D-3). A rule
	 * that names no team takes the whole pool, which is today's behaviour.
	 *
	 * @param array<int, array<string, mixed>> $roles The role rows bound to the case.
	 * @param string $roleType The roleType the rule targets.
	 * @param string $team The team the rule narrows to, or '' for all of them.
	 *
	 * @return array<int, array{participant: string, weight: float, team: string}>
	 *         The pool, deduplicated by participant, first binding winning.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-a-rule-may-target-a-position-inside-a-team-req-rtp-02
	 */
	public function membersOf(array $roles, string $roleType, string $team = ''): array {
		if ($roleType === '') {
			return [];
		}

		$members = [];
		$seen = [];
		foreach ($roles as $role) {
			if (is_array($role) === false || (string)($role['roleType'] ?? '') !== $roleType) {
				continue;
			}

			$participant = (string)($role['participant'] ?? '');
			if ($participant === '' || isset($seen[$participant]) === true) {
				continue;
			}

			$memberTeam = trim((string)($role['team'] ?? ''));
			if ($team !== '' && $memberTeam !== $team) {
				continue;
			}

			$seen[$participant] = true;
			$members[] = [
				'participant' => $participant,
				'weight' => $this->weightOf(role: $role),
				'team' => $memberTeam,
			];
		}

		return $members;
	}//end membersOf()

	/**
	 * The weight one membership declares.
	 *
	 * A missing, non-numeric or negative weight reads as one. A pool row that
	 * somebody typed `zwaar` into must route as it did yesterday rather than
	 * dropping its member out of the rotation, which is what a cast to float
	 * would quietly do.
	 *
	 * @param array<string, mixed> $role One role row.
	 *
	 * @return float The weight, never negative.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-a-pool-member-carries-a-weight-the-strategies-read-req-rtp-01
	 */
	public function weightOf(array $role): float {
		$raw = ($role['weight'] ?? null);
		if (is_numeric($raw) === false) {
			return self::DEFAULT_WEIGHT;
		}

		$weight = (float)$raw;

		return ($weight < 0.0 ? self::DEFAULT_WEIGHT : $weight);
	}//end weightOf()

	/**
	 * Whether every member of this pool carries the default weight.
	 *
	 * @param array<int, array{participant: string, weight: float, team: string}> $members The pool.
	 *
	 * @return bool True when no weight was declared anywhere.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-a-pool-member-carries-a-weight-the-strategies-read-req-rtp-01
	 */
	public function isUnweighted(array $members): bool {
		foreach ($members as $member) {
			if (abs(($member['weight'] - self::DEFAULT_WEIGHT)) > 0.0001) {
				return false;
			}
		}

		return true;
	}//end isUnweighted()

	/**
	 * The rotation a weighted pool turns through.
	 *
	 * One entry per share, so a pool of 1 and 0.4 turns through five slots,
	 * five of every seven going to the first member. The sequence INTERLEAVES
	 * rather than blocking: `a, a, b, a` and not `a, a, a, b`, because a
	 * handler who receives three cases in a row and then none for a week
	 * experiences that as broken even when the totals are right.
	 *
	 * An unweighted pool returns its participants unchanged, which is what
	 * makes "a pool whose weights are all one is routed exactly as it is
	 * today" a fact rather than a hope.
	 *
	 * @param array<int, array{participant: string, weight: float, team: string}> $members The pool.
	 *
	 * @return array<int, string> The rotation, empty when nobody may take work.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-a-pool-member-carries-a-weight-the-strategies-read-req-rtp-01
	 */
	public function rotation(array $members): array {
		if ($members === []) {
			return [];
		}

		if ($this->isUnweighted(members: $members) === true) {
			return array_map(static fn (array $member): string => $member['participant'], $members);
		}

		$smallest = null;
		foreach ($members as $member) {
			if ($member['weight'] <= 0.0) {
				continue;
			}

			if ($smallest === null || $member['weight'] < $smallest) {
				$smallest = $member['weight'];
			}
		}

		if ($smallest === null) {
			// Every member is at zero: nobody in this pool takes new work.
			// Answered as an empty rotation so the caller surfaces it, the
			// way it already surfaces a pool with no members.
			return [];
		}

		$slots = [];
		foreach ($members as $member) {
			$count = (int)max(0, min(self::MAX_SLOTS_PER_MEMBER, (int)round(($member['weight'] / $smallest))));
			if ($count > 0) {
				$slots[$member['participant']] = $count;
			}
		}

		$rotation = [];
		while ($slots !== []) {
			foreach ($slots as $participant => $remaining) {
				$rotation[] = (string)$participant;
				$remaining--;
				if ($remaining === 0) {
					unset($slots[$participant]);
					continue;
				}

				$slots[$participant] = $remaining;
			}
		}

		return $rotation;
	}//end rotation()

	/**
	 * The load one member carries per unit of weight.
	 *
	 * This is what makes least loaded read a weight as CAPACITY rather than as
	 * a tie-break: a member at weight 2 holding six cases is less loaded than
	 * a member at weight 1 holding four, and a strategy comparing raw counts
	 * gets that backwards every time.
	 *
	 * @param float $count How many open items the member holds.
	 * @param float $weight The member's weight.
	 *
	 * @return float The relative load; INF for a member who takes no new work.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-a-pool-member-carries-a-weight-the-strategies-read-req-rtp-01
	 */
	public function relativeLoad(float $count, float $weight): float {
		if ($weight <= 0.0) {
			return INF;
		}

		return ($count / $weight);
	}//end relativeLoad()
}//end class
