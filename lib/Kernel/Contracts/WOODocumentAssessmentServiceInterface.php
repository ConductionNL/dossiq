<?php

/**
 * Contract of WOODocumentAssessmentService
 *
 * The public surface of WOODocumentAssessmentService that other components call, so they depend
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
 * Contract of WOODocumentAssessmentService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WOODocumentAssessmentServiceInterface {

	/**
	 * Bulk-upsert assessments for a case's documents.
	 *
	 * Creates or updates wooAssessment records. Returns the list of saved
	 * assessments and flags any documents that still lack an assessment.
	 *
	 * @param string $caseId The case UUID
	 * @param array<int, array<string, mixed>> $assessments Array of assessment payloads
	 *
	 * @return array<string, mixed> Result with saved assessments and outstanding documents
	 *
	 * @throws RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function bulkUpsert(string $caseId, array $assessments): array;

	/**
	 * Load the existing wooAssessment record for a (case, document) pair, or
	 * null when the document has not been assessed yet — the SAME
	 * search-then-update lookup `bulkUpsert()` already performs, extracted
	 * so `WOOAnonymisationAssistService` can find the record it attaches a
	 * `redactionProposal` to without duplicating the OpenRegister query
	 * shape (woo-llm-anonymisation).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $documentRef The document UUID.
	 *
	 * @return array<string, mixed>|null The assessment record, or null if not yet assessed.
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function findAssessment(string $caseId, string $documentRef): ?array;

	/**
	 * Every document of a case that is under review: on the case and not set aside.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return list<string> The document identifiers.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-every-document-with-its-assessment-req-das-004
	 */
	public function getDocumentsUnderReview(string $caseId): array;

	/**
	 * Get documents without a completed assessment for a case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed> Array with 'count' and 'documents' list of unassessed doc IDs
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 */
	public function getOutstanding(string $caseId): array;
}//end interface
