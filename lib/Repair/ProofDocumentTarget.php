<?php

/**
 * Where migrated proof-of-transfer records are written as case documents.
 *
 * Built by `MigrateArchivalToOpenRegister::exportProofRecords()` and handed through its export steps:
 * OpenRegister's object service, the register and the case document schema.
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
 * Where migrated proof-of-transfer records are written as case documents.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
final class ProofDocumentTarget {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      Register slug or id.
	 * @param string $docSchema     The case document schema.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $docSchema,
	) {
	}//end __construct()
}//end class
