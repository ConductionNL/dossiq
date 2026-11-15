<?php

/**
 * Contract of MandaatImportService
 *
 * The public surface of MandaatImportService that other components call, so they depend
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
 * Contract of MandaatImportService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MandaatImportServiceInterface {

	/**
	 * Approve a concept besluit: flip besluit → vastgesteld + every mandaat → active,
	 * and mark the prior besluit (if any) → vervallen.
	 *
	 * @param string $decisionId Besluit id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function approveImport(string $decisionId): array;

	/**
	 * Import a mandate decision from CSV text.
	 *
	 * @param string $decisionNumber Decision identifier.
	 * @param string $decisionName Decision name.
	 * @param string $decideskUuid Source Decidesk decision id.
	 * @param string $csvContents The CSV payload (RFC 4180; first row is header).
	 *
	 * @return array<string, mixed> carrying mandateDecisionId, totalMandaten, newCount, changedCount, removedCount and diff.
	 *
	 * @throws RuntimeException When the CSV is malformed or a rol cannot be resolved.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function importFromCsv(
		string $decisionNumber,
		string $decisionName,
		string $decideskUuid,
		string $csvContents,
	): array;
}//end interface
