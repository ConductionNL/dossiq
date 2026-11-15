<?php

/**
 * Contract of DocumentRelevance
 *
 * The public surface of DocumentRelevance that other components call, so they depend
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
 * Contract of DocumentRelevance.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentRelevanceInterface {

	public const IN_SCOPE = 'in-scope';

	/**
	 * Every review of a case, keyed by document, whoever asks.
	 *
	 * A review is private to the people on the case, so one person reads only
	 * a slice of them: a batch reviewer the reviews of their batch. Every
	 * caller of this method is a rule or a count of the case (what is still
	 * outstanding, which pages are unseen, which documents a rule may mark,
	 * whether a document already has a review), and such a rule holds for the
	 * whole case. So this reads regardless of the caller's own grants, the
	 * read CaseReviewerAccess::recordsOf() makes. The routes that reach it
	 * check access to the case first, and none of them hands out a review's
	 * row: a person lists reviews through OpenRegister, under their own access.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, array<string, mixed>> The reviews by documentRef.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-rule-of-the-case-reads-the-whole-case-req-cra-011
	 */
	public function forCase(string $caseId): array;

	/**
	 * Whether the review schema can be read. Without it, relevance is not recorded at all.
	 *
	 * @return bool True when OpenRegister and the review schema are configured.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function isAvailable(): bool;

	/**
	 * A verdict says a document is about the request: mark the unmarked ones among these in scope.
	 *
	 * A document already marked, in scope or out of scope, keeps its marking.
	 * Without the review schema nothing is recorded.
	 *
	 * @param string       $caseId       The case UUID.
	 * @param list<string> $documentRefs The documents that just received a verdict.
	 * @param string       $userId       Who gave the verdict.
	 *
	 * @return int How many documents were marked in scope.
	 *
	 * @throws RefusedException When a review cannot be written.
	 *
	 * @spec openspec/changes/a-verdict-marks-its-document-in-scope/specs/document-review/spec.md#requirement-a-verdict-on-an-unmarked-document-marks-it-in-scope-req-wrt-006
	 */
	public function markInScopeWhereUnmarked(string $caseId, array $documentRefs, string $userId): int;

	/**
	 * The pages seen on these reviews, and the required pages still unseen.
	 *
	 * @return PagesSeenInterface The pages seen, over these reviews.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function pages(): PagesSeenInterface;

	/**
	 * Save a review, creating or replacing it.
	 *
	 * @param array<string, mixed> $review The review.
	 *
	 * @return array<string, mixed> The saved review.
	 *
	 * @throws RefusedException When the review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function save(array $review): array;

	/**
	 * The documents that still hold up the decision.
	 *
	 * An out-of-scope document needs no verdict, an in-scope one needs a
	 * verdict, and an unmarked one is outstanding whatever it carries: nobody
	 * has said yet whether it is about the request. Without the review schema
	 * every document needs a verdict, as before, which is the stricter reading.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<int, int|string> $documentIds The case's documents.
	 * @param array<string, bool> $assessed The assessed documents as keys.
	 *
	 * @return list<string> The outstanding documents.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function outstanding(string $caseId, array $documentIds, array $assessed): array;
}//end interface
