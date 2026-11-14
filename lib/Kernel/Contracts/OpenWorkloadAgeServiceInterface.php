<?php

/**
 * Contract of OpenWorkloadAgeService
 *
 * The public surface of OpenWorkloadAgeService that other components call, so they depend
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

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of OpenWorkloadAgeService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OpenWorkloadAgeServiceInterface {

	/**
	 * The age of the open workload, grouped by the status it is sitting in.
	 *
	 * @param DateTimeImmutable|null $now The moment to count from (default now).
	 *
	 * @return array{generatedAt: string, openCases: int, truncated: bool,
	 *               perStatus: array<int, array{status: string, cases: int, averageDays: int,
	 *               medianDays: int, oldestDays: int}>}
	 *
	 * @throws RefusedException When the open cases could not be read at all.
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function report(?DateTimeImmutable $now = null): array;
}//end interface
