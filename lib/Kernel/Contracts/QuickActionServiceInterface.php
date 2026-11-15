<?php

/**
 * Contract of QuickActionService
 *
 * The public surface of QuickActionService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of QuickActionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QuickActionServiceInterface {

	/**
	 * Register a klacht as an Awb 9:1 case linked to the original case.
	 *
	 * @param string $caseId The case being complained about (may be empty).
	 * @param string $summary The klacht text.
	 * @param string $burgerId The identified burger reference.
	 *
	 * @return array{klachtCaseId: string, deadline: string}
	 *
	 * @throws RuntimeException When the klacht text is empty or the write fails.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T07
	 */
	public function executeKlachtRegistreren(string $caseId, string $summary, string $burgerId): array;

	/**
	 * Create a new case from the KCC contact context.
	 *
	 * @param string $caseType The target case type slug.
	 * @param string $burgerId The identified burger reference.
	 * @param array<string, mixed> $details The intake details (location, etc.).
	 *
	 * @return array{caseId: string}
	 *
	 * @throws RuntimeException When input is invalid or the write fails.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T07
	 */
	public function executeNieuweZaak(string $caseType, string $burgerId, array $details): array;

	/**
	 * Render a "Status terugkoppelen" draft text for medewerker confirmation.
	 *
	 * Returns a draft only; the activity is recorded by the caller after the
	 * medewerker confirms the status was communicated.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{caseId: string, draftText: string, status: string}
	 *
	 * @throws RuntimeException When the case cannot be loaded.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T07
	 */
	public function executeStatusTerugkoppelen(string $caseId): array;
}//end interface
