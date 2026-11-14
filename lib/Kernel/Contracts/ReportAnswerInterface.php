<?php

/**
 * Contract of ReportAnswer
 *
 * The public surface of ReportAnswer that other components call, so they depend
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
 * Contract of ReportAnswer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportAnswerInterface {

	/**
	 * The days per phase per case type of one statistic as CSV: a column per
	 * phase, a row per case type, a phase the case type never passes in words.
	 *
	 * @param array<string, mixed> $report    The report.
	 * @param string               $statistic The statistic, mean or median.
	 *
	 * @return string The CSV, with a byte order mark so a spreadsheet reads it as UTF-8.
	 *
	 * @spec openspec/specs/procesanalyse-csv/spec.md#requirement-the-days-per-phase-download-as-csv
	 */
	public function csv(array $report, string $statistic): string;

	/**
	 * The report with every display block, or narrowed to one view.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param mixed                $view   The requested view, when any.
	 *
	 * @return array<string, mixed> The JSON body.
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T02
	 */
	public function json(array $report, mixed $view): array;
}//end interface
