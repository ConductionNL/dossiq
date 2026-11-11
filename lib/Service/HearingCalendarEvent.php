<?php

/**
 * Dossiq Hearing Calendar Event
 *
 * One serialised hearing event and the UID every copy of it carries.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * A hearing event as written into each calendar: its UID and its ICS.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
 */
final class HearingCalendarEvent {

	/**
	 * Constructor.
	 *
	 * @param string $uid The event UID, also the calendar object name.
	 * @param string $ics The event, serialised.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly string $ics,
	) {
	}//end __construct()
}//end class
