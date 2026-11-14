<?php

/**
 * OpenRegister's object service with the register and the decision and case schemas the Woo publication state backfill reads and writes.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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

namespace OCA\Dossiq\Repair;

/**
 * OpenRegister's object service with the register and the decision and case schemas the Woo publication state backfill reads and writes.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class WooBackfillStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service
	 * @param string $register The register
	 * @param string $decisionSchema The decision schema
	 * @param string $caseSchema The case schema
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $decisionSchema,
		public readonly string $caseSchema,
	) {
	}//end __construct()
}//end class
