<?php

/**
 * Contract of CaseAttentionFlagService
 *
 * The public surface of CaseAttentionFlagService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of CaseAttentionFlagService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseAttentionFlagServiceInterface {

	/**
	 * Clear the flag on this case, with a reason and a name.
	 *
	 * Clearing does not delete the raising: it appends a second row. That is
	 * the whole of design D-2, and it is why this method never unsets
	 * `attentionFlagHistory`.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId Who is clearing it.
	 * @param string $reason Why it no longer needs attention.
	 *
	 * @return array{caseId: string, raised: bool, flag: array<string, mixed>,
	 *               history: array<int, array<string, mixed>>, raisings: int,
	 *               clearings: int} The flag, its history and the two counts.
	 *
	 * @throws RuntimeException With code `case_not_found`, `reason_required` or `not_raised`.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function clear(string $caseId, string $userId, string $reason): array;

	/**
	 * Raise the flag on this case, with a reason and a name.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId Who is raising it.
	 * @param string $reason Why the case needs attention.
	 *
	 * @return array{caseId: string, raised: bool, flag: array<string, mixed>,
	 *               history: array<int, array<string, mixed>>, raisings: int,
	 *               clearings: int} The flag, its history and the two counts.
	 *
	 * @throws RuntimeException With code `case_not_found`, `reason_required` or `already_raised`.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function raise(string $caseId, string $userId, string $reason): array;

	/**
	 * Where the flag on this case stands, and what it has been through.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{caseId: string, raised: bool, flag: array<string, mixed>,
	 *               history: array<int, array<string, mixed>>, raisings: int,
	 *               clearings: int} The flag, its history and the two counts.
	 *
	 * @throws RuntimeException With code `case_not_found`.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function state(string $caseId): array;
}//end interface
