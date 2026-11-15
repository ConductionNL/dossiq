<?php

/**
 * Contract of ConsultationReports
 *
 * The public surface of ConsultationReports that other components call, so they depend
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
 * Contract of ConsultationReports.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConsultationReportsInterface {

	/**
	 * Per advisory body, the late share over the last 30 days against the bottleneck threshold.
	 *
	 * @return array{threshold: float, windowDays: int, bodies: array<int, array<string, mixed>>} The report.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-advisory-bodies-past-the-bottleneck-threshold-are-flagged
	 */
	public function getBottlenecks(): array;

	/**
	 * The waiting consultations whose deadline is within the warning offset.
	 *
	 * @param callable|null $mayRead Keeps a request the caller may see; asked of each before the list is built.
	 *
	 * @return array{results: array<int, array<string, mixed>>, warningOffsetDays: int} The list and the offset used.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-advice-requests-due-within-the-warning-offset-are-listed
	 */
	public function getDueSoonConsultations(?callable $mayRead = null): array;
}//end interface
