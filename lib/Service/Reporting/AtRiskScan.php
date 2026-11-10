<?php

/**
 * One scan of the open records for those at risk of missing their target.
 *
 * Built by `ProcessingTimeRows::atRisk()` and never handed out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

use DateTimeImmutable;

/**
 * The types, threshold and reference day one at-risk scan holds its records against.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
final class AtRiskScan {
	/**
	 * The reference day, at midnight in the zone the dates are counted in.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $today;

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $types     Prepared types keyed by id.
	 * @param float                               $threshold Remaining share at or below which a record is listed.
	 */
	public function __construct(
		public readonly array $types,
		public readonly float $threshold,
	) {
	}//end __construct()
}//end class
