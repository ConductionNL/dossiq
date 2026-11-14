<?php

/**
 * Contract of ReviewWrites
 *
 * The public surface of ReviewWrites that other components call, so they depend
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
 * Contract of ReviewWrites.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReviewWritesInterface {

	/**
	 * Mark a document's relevance as this person.
	 *
	 * @param string $caseId      The case UUID.
	 * @param string $documentRef The document.
	 * @param string $relevance   `in-scope`, `out-of-scope` or `unmarked`.
	 * @param string $userId      The person.
	 *
	 * @return array<string, mixed> The saved review.
	 *
	 * @throws RefusedException When the relevance is unknown or the review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function mark(string $caseId, string $documentRef, string $relevance, string $userId): array;

	/**
	 * Record the pages this person displayed, and say which required pages nobody has seen yet.
	 *
	 * @param string    $caseId      The case UUID.
	 * @param string    $documentRef The document.
	 * @param list<int> $pages       The pages displayed.
	 * @param int|null  $pageCount   The document's page count, when the viewer reports it.
	 * @param string    $userId      The person.
	 *
	 * @return array<string, mixed> The saved review, with `unseen`.
	 *
	 * @throws RefusedException When no page is given or the review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function pagesSeen(string $caseId, string $documentRef, array $pages, ?int $pageCount, string $userId): array;

	/**
	 * Open an unmarked review for each document just added to a case, then share the new reviews.
	 *
	 * @param string       $caseId       The case UUID.
	 * @param list<string> $documentRefs The documents just added.
	 *
	 * @return int How many reviews were created.
	 *
	 * @throws RefusedException When a review cannot be written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function openReviews(string $caseId, array $documentRefs): int;
}//end interface
