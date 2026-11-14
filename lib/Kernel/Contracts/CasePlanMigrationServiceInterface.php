<?php

/**
 * Contract of CasePlanMigrationService
 *
 * The public surface of CasePlanMigrationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of CasePlanMigrationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CasePlanMigrationServiceInterface {

	public const MIGRATED = 'migrated';

	public const UNMAPPABLE = 'unmappable';

	/**
	 * Migrate every case of every CMMN case type.
	 *
	 * @param boolean $dryRun Report, write nothing.
	 *
	 * @return array<int, array<string, mixed>> One report per case that carried a blob, or why none could be read.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function migrateAll(bool $dryRun): array;

	/**
	 * Migrate one case by uuid.
	 *
	 * @param string  $caseId The case uuid.
	 * @param boolean $dryRun Report, write nothing.
	 *
	 * @return array<string, mixed> The case's report.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function migrateCaseById(string $caseId, bool $dryRun): array;
}//end interface
