<?php

/**
 * Where the shipped municipal roles are read and stamped: the starter store, the ledger and the shipped sets.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

/**
 * Where the shipped municipal roles are read and stamped: the starter store, the ledger and the shipped sets.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
final class ShippedRoleSources {
	/**
	 * Constructor.
	 *
	 * @param StarterStore                $store   The OpenRegister seam.
	 * @param ShippedConfigurationService $shipped The provenance ledger.
	 * @param ShippedSets                 $sets    The sets dossiq ships, and their versions.
	 */
	public function __construct(
		public readonly StarterStore $store,
		public readonly ShippedConfigurationService $shipped,
		public readonly ShippedSets $sets,
	) {
	}//end __construct()
}//end class
