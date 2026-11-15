<?php

/**
 * An open case the stall check can assess: its id, its case type and its start.
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
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Milestone;

use DateTimeImmutable;

/**
 * One case that has an id, a case type and a parsable start date.
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */
final class StallCandidate {

	/**
	 * Constructor.
	 *
	 * @param string            $caseId     The case id.
	 * @param string            $caseTypeId The case type id.
	 * @param DateTimeImmutable $startDate  The case start date.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $caseTypeId,
		public readonly DateTimeImmutable $startDate,
	) {
	}//end __construct()
}//end class
