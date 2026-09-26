<?php

/**
 * Dossiq Least-Loaded Routing Strategy
 *
 * Picks the participant currently holding the lowest count of open tasks.
 * Open-task counts are taken from the case's `openTaskCountsByParticipant`
 * map when supplied by the caller (RoleResolverService precomputes it once
 * per resolve pass to avoid N+1 queries).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing\Strategy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing\Strategy;

use OCA\Dossiq\Service\Routing\PoolMembership;
use OCA\Dossiq\Service\Routing\RoutingStrategyInterface;

/**
 * Least-loaded strategy.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class LeastLoadedStrategy implements RoutingStrategyInterface {
	/**
	 * Constructor.
	 *
	 * @param PoolMembership $pool Reads the pool, its weights and its teams
	 */
	public function __construct(
		private readonly PoolMembership $pool,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The strategy name.
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function name(): string {
		return 'least-loaded';
	}//end name()

	/**
	 * Return the participant with the lowest count of open tasks.
	 *
	 * Reads counts from `$case['openTaskCountsByParticipant']` (string =>
	 * int). Ties are broken by participant order (first match wins).
	 * Returns an empty array when no participants match the rule.
	 *
	 * @param array<string, mixed> $rule The routing rule
	 * @param array<string, mixed> $case The case object (must include `openTaskCountsByParticipant`)
	 * @param array<int, array<string, mixed>> $roles Roles bound to the case
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function resolve(array $rule, array $case, array $roles): array {
		$target = (string)($rule['roleType'] ?? '');
		if ($target === '') {
			return [];
		}

		$counts = $this->normaliseOpenTaskCounts(
			raw: ($case['openTaskCountsByParticipant'] ?? [])
		);

		$team = trim((string)($rule['team'] ?? ''));
		$members = $this->pool->membersOf(roles: $roles, roleType: $target, team: $team);

		$bestParticipant = null;
		$bestLoad = null;
		foreach ($members as $member) {
			$participant = $member['participant'];
			// LOAD PER UNIT OF WEIGHT, not the raw count. A member at weight 2
			// holding six is less loaded than one at weight 1 holding four,
			// and comparing counts gets exactly that case backwards. An
			// unweighted pool divides every count by one, which is the same
			// comparison this strategy has always made (REQ-RTP-01).
			$load = $this->pool->relativeLoad(
				count: (float)($counts[$participant] ?? 0),
				weight: $member['weight'],
			);

			if ($load === INF) {
				// Weight zero: in the pool, holding their cases, not offered
				// the next one.
				continue;
			}

			if ($bestLoad === null || $load < $bestLoad) {
				$bestParticipant = $participant;
				$bestLoad = $load;
			}
		}

		if ($bestParticipant === null) {
			return [];
		}

		return [$bestParticipant];
	}//end resolve()

	/**
	 * Normalise the raw open-task tally to a participant => count map.
	 *
	 * @param mixed $raw The raw `openTaskCountsByParticipant` value
	 *
	 * @return array<string, int>
	 */
	private function normaliseOpenTaskCounts(mixed $raw): array {
		$counts = [];
		if (is_array($raw) === false) {
			return $counts;
		}

		foreach ($raw as $key => $value) {
			$counts[(string)$key] = (int)$value;
		}

		return $counts;
	}//end normaliseOpenTaskCounts()
}//end class
