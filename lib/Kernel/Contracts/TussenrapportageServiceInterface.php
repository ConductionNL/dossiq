<?php

/**
 * Contract of TussenrapportageService
 *
 * The public surface of TussenrapportageService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Contract of TussenrapportageService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TussenrapportageServiceInterface {

	/**
	 * Approve an interim report (REQ-SUB-004). Records the assessor (from
	 * session, never the body), the assessment date, and sets the status to
	 * goedgekeurd. The caller surfaces the report id so the voorschot engine
	 * can release conditionally dependent disbursements.
	 *
	 * @param string $reportId The report id.
	 * @param string|null $beoordelingsoordeel Optional assessment narrative.
	 * @param float|null $approvedAmount Optional approved amount.
	 *
	 * @return array<string, mixed> The approved report record.
	 *
	 * @throws OCSBadRequestException When unauthenticated or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-004-tussenrapportage-as-typed-sub-zaak
	 */
	public function approveReport(string $reportId, ?string $beoordelingsoordeel = null, ?float $approvedAmount = null): array;

	/**
	 * Create an interim report in status "expected" (REQ-SUB-004).
	 *
	 * @param string $uitvoeringId The execution id.
	 * @param array<string, mixed> $payload The report properties.
	 *
	 * @return array<string, mixed> The created report record.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-004-tussenrapportage-as-typed-sub-zaak
	 */
	public function createExpected(string $uitvoeringId, array $payload): array;
}//end interface
