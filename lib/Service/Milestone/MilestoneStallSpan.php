<?php

/**
 * The milestone wait a stall timer is armed for.
 *
 * Built by `MilestoneStallTimer::sync()` once the stall day is known to lie ahead, and handed through
 * the arm steps: the case id, the milestone row, midnight today and the calendar days to the day after
 * the deadline.
 *
 * @category Milestone
 * @package  OCA\Dossiq\Service\Milestone
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Milestone;

use DateTimeImmutable;

/**
 * The milestone wait a stall timer is armed for.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
final class MilestoneStallSpan {

	/**
	 * Constructor.
	 *
	 * @param string               $caseId  The case id.
	 * @param array<string, mixed> $waiting The milestone row.
	 * @param DateTimeImmutable    $anchor  Midnight today.
	 * @param int                  $slaDays Calendar days to the day after the deadline.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly array $waiting,
		public readonly DateTimeImmutable $anchor,
		public readonly int $slaDays,
	) {
	}//end __construct()
}//end class
