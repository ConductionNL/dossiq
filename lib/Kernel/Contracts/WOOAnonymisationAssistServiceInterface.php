<?php

/**
 * Contract of WOOAnonymisationAssistService
 *
 * The public surface of WOOAnonymisationAssistService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of WOOAnonymisationAssistService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WOOAnonymisationAssistServiceInterface {

	/**
	 * Propose redaction spans for a document, merging the deterministic
	 * rules floor with an optional LLM-assisted layer, and persist the
	 * result as a `pending_review` proposal on the document's assessment.
	 *
	 * FAIL-CLOSED: an LLM error/timeout/guardrail-block never blocks this
	 * call — it degrades to a rules-only proposal with a clear
	 * `llmAvailable`/`llmError` signal, and the assessment is NEVER marked
	 * anything but `pending_review` (never "anonymised", never published).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $documentRef The document UUID.
	 * @param string $text The document text to scan (caller is responsible for having
	 *                     authorization to read the underlying document — this method
	 *                     does no file access of its own).
	 * @param string $userId The requesting user id (audit + `proposedBy`).
	 *
	 * @return array<string, mixed> `{spans, source, llmAvailable, llmError?, status}`.
	 *
	 * @throws InvalidArgumentException (400) When `text` is empty or over the length cap.
	 * @throws RuntimeException When the document has not yet been assessed (assess-first rule).
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-2
	 */
	public function proposeSpans(string $caseId, string $documentRef, string $text, string $userId): array;

	/**
	 * Record a human reviewer's decision on a pending proposal.
	 *
	 * On `approve`, the proposal is marked `approved` with the reviewed spans;
	 * on `reject`, it is marked `rejected`. Neither writes a redacted copy:
	 * that is the redaction panel's, after an officer checked the entities
	 * filinq will act on.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $documentRef The document UUID.
	 * @param string $decision `'approve'` or `'reject'`.
	 * @param string $reviewerId The reviewing user id.
	 * @param array|null $editedSpans Optional reviewer-edited span list (approve only) —
	 *                                when omitted, the full merged proposal is approved
	 *                                as-is.
	 *
	 * @return array<string, mixed> The updated proposal record.
	 *
	 * @throws InvalidArgumentException (400) On an invalid `decision`.
	 * @throws RuntimeException When no `pending_review` proposal exists for this document.
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-2
	 */
	public function reviewProposal(
		string $caseId,
		string $documentRef,
		string $decision,
		string $reviewerId,
		?array $editedSpans = null,
	): array;
}//end interface
