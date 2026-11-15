<?php

/**
 * The state of one milestone projection: the definitions, the running totals, the case start, what is reached, and the dates resolved so far.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Milestone
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Milestone;

use DateTimeImmutable;

/**
 * One projection run. The resolved dates are a memo, written as the run goes,
 * so a diamond in the dependency chain is resolved once rather than once per
 * path into it.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */
final class MilestoneProjection {

	/**
	 * The dates resolved so far, by identifier.
	 *
	 * @var array<string, DateTimeImmutable>
	 */
	public array $dates = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $byIdentifier Every definition, by identifier.
	 * @param array<string, int>                  $cumulative   The running total for predecessor-less items.
	 * @param DateTimeImmutable                   $caseStart    The case start date.
	 * @param array<string, DateTimeImmutable>    $reached      Actual dates, by identifier.
	 */
	public function __construct(
		public readonly array $byIdentifier,
		public readonly array $cumulative,
		public readonly DateTimeImmutable $caseStart,
		public readonly array $reached,
	) {
	}//end __construct()
}//end class
