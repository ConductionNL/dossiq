<?php

/**
 * Contract of ProcessingTimeReportService
 *
 * The public surface of ProcessingTimeReportService that other components call, so they depend
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
 * Contract of ProcessingTimeReportService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProcessingTimeReportServiceInterface {

	/**
	 * The blocks a `view` may name.
	 *
	 * @var string[]
	 */
	public const VIEWS = ['sla', 'distribution', 'trend', 'throughput', 'atRisk', 'breakdown'];

	/**
	 * The remaining share of the target at or below which an open record is
	 * listed as at risk, unless the request names another.
	 *
	 * @var float
	 */
	public const DEFAULT_THRESHOLD = 0.25;

	/**
	 * Build the report.
	 *
	 * @param string      $period    A {@see ReportPeriod::PRESETS} key.
	 * @param string|null $caseType  A record type id or slug to narrow to, or null.
	 * @param string|null $view      One of {@see self::VIEWS}, or null for every block.
	 * @param float       $threshold The at-risk threshold, 0 to 1.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function getReport(string $period, ?string $caseType, ?string $view, float $threshold): array;
}//end interface
