<?php

/**
 * One computation of the dashboard KPIs for one reader
 *
 * The register ids, the reader, the day and month the figures are counted
 * for, and the open and closed cases read once: the state
 * KpiAggregationService::computeKpis() builds and its figure groups read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * The state of one KPI computation; it never leaves the service that built it.
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */
final class KpiRun {

	/**
	 * The register and schema ids.
	 *
	 * @var array<string, mixed>
	 */
	public array $ids = [];

	/**
	 * The reader.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * Today, at midnight.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $now;

	/**
	 * Today (Y-m-d).
	 *
	 * @var string
	 */
	public string $today = '';

	/**
	 * The first day of this month (Y-m-d).
	 *
	 * @var string
	 */
	public string $monthStart = '';

	/**
	 * The last day of this month (Y-m-d).
	 *
	 * @var string
	 */
	public string $monthEnd = '';

	/**
	 * The cases closed this month.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $closed = [];

	/**
	 * The open cases.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $openCases = [];
}//end class
