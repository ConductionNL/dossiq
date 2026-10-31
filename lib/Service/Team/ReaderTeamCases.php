<?php

/**
 * Dossiq reader team cases.
 *
 * The open cases of the reader's teams, as the team queue's `@myTeam` filter
 * reads them: a reader in no team sees every case, as the list then drops its
 * team filter.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Team
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Team;

use OCP\IUserManager;

/**
 * Narrows open cases to the reader's teams.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams
 */
class ReaderTeamCases {

	/**
	 * Constructor.
	 *
	 * @param PrimaryTeamService $teams The reader's teams.
	 * @param IUserManager       $users Finds the reader by id.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PrimaryTeamService $teams,
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * The cases of the reader's teams; every case for a reader in no team.
	 *
	 * @param string            $userId The reader.
	 * @param array<int, mixed> $cases  The open cases.
	 *
	 * @return array<int, mixed> The cases in the reader's teams.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams
	 */
	public function casesOf(string $userId, array $cases): array {
		$user = $this->users->get($userId);
		if ($user === null) {
			return $cases;
		}

		return self::inTeams(cases: $cases, teamGroupIds: array_column($this->teams->teamsOf(user: $user), 'groupId'));
	}//end casesOf()

	/**
	 * The cases of the given teams; every case when no team is given.
	 *
	 * @param array<int, mixed>  $cases        The open cases.
	 * @param array<int, string> $teamGroupIds The team group ids.
	 *
	 * @return array<int, mixed> The cases in those teams.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams
	 */
	public static function inTeams(array $cases, array $teamGroupIds): array {
		if ($teamGroupIds === []) {
			return $cases;
		}

		return array_values(
			array_filter(
				$cases,
				static fn ($case): bool => is_array($case) === true
					&& in_array((string)($case['assignedGroup'] ?? ''), $teamGroupIds, true) === true
			)
		);
	}//end inTeams()
}//end class
