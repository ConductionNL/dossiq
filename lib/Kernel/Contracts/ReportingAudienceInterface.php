<?php

/**
 * Contract of ReportingAudience
 *
 * The public surface of ReportingAudience that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of ReportingAudience.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportingAudienceInterface {

	/**
	 * The sentence a refused caller reads.
	 */
	public const REFUSAL = 'This report covers every case, so it is for the controller and beheerder roles.';

	/**
	 * Whether this caller is in the audience for a fleet-wide report.
	 *
	 * It reads GROUP MEMBERSHIP and never an object grant, which is why it is
	 * not named `mayRead`: a report is about the fleet and has no object whose
	 * grants could answer, and a method with an evaluator's name here reads as
	 * a second answer to a question OpenRegister owns.
	 *
	 * @param IUser|null $user The caller, null for no session.
	 *
	 * @return bool True when they hold one of the roles.
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function isInAudience(?IUser $user): bool;
}//end interface
