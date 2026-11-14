<?php

/**
 * Dossiq Status Capacity value object.
 *
 * A status being entered and the number of cases it may hold.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * A capped status: its id and its limit.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class StatusCapacity {
	/**
	 * Constructor.
	 *
	 * @param string $statusTypeId The status being entered.
	 * @param int    $limit        The number of cases it may hold, above 0.
	 */
	public function __construct(
		public readonly string $statusTypeId,
		public readonly int $limit,
	) {
	}//end __construct()
}//end class
