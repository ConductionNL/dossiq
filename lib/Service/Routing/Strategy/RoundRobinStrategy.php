<?php

/**
 * Dossiq Round-Robin Routing Strategy
 *
 * Rotates across the participants of `roleType` for the case's caseType. The
 * cursor is persisted in IAppConfig under
 * `routing.rr.<caseTypeUuid>.<roleTypeUuid>` so rotation is stable across
 * worker processes and PHP-FPM restarts.
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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Routing\PoolMembership;
use OCA\Dossiq\Service\Routing\RoutingStrategyInterface;
use OCP\IAppConfig;

/**
 * Round-robin strategy.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class RoundRobinStrategy implements RoutingStrategyInterface {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Persists the per-(caseType, roleType) cursor
	 * @param PoolMembership $pool Reads the pool, its weights and its teams
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
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
		return 'round-robin';
	}//end name()

	/**
	 * Pick the next participant in rotation; advance and persist the cursor.
	 *
	 * Returns a single-element array. When no participants match the rule's
	 * `roleType` for the case, returns an empty array.
	 *
	 * @param array<string, mixed> $rule The routing rule
	 * @param array<string, mixed> $case The case object
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

		// A rule may narrow the pool to one team, which is how the senior
		// handler OF TEAM ZUID is named without an organisation-wide role for
		// every senior of every team (REQ-RTP-02).
		$team = trim((string)($rule['team'] ?? ''));
		$members = $this->pool->membersOf(roles: $roles, roleType: $target, team: $team);

		// The rotation is the pool expanded by weight, and it is the
		// participants UNCHANGED when nobody declared one. That identity is
		// what makes an existing pool route exactly as it did yesterday
		// (REQ-RTP-01).
		$participants = $this->pool->rotation(members: $members);
		$count = count($participants);
		if ($count === 0) {
			return [];
		}

		$caseType = (string)($case['caseType'] ?? '');
		// The cursor is keyed by the TEAM as well, because two rules over one
		// roleType in two teams are two rotations; one cursor between them
		// would make each rule skip the other's turn.
		$key = sprintf('routing.rr.%s.%s%s', $caseType, $target, ($team === '' ? '' : '.' . $team));
		$cursor = (int)$this->appConfig->getValueInt(
			Application::APP_ID,
			$key,
			0,
		);

		$pick = $participants[$cursor % $count];
		$this->appConfig->setValueInt(
			Application::APP_ID,
			$key,
			(($cursor + 1) % max($count, 1)),
		);

		return [$pick];
	}//end resolve()
}//end class
