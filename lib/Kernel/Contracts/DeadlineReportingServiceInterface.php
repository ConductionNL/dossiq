<?php

/**
 * Contract of DeadlineReportingService
 *
 * The public surface of DeadlineReportingService that other components call, so they depend
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

/**
 * Contract of DeadlineReportingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DeadlineReportingServiceInterface {

	/**
	 * Generate an annual dwangsom audit report.
	 *
	 * @param int $year Year.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function generateDwangsomAuditReport(int $year): array;

	/**
	 * Generate a quarterly KPI report.
	 *
	 * @param string $period Period (YYYY-Qn, e.g. "2026-Q2").
	 * @param string|null $department Optional department filter.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function generateQuarterlyReport(string $period, ?string $department = null): array;

	/**
	 * Compute a snapshot KPI summary for the dashboard widget.
	 *
	 * @param array<string, mixed>   $filters Optional filters (afdeling, zaaktype).
	 * @param DateTimeImmutable|null $today   The day the running figures are read on; now when absent.
	 * @param string|null            $from    First day (YYYY-MM-DD) of the window the share per case type covers; the current quarter when absent.
	 * @param string|null            $until   Last day (YYYY-MM-DD) of that window.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function getTermijnKpi(array $filters = [], ?DateTimeImmutable $today = null, ?string $from = null, ?string $until = null): array;
}//end interface
