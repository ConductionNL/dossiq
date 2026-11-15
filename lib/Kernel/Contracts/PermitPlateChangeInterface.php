<?php

/**
 * Contract of PermitPlateChange
 *
 * The public surface of PermitPlateChange that other components call, so they depend
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

use OCA\Dossiq\Service\Permit\PermitChangeRefused;

/**
 * Contract of PermitPlateChange.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PermitPlateChangeInterface {

	/**
	 * Open the change case.
	 *
	 * @param string $subjectRef The resident, from the verified assertion.
	 * @param string $permitId   The permit the resident named.
	 * @param string $plate      The new plate as typed.
	 *
	 * @return array{caseId: string, identifier: string} The case opened.
	 *
	 * @throws PermitChangeRefused When the request is unusable, the permit is not the resident's
	 *                             or not in force, or the register or case type is missing.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function request(string $subjectRef, string $permitId, string $plate): array;
}//end interface
