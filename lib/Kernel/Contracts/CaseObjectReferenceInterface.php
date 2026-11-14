<?php

/**
 * Contract of CaseObjectReference
 *
 * The public surface of CaseObjectReference that other components call, so they depend
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
 * Contract of CaseObjectReference.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseObjectReferenceInterface {

	/**
	 * Whether the reference names a dossiq case.
	 *
	 * @param string|null $register The register, as the other app gave it.
	 * @param string|null $schema   The schema, as the other app gave it.
	 *
	 * @return bool True when it is a case in dossiq's register.
	 *
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function isCase(?string $register, ?string $schema): bool;
}//end interface
