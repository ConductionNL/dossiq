<?php

/**
 * The day a stall check runs on and the grace days it allows past a deadline.
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
 * When a milestone counts as stalled.
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */
final class StallWindow {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $today         Today (date only).
	 * @param int               $thresholdDays Grace days past the deadline.
	 */
	public function __construct(
		public readonly DateTimeImmutable $today,
		public readonly int $thresholdDays,
	) {
	}//end __construct()
}//end class
