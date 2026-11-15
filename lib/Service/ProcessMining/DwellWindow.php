<?php

/**
 * The window a dwell-time report counts visits in, and the moment an open visit ends.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use DateTimeImmutable;

/**
 * Only visits entered within [start, end] count; a still-open visit ends at now.
 *
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T01
 */
final class DwellWindow {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $now   "Now", for the still-open current status.
	 * @param DateTimeImmutable $start Inclusive window start.
	 * @param DateTimeImmutable $end   Inclusive window end.
	 */
	public function __construct(
		public readonly DateTimeImmutable $now,
		public readonly DateTimeImmutable $start,
		public readonly DateTimeImmutable $end,
	) {
	}//end __construct()
}//end class
