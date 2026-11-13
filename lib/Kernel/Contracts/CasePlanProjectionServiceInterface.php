<?php

/**
 * Contract of CasePlanProjectionService
 *
 * The public surface of CasePlanProjectionService that other components call, so they depend
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
 * Contract of CasePlanProjectionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CasePlanProjectionServiceInterface {

	/**
	 * Project a case by id, resolving its caseType first.
	 *
	 * The entry point the case-created listener calls, so the listener stays
	 * a pure observer and every decision lives here (ADR-022).
	 *
	 * @param string $caseId     The case object uuid.
	 * @param string $caseTypeId The caseType uuid the case names.
	 *
	 * @return array{projected: boolean, reason: string} What it did, and why.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function projectForCase(string $caseId, string $caseTypeId): array;
}//end interface
