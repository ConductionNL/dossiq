<?php

/**
 * Contract of CaseDestructionService
 *
 * The public surface of CaseDestructionService that other components call, so they depend
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
 * Contract of CaseDestructionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDestructionServiceInterface {

	/**
	 * Destroy a deleted case, and record who decided it.
	 *
	 * @param string $caseId The case UUID.
	 * @param bool $waiveWindow Whether the caller waived the open recovery window.
	 *
	 * @return array<string, mixed> What went, and the record of the act.
	 *
	 * @throws RuntimeException One of {@see self::REFUSALS}.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the waiver
	 * itself, not a mode switch: destroying inside the recovery window is the
	 * SAME act with the window explicitly set aside, and a second method would
	 * duplicate the role check, both clocks and the record to express it.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function destroy(string $caseId, bool $waiveWindow = false): array;

	/**
	 * What a destruction would take with the case, before it runs.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The scope, the window and both clocks.
	 *
	 * @throws RuntimeException One of {@see self::REFUSALS}.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function preview(string $caseId): array;
}//end interface
