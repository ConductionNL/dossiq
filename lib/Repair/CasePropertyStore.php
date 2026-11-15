<?php

/**
 * The object service with the register and the three schemas the case-properties backfill reads and writes.
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
 * The object service with the register and the three schemas the case-properties backfill reads and writes.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class CasePropertyStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService
	 * @param string $register The register id
	 * @param string $caseSchema The case schema id
	 * @param string $valueSchema The caseProperty schema id
	 * @param string $defSchema The propertyDefinition schema id
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $caseSchema,
		public readonly string $valueSchema,
		public readonly string $defSchema,
	) {
	}//end __construct()
}//end class
