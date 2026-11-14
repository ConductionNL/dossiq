<?php

/**
 * Contract of CaseCopyService
 *
 * The public surface of CaseCopyService that other components call, so they depend
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
 * Contract of CaseCopyService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseCopyServiceInterface {

	/**
	 * Copy a case.
	 *
	 * @param string $caseId The source case's OpenRegister id.
	 * @param array{title?: string, documents?: bool} $options The copy's title, and whether to link the source's documents.
	 *
	 * @return array<string, mixed> The new case.
	 *
	 * @throws RuntimeException `storage_unavailable` when OpenRegister is absent
	 *                          or unconfigured, `case_not_found` when the source
	 *                          does not resolve, `copy_failed` when the write is
	 *                          refused.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function copy(string $caseId, array $options = []): array;
}//end interface
