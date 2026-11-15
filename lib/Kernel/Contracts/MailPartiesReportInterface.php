<?php

/**
 * Contract of MailPartiesReport
 *
 * The public surface of MailPartiesReport that other components call, so they depend
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
 * Contract of MailPartiesReport.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MailPartiesReportInterface {

	/**
	 * The counts for one case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{
	 *     mails: int,
	 *     unreadable: int,
	 *     senders: list<array{address: string, count: int}>,
	 *     recipients: list<array{address: string, count: int}>,
	 *     domains: list<array{domain: string, count: int}>
	 * } Each list sorted by count, highest first, then by name.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
	 */
	public function forCase(string $caseId): array;

	/**
	 * The report as CSV: one line per party, its kind, and its count; unreadable last.
	 *
	 * @param array<string, mixed> $report The report forCase() answered.
	 *
	 * @return string The CSV, with a header line.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
	 */
	public function toCsv(array $report): string;
}//end interface
