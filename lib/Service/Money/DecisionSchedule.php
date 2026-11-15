<?php

/**
 * A decision, its payment schedule as raised so far, and the day a run counts as.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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

namespace OCA\Dossiq\Service\Money;

use DateTimeImmutable;

/**
 * A decision, its payment schedule as raised so far, and the day a run counts as.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class DecisionSchedule {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param array<string, mixed> $schedule Its payment schedule so far.
	 * @param DateTimeImmutable    $today    The day the run counts as.
	 */
	public function __construct(
		public readonly array $decision,
		public readonly array $schedule,
		public readonly DateTimeImmutable $today,
	) {
	}//end __construct()
}//end class
