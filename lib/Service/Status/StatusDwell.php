<?php

/**
 * The maximum dwell a case got on entering a status: the case, the status, the maximum and the status's name.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Status
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

namespace OCA\Dossiq\Service\Status;

/**
 * The maximum dwell a case got on entering a status: the case, the status, the maximum and the status's name.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class StatusDwell {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The case
	 * @param string $statusTypeId The status entered
	 * @param int $maximumDwell The maximum, in working days
	 * @param string $statusName The status's name, for the timer's title
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $statusTypeId,
		public readonly int $maximumDwell,
		public readonly string $statusName,
	) {
	}//end __construct()
}//end class
