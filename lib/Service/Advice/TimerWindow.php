<?php

/**
 * When an advice timer starts counting, and how many days it counts to the breach.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Advice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Advice;

use DateTimeImmutable;

/**
 * When an advice timer starts counting, and how many days it counts to the breach.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
final class TimerWindow {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $anchor  Midnight today.
	 * @param int               $slaDays Days from the anchor to the breach.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function __construct(
		public readonly DateTimeImmutable $anchor,
		public readonly int $slaDays,
	) {
	}//end __construct()
}//end class
