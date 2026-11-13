<?php

/**
 * Contract of BesluitMaterialisationService
 *
 * The public surface of BesluitMaterialisationService that other components call, so they depend
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
 * Contract of BesluitMaterialisationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BesluitMaterialisationServiceInterface {

	/**
	 * Materialise the ZGW Besluit from a decidesk `DecisionConcludedEvent`.
	 *
	 * Maps the decidesk event getters into the normalised-outcome array shape
	 * that {@see materialise()} consumes, then materialises the ZGW Besluit. The
	 * Besluiten-API payload shape is unchanged; only the *origin* of the values
	 * is the concluded event rather than a poll of the decidesk outcome.
	 *
	 * The `getOutcome()` string is used as the ZGW result verbatim when present,
	 * falling back to `getStatus()` (approved/rejected/withdrawn/pending).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $decisionId The existing ZGW Besluit UUID on the case (or empty for new).
	 * @param array<string,mixed> $event The event projection: status, outcome, decidedAt, motivering, signer, method.
	 *
	 * @return array<string,mixed> The persisted Besluit record.
	 *
	 * @throws RuntimeException When the case cannot be loaded or the Besluit cannot be persisted.
	 *
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	public function materialiseFromConcludedEvent(string $caseId, string $decisionId, array $event): array;
}//end interface
