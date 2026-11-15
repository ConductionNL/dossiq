<?php

/**
 * The in-memory filters a DSO dashboard query applies after the register answered.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dso
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

namespace OCA\Dossiq\Service\Dso;

/**
 * The in-memory filters a DSO dashboard query applies after the register answered.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class DashboardFilters {
	/**
	 * Constructor.
	 *
	 * @param string $activiteitgroep Filter by activiteitgroep
	 * @param string $regelkwalificatie Filter by regelkwalificatie
	 * @param string $location Filter by locatie substring
	 */
	public function __construct(
		public readonly string $activiteitgroep,
		public readonly string $regelkwalificatie,
		public readonly string $location,
	) {
	}//end __construct()
}//end class
