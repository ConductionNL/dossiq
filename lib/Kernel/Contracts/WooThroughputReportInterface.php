<?php

/**
 * Contract of WooThroughputReport
 *
 * The public surface of WooThroughputReport that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of WooThroughputReport.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooThroughputReportInterface {

	/**
	 * The throughput of one Woo case.
	 *
	 * @param string $caseId The Woo case UUID.
	 *
	 * @return array{rows: list<array<string, int|string>>, cases: list<string>, truncated: bool} The report.
	 *
	 * @throws RefusedException When OpenRegister or the assessment schema is not available.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function forCase(string $caseId): array;

	/**
	 * The throughput across Woo cases over a period of days, both ends included.
	 *
	 * @param string $from The first day, Y-m-d.
	 * @param string $to The last day, Y-m-d.
	 *
	 * @return array{rows: list<array<string, int|string>>, cases: list<string>, truncated: bool} The report.
	 *
	 * @throws RefusedException When a day is not a date, or OpenRegister is not available.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function forPeriod(string $from, string $to): array;

	/**
	 * The rows as CSV, with a header line.
	 *
	 * @param list<array<string, int|string>> $rows The report rows.
	 *
	 * @return string The CSV text.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function toCsv(array $rows): string;
}//end interface
