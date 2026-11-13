<?php

/**
 * Contract of CaseCompleteness
 *
 * The public surface of CaseCompleteness that other components call, so they depend
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
 * Contract of CaseCompleteness.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseCompletenessInterface {

	/**
	 * The completeness answer of one case for one reader, who must already be allowed to read it.
	 *
	 * @param string $caseId The case uuid.
	 * @param IUser  $user   The reader.
	 *
	 * @return array<string, mixed>|null The answer (see check()), or null when the case is not found.
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function forCase(string $caseId, IUser $user): ?array;
}//end interface
