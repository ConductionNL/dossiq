<?php

/**
 * Dossiq referenced case value backfill: where it reads and writes.
 *
 * The object service, the register, the case schema and the schema of the rows
 * the cases refer to, for one run of a BackfillReferencedCaseValue step.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * Where one referenced-value backfill reads the referred rows and writes the cases.
 *
 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
 */
final class CaseValueBackfill {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The dossiq register.
	 * @param string $caseSchema    The case schema.
	 * @param string $targetSchema  The schema of the referred rows.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $caseSchema,
		public readonly string $targetSchema,
	) {
	}//end __construct()
}//end class
