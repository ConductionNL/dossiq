<?php

/**
 * Contract of DecisionService
 *
 * The public surface of DecisionService that other components call, so they depend
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
 * Contract of DecisionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionServiceInterface {

	/**
	 * The case a drafted decision belongs to, through its bezwaar.
	 *
	 * @param string $decisionId UUID of the bezwaarDecision.
	 *
	 * @return string|null The case uuid, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function caseIdForDecision(string $decisionId): ?string;

	/**
	 * The case a bezwaar belongs to, for the per-case guard.
	 *
	 * A controller cannot authorise a decision on an objection without one:
	 * `CaseAccessGuard` answers per CASE, and an objection names its case.
	 * Answering null denies, which is the posture every other bezwaar surface
	 * takes on an unresolvable id.
	 *
	 * @param string $objectionId UUID of the bezwaar.
	 *
	 * @return string|null The case uuid, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function caseIdForObjection(string $objectionId): ?string;

	/**
	 * Create a bezwaarDecision in status "draft".
	 *
	 * Surface-validates the disposition enum and the per-disposition
	 * mandatory-field rules that apply at draft time (canonical enum,
	 * replacementDecision compatibility, reasoning + legalBasis
	 * presence). Hard guards that only apply at publication time
	 * (appealNotice completeness, proceskosten resolution) are
	 * deferred to publish().
	 *
	 * @param string $objectionId UUID of the bezwaar.
	 * @param array<string, mixed> $payload Decision properties.
	 *
	 * @return array<string, mixed> The created decision record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, schemas
	 *                          are unconfigured, or the payload is
	 *                          invalid at draft time.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function draft(string $objectionId, array $payload): array;

	/**
	 * Publish a draft bezwaarDecision by delegating the *deciding* to decidesk.
	 *
	 * Runs the full Awb validity matrix (REQ-BD-3, REQ-BD-5, REQ-BD-6,
	 * REQ-BD-7) as dossiq domain validation (REQ-PDRD-004), then raises a
	 * decidesk `bezwaar-decision` Decision by dispatching a `DecisionRequestedEvent`
	 * (REQ-PDRD-001) and persists the returned `decisionRef` on the record.
	 * dossiq no longer authors the besluit locally: there is no
	 * `status:'published'` local decision state — the besluit is materialised
	 * from the decidesk `DecisionConcludedEvent` by
	 * {@see \OCA\Dossiq\Listener\DecisionConcludedListener} (REQ-PDRD-003,
	 * REQ-PDRD-007). FAILS CLOSED when decidesk is unavailable (REQ-PDRD-002):
	 * no local decided state is set as a fallback.
	 *
	 * @param string $decisionId UUID of the bezwaarDecision.
	 *
	 * @return array<string, mixed> The decision record annotated with the decidesk decisionRef.
	 *
	 * @throws RuntimeException When validation fails, the decidesk leaf is
	 *                          unavailable (fail closed), or persistence errors.
	 *
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-004-the-awb-and-idor-domain-rules-stay-in-dossiq
	 */
	public function publish(string $decisionId): array;
}//end interface
