<?php

/**
 * The window a dossiq timer runs over: its owner, anchor and length.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timer;

use DateTimeImmutable;

/**
 * One timer's owner id, the moment it counts from and its calendar days.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
final class TimerWindow {

	/**
	 * Build the window.
	 *
	 * @param string            $ownerId The owning object's id.
	 * @param DateTimeImmutable $anchor  The moment the timer counts from.
	 * @param int               $slaDays Calendar days from the anchor to the breach.
	 */
	public function __construct(
		public readonly string $ownerId,
		public readonly DateTimeImmutable $anchor,
		public readonly int $slaDays,
	) {
	}//end __construct()
}//end class
