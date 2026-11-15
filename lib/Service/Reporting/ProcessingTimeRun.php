<?php

/**
 * One processing-time report on its way to the dashboard
 *
 * The period, the case type and the view asked for, the day the report is
 * built on, and the case types and final statuses it is read against: the
 * state ProcessingTimeReportService::getReport() builds and its steps read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

use DateTimeImmutable;

/**
 * The state of one report; it never leaves the report service that built it.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
final class ProcessingTimeRun {

	/**
	 * The period preset (1m, 3m, 6m, year, all, or the 12-month default).
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * The case type asked for (id, slug or identifier), or null for all.
	 *
	 * @var string|null
	 */
	public ?string $caseType = null;

	/**
	 * The one block asked for, or null for every block.
	 *
	 * @var string|null
	 */
	public ?string $view = null;

	/**
	 * The remaining share of the target at or below which an open record is at risk.
	 *
	 * @var float
	 */
	public float $threshold = 0.0;

	/**
	 * The day the report is built on.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $today;

	/**
	 * The prepared case types, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $types = [];

	/**
	 * The final status ids, as keys.
	 *
	 * @var array<string, true>
	 */
	public array $finals = [];
}//end class
