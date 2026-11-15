<?php

/**
 * Contract of AdvisoryCommitteeService
 *
 * The public surface of AdvisoryCommitteeService that other components call, so they depend
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

use OCA\Dossiq\Service\Transitions\GuardFailedException;
use RuntimeException;

/**
 * Contract of AdvisoryCommitteeService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdvisoryCommitteeServiceInterface {

	/**
	 * The code of the RuntimeException raised for an advice request that does not exist.
	 */
	public const NOT_FOUND = 404;

	/**
	 * The code of the RuntimeException raised when someone other than the committee chair signs the advice.
	 */
	public const NOT_CHAIR = 403;

	/**
	 * Listener entry-point: when a bezwaar enters status
	 * "Hearing planned", auto-assign the default committee for the
	 * bezwaar's jurisdiction.
	 *
	 * @param string $objectionId The bezwaar (lifecycle) UUID
	 *
	 * @return array<string, mixed>|null The created advice request, or
	 *                                   null when no default committee
	 *                                   is configured.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function autoAssignDefaultCommittee(string $objectionId): ?array;

	/**
	 * Guard a move into in-deliberation (REQ-BAC-2) for a request as it will be saved.
	 *
	 * Called by DeliberationStartGuard on every single-object save that moves
	 * an advice request into in-deliberation.
	 *
	 * @param array<string, mixed> $request   The advice request as it will be saved
	 * @param string               $requestId Advice request UUID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister is not available, or no panel has been set
	 * @throws GuardFailedException When a panel member is not independent, or independence cannot be verified
	 *
	 * @spec openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
	 */
	public function guardDeliberationStart(array $request, string $requestId): void;

	/**
	 * Advance the advice request to a new status. Enforces the one-way
	 * lifecycle (REQ-BAC-3), the independence check (REQ-BAC-2) and the
	 * advice content contract (REQ-BAC-4).
	 *
	 * @param string $requestId Advice request UUID
	 * @param string $newStatus Target status
	 * @param array<string, mixed> $payload Optional patch (advice text,
	 *                                      signatureEvidence, conclusion, ...)
	 *
	 * @return array<string, mixed> The updated advice request record
	 *
	 * @throws RuntimeException When the transition is forbidden
	 * @throws GuardFailedException When the independence check fails
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-004-the-awb-and-idor-domain-rules-stay-in-dossiq
	 */
	public function transitionAdviceStatus(
		string $requestId,
		string $newStatus,
		array $payload = [],
	): array;

	/**
	 * Record a council-deviation event on the linked advice request after
	 * the parent bezwaar-lifecycle finalises a besluit op bezwaar with
	 * `motivatieAfwijkingAdvies` set (REQ-BAC-5).
	 *
	 * @param string $requestId Advice request UUID
	 * @param string $decisionId Besluit op bezwaar UUID
	 * @param string $rationaleRef Reference / hash of the motivation
	 *
	 * @return void
	 *
	 * @throws RuntimeException A BezwaarEntryNotWrittenException when the entry could not be written; the caller logs it with the entry.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function recordCouncilDeviation(
		string $requestId,
		string $decisionId,
		string $rationaleRef,
	): void;
}//end interface
