<?php

/**
 * One team migration run: where it writes, the roles, whether it writes, and its report.
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
 * What every case of one team migration run is sorted against.
 *
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */
final class CaseTeamSort {
	/**
	 * Constructor.
	 *
	 * @param CaseTeamStore                       $store  Where the cases are written.
	 * @param array<string, array<string, mixed>> $roles  The organisation roles by id.
	 * @param bool                                $apply  Whether to write.
	 * @param TeamMigrationReport                 $report The report being built.
	 */
	public function __construct(
		public readonly CaseTeamStore $store,
		public readonly array $roles,
		public readonly bool $apply,
		public readonly TeamMigrationReport $report,
	) {
	}//end __construct()
}//end class
