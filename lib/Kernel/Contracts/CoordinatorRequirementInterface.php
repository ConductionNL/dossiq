<?php

/**
 * Contract of CoordinatorRequirement
 *
 * The public surface of CoordinatorRequirement that other components call, so they depend
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
 * Contract of CoordinatorRequirement.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CoordinatorRequirementInterface {

	/**
	 * Whether this case's type asks for a coordinator before signing.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return bool True when the case type declares the requirement.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-type-may-require-a-coordinator-before-signing-req-hand-06
	 */
	public function applies(string $caseId): bool;
}//end interface
