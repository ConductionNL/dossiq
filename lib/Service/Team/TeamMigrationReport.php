<?php

/**
 * The report the team migration builds while it walks the cases.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Team
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
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Team;

/**
 * The counts and the unmapped cases of one team migration run.
 *
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */
final class TeamMigrationReport {
	/**
	 * The counts by kind: converted, alreadyGroup, failed.
	 *
	 * @var array<string, int>
	 */
	private array $counts = ['converted' => 0, 'alreadyGroup' => 0, 'failed' => 0];

	/**
	 * The cases that could not be converted.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $unmapped = [];

	/**
	 * Count one case of a kind.
	 *
	 * @param string $kind converted, alreadyGroup or failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function count(string $kind): void {
		$this->counts[$kind] += 1;
	}//end count()

	/**
	 * Report one case that could not be converted.
	 *
	 * @param array<string, mixed> $row The case, its value, the reason and the hints.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function addUnmapped(array $row): void {
		$this->unmapped[] = $row;
	}//end addUnmapped()

	/**
	 * The report as CaseTeamMigration::run answers it, for a run that ran.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function toArray(): array {
		return [
			'ran' => true,
			'skippedBecause' => '',
			'converted' => $this->counts['converted'],
			'alreadyGroup' => $this->counts['alreadyGroup'],
			'failed' => $this->counts['failed'],
			'unmapped' => $this->unmapped,
		];
	}//end toArray()
}//end class
