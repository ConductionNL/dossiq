<?php

/**
 * The running SLA count over the completed records of one report
 *
 * The case types the records are measured against, and so far: the records
 * within their target, the records measured, the records that could not be
 * measured, and the same per case type family. The state
 * ProcessingTimeCalculator::slaCompliance() fills in one record at a time.
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

/**
 * The progress of one SLA count; it never leaves the calculator that built it.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
final class SlaTally {

	/**
	 * The prepared case types, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $types = [];

	/**
	 * One entry per case type family, in the order first met.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $byType = [];

	/**
	 * The records within their target.
	 *
	 * @var integer
	 */
	public int $withinSla = 0;

	/**
	 * The records measured.
	 *
	 * @var integer
	 */
	public int $total = 0;

	/**
	 * The records without a target or a measurable duration.
	 *
	 * @var integer
	 */
	public int $excluded = 0;
}//end class
