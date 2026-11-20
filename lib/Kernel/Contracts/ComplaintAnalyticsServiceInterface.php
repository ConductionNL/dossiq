<?php

/**
 * Contract of ComplaintAnalyticsService
 *
 * The public surface of ComplaintAnalyticsService that other components call, so they depend
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
 * Contract of ComplaintAnalyticsService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplaintAnalyticsServiceInterface {

	/**
	 * Check for employees referenced in >= 3 complaints in the last 6 months.
	 *
	 * @return array<int, array<string, mixed>> Anonymized alert records (employee reference redacted)
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function checkEmployeeThresholdAlerts(): array;

	/**
	 * Compute average resolution time (in days) by category.
	 *
	 * @param string $dateFrom ISO date (Y-m-d)
	 * @param string $dateTo ISO date (Y-m-d)
	 *
	 * @return array<string, float> Map of categorie => average days to resolve
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getAverageResolutionTime(string $dateFrom, string $dateTo): array;

	/**
	 * Aggregate complaint frequency by a given dimension for a date range.
	 *
	 * @param string $dimension Grouping dimension: 'category', 'involvedDepartment', 'receiptChannel'
	 * @param string $dateFrom ISO date string (Y-m-d) for range start
	 * @param string $dateTo ISO date string (Y-m-d) for range end
	 *
	 * @return array<string, int> Map of dimension value => complaint count
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getFrequencyByDimension(string $dimension, string $dateFrom, string $dateTo): array;

	/**
	 * Get KPI summary for management dashboard.
	 *
	 * @param string $dateFrom ISO date
	 * @param string $dateTo ISO date
	 *
	 * @return array<string, mixed> KPI summary
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getKpiSummary(string $dateFrom, string $dateTo): array;

	/**
	 * Get monthly complaint trend for a date range.
	 *
	 * @param string $dateFrom ISO date string (Y-m-d)
	 * @param string $dateTo ISO date string (Y-m-d)
	 *
	 * @return array<string, int> Map of 'YYYY-MM' => complaint count
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-05
	 */
	public function getMonthlyTrend(string $dateFrom, string $dateTo): array;
}//end interface
