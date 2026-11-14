<?php

/**
 * Contract of ProcessMiningService
 *
 * The public surface of ProcessMiningService that other components call, so they depend
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
 * Contract of ProcessMiningService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProcessMiningServiceInterface {

	/**
	 * Compute the full process-mining report for the given parameters.
	 *
	 * @param array<string, mixed> $params Query parameters from the controller
	 *                                     (`from`, `to`, `caseType` — all optional strings).
	 *
	 * @return array<string, mixed> The structured response body.
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function getReport(array $params): array;
}//end interface
