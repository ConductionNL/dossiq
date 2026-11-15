<?php

/**
 * A bezwaartermijn timer about to be armed: the trigger, its id, the anchor and the days to the archive date.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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

namespace OCA\Dossiq\Service\Beschikking;

use DateTimeImmutable;

/**
 * A bezwaartermijn timer about to be armed: the trigger, its id, the anchor and the days to the archive date.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class ArchiveTimerStart {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $trigger The trigger
	 * @param string $triggerId Its id
	 * @param DateTimeImmutable $anchor Midnight of the bekendmaking (or today)
	 * @param int $slaDays Days from the anchor to the archive date
	 */
	public function __construct(
		public readonly array $trigger,
		public readonly string $triggerId,
		public readonly DateTimeImmutable $anchor,
		public readonly int $slaDays,
	) {
	}//end __construct()
}//end class
