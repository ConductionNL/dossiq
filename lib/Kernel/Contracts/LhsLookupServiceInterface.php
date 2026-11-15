<?php

/**
 * Contract of LhsLookupService
 *
 * The public surface of LhsLookupService that other components call, so they depend
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
 * Contract of LhsLookupService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LhsLookupServiceInterface {

	/**
	 * Look up the recommended intervention for a gedrag + gevolg combination.
	 *
	 * @param string $behaviour Behaviour axis: A, B, C, or D
	 * @param string $gevolg Impact axis: 1, 2, 3, or 4
	 *
	 * @return array<string, mixed> Cell data with interventieStep and description
	 *
	 * @throws RuntimeException If gedrag or gevolg are invalid
	 *
	 * @spec openspec/changes/archive/2026-06-13-vth-module/tasks.md#task-8
	 */
	public function lookup(string $behaviour, string $gevolg): array;
}//end interface
