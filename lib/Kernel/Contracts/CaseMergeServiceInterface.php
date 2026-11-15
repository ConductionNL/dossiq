<?php

/**
 * Contract of CaseMergeService
 *
 * The public surface of CaseMergeService that other components call, so they depend
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
 * Contract of CaseMergeService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseMergeServiceInterface {

	/**
	 * Apply dossiq's consequences of a merge.
	 *
	 * @param string $mergedId   The case that was merged away.
	 * @param string $survivorId The case it was merged into.
	 *
	 * @return bool True when the merged case was updated.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function applyMerge(string $mergedId, string $survivorId): bool;

	/**
	 * Undo dossiq's consequences when the platform reverses the merge.
	 *
	 * @param string $mergedId   The case that was merged away.
	 * @param string $survivorId The case it had been merged into.
	 *
	 * @return bool True when the merged case was updated.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function applyReversal(string $mergedId, string $survivorId): bool;

	/**
	 * Ask OpenRegister to merge one case into another.
	 *
	 * Dossiq decides whether this case may be merged away, because the rules
	 * that refuse it are case management's; OpenRegister does the merge,
	 * because the merge is the platform's (ADR-045). The refusal is written
	 * here and not only in the browser: an action the browser hides is still
	 * an endpoint anyone may call.
	 *
	 * @param string $mergedId   The case to merge away.
	 * @param string $survivorId The case it becomes part of.
	 * @param string $reason     What the caseworker typed.
	 * @param string $actor      The acting user's uid.
	 *
	 * @return array{refused?: string, operation?: array<string, mixed>} The outcome.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function requestMerge(string $mergedId, string $survivorId, string $reason, string $actor): array;

	/**
	 * Follow `mergedInto` until it reaches a case that carries none.
	 *
	 * This is what makes an old case number keep working: mail matching and
	 * the public status page ask this question and act on the answer, so a
	 * reply to a merged case lands where the work actually is.
	 *
	 * An unreadable case, a cycle or a chain longer than {@see MAX_HOPS}
	 * answers the id it was given. That is the safe direction: filing a reply
	 * on the case it names is wrong only in the way it was already wrong.
	 *
	 * @param string $caseId The case uuid a caller matched.
	 *
	 * @return string The surviving case's uuid, or the input when there is none.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-old-number-still-finds-the-case-req-cm-38
	 */
	public function resolveSurvivor(string $caseId): string;
}//end interface
