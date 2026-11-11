<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * The scope a ZGW operation requires: its component and the scope on it.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
final class ZgwScopeRequirement {
	/**
	 * Constructor.
	 *
	 * @param string $component The ZGW component (e.g. 'zrc', 'ztc', 'brc', 'drc').
	 * @param string $scope     The required scope.
	 */
	public function __construct(
		public readonly string $component,
		public readonly string $scope,
	) {
	}//end __construct()
}//end class
