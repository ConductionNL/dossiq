<?php

/**
 * Contract of SubstitutionAuditService
 *
 * The public surface of SubstitutionAuditService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of SubstitutionAuditService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubstitutionAuditServiceInterface {

	/**
	 * List every capacity-stamped action performed under a substitution.
	 *
	 * Scans the absentee's cases (the population a substitution can touch) and
	 * collects the activity entries that carry the given substitution id,
	 * sorted chronologically.
	 *
	 * @param string $substitutionId The substitution UUID.
	 *
	 * @return array<int, array<string, mixed>> The stamped actions.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function getActionsForSubstitution(string $substitutionId): array;
}//end interface
