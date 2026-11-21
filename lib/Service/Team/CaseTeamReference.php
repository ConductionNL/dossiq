<?php

/**
 * The team one case names, and the organisation role that value is.
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
 * One case's team reference, before it is converted or reported.
 *
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */
final class CaseTeamReference {
	/**
	 * Constructor.
	 *
	 * @param string                    $caseId The case.
	 * @param array<string, mixed>      $case   The stored case.
	 * @param string                    $value  The team value it holds.
	 * @param array<string, mixed>|null $role   The organisation role the value names, or null.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly array $case,
		public readonly string $value,
		public readonly ?array $role,
	) {
	}//end __construct()

	/**
	 * The group the role names, or '' when it names none or there is no role.
	 *
	 * @return string The group id.
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function group(): string {
		if ($this->role === null) {
			return '';
		}

		return trim((string)($this->role['ncGroupId'] ?? ''));
	}//end group()

	/**
	 * Why the case cannot be converted: no such role, a role without a group, or a group that is gone.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function reason(): string {
		if ($this->role === null) {
			return CaseTeamMigration::REASON_UNKNOWN;
		}

		if ($this->group() === '') {
			return CaseTeamMigration::REASON_NO_GROUP;
		}

		return CaseTeamMigration::REASON_GROUP_MISSING;
	}//end reason()
}//end class
