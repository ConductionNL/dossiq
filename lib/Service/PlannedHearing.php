<?php

/**
 * A hearing about to be put in calendars: its stored data, when it starts and who takes part.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * A hearing about to be put in calendars: its stored data, when it starts and who takes part.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class PlannedHearing {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Hearing data with participants
	 * @param DateTimeImmutable $start When the hearing starts
	 * @param array<int, mixed> $participants The planned participants as stored
	 */
	public function __construct(
		public readonly array $data,
		public readonly DateTimeImmutable $start,
		public readonly array $participants,
	) {
	}//end __construct()
}//end class
