<?php

/**
 * Contract of DoorlooptijdService
 *
 * The public surface of DoorlooptijdService that other components call, so they depend
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
 * Contract of DoorlooptijdService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DoorlooptijdServiceInterface {

	/**
	 * Compute the full metrics payload for the dashboard.
	 *
	 * @param array<string, mixed> $params Query parameters from the controller.
	 *
	 * @return array<string, mixed> The structured response body.
	 *
	 * @spec openspec/changes/archive/2026-06-13-doorlooptijd-dashboard/tasks.md#T01
	 */
	public function getMetrics(array $params): array;

	/**
	 * The processing-time figures per case type and month, for a download.
	 *
	 * Reads the same cases as {@see getMetrics()}: the same case type
	 * filter and the same period, so a download holds what the page shows.
	 *
	 * @param array<string, mixed> $params `caseType` (optional) and `period` (`12m`).
	 *
	 * @return list<array{caseType: string, month: string, closed: int, avgDays: int|null, withinNormPercent: int|null}>
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
	 */
	public function getMonthlyRows(array $params): array;
}//end interface
