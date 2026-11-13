<?php

/**
 * Contract of DataSubjectRequestCase
 *
 * The public surface of DataSubjectRequestCase that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of DataSubjectRequestCase.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DataSubjectRequestCaseInterface {

	/**
	 * What the case can offer the data subject right now.
	 *
	 * 🔑 THE PLATFORM IS ASKED AGAIN RATHER THAN THE CASE BEING READ. The
	 * export has a seven day life and the case is a copy of a reading taken
	 * when it was made. Offering a link off that copy is offering a link that
	 * 404s in front of the person it was promised to.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return array<string, mixed> `{exportId, downloadable, expiresAt, expired}`.
	 *
	 * @spec openspec/specs/avg-processing-surface/spec.md
	 */
	public function exportState(string $caseId): array;

	/**
	 * Ask the platform what erasing this subject would touch, and record it.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return array<string, mixed> The preview, as the platform recorded it.
	 *
	 * @throws RefusedException When the case names no subject, or the platform refuses.
	 *
	 * @spec openspec/specs/avg-processing-surface/spec.md
	 */
	public function preview(string $caseId): array;

	/**
	 * Ask the platform for this subject's own export, and record it.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return array<string, mixed> The export record.
	 *
	 * @throws RefusedException When the case names no subject, or the platform refuses.
	 *
	 * @spec openspec/specs/avg-processing-surface/spec.md
	 */
	public function requestExport(string $caseId): array;

	/**
	 * Run the approved erasure, and write back what the platform did.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return array<string, mixed> The run report.
	 *
	 * @throws RefusedException When the approving act was not taken, or the platform refuses.
	 *
	 * @spec openspec/specs/avg-processing-surface/spec.md
	 */
	public function run(string $caseId): array;
}//end interface
