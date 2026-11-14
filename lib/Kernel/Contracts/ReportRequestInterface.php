<?php

/**
 * Contract of ReportRequest
 *
 * The public surface of ReportRequest that other components call, so they depend
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
 * Contract of ReportRequest.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportRequestInterface {

	/**
	 * The filter map for the report service.
	 *
	 * Absent and empty-string filters are omitted. A period preset sets `from`
	 * when `from` is not given.
	 *
	 * @param array<string, mixed> $query The accepted query parameters.
	 *
	 * @return array<string, string> The filters.
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T02
	 */
	public function filters(array $query): array;

	/**
	 * Why the query is refused, or null when it is acceptable.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return string|null The message of the first offending parameter.
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T02
	 * @spec openspec/specs/procesanalyse-csv/spec.md#requirement-the-days-per-phase-download-as-csv
	 */
	public function refusal(array $query): ?string;
}//end interface
