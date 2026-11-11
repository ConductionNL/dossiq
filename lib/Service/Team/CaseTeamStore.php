<?php

/**
 * Where the team migration reads and writes: OpenRegister, the register and the case and role schemas.
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
 * The configured storage the team migration walks.
 *
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */
final class CaseTeamStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register.
	 * @param string $caseSchema    The case schema.
	 * @param string $roleSchema    The organisation role schema, or ''.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $caseSchema,
		public readonly string $roleSchema,
	) {
	}//end __construct()
}//end class
