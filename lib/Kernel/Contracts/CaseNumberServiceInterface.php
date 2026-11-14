<?php

/**
 * Contract of CaseNumberService
 *
 * The public surface of CaseNumberService that other components call, so they depend
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
 * Contract of CaseNumberService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseNumberServiceInterface {

	/**
	 * Give a case its number, unless it already has one.
	 *
	 * @param array<string, mixed> $case The case as OpenRegister just stored it.
	 *
	 * @return string The number the case now holds, or an empty string when
	 *                nothing was written (it already had one, or the register
	 *                could not be read).
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function assign(array $case): string;
}//end interface
