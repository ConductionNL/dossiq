<?php

/**
 * Contract of PersonalStageService
 *
 * The public surface of PersonalStageService that other components call, so they depend
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
 * Contract of PersonalStageService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PersonalStageServiceInterface {

	/**
	 * This person's stage on one case.
	 *
	 * @param string $userId The reader. Their own, always: there is no
	 *                       parameter anywhere that reads somebody else's.
	 * @param string $caseId The case.
	 *
	 * @return string The stage, or an empty string when they set none.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function get(string $userId, string $caseId): string;

	/**
	 * Set, or clear, this person's stage on one case.
	 *
	 * @param string $userId The reader.
	 * @param string $caseId The case.
	 * @param string $stage  The stage, or an empty string to clear it.
	 *
	 * @return string The stage now stored.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function set(string $userId, string $caseId, string $stage): string;
}//end interface
