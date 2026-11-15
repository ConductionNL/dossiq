<?php

/**
 * Where a full case-plan drain reads and whether it writes.
 *
 * Built once by `CasePlanMigrationService::migrateAll()` and handed through its private steps: the
 * object service, the register, the case and case type schemas, and the dry-run flag.
 *
 * @category CasePlanDrain
 * @package  OCA\Dossiq\Service\CasePlanDrain
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

namespace OCA\Dossiq\Service\CasePlanDrain;

/**
 * Where a full case-plan drain reads and whether it writes.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */
final class DrainScope {

	/**
	 * Constructor.
	 *
	 * @param object  $objects    The OpenRegister ObjectService.
	 * @param string  $register   The register id.
	 * @param string  $caseSchema The case schema id.
	 * @param string  $typeSchema The case type schema id.
	 * @param boolean $dryRun     Report, write nothing.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objects,
		public readonly string $register,
		public readonly string $caseSchema,
		public readonly string $typeSchema,
		public readonly bool $dryRun,
	) {
	}//end __construct()
}//end class
