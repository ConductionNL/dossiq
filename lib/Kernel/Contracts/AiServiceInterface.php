<?php

/**
 * Contract of AiService
 *
 * The public surface of AiService that other components call, so they depend
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
 * Contract of AiService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiServiceInterface {

	/**
	 * Ask a knowledge base question in case context.
	 *
	 * @param string $caseId The case ID for context
	 * @param string $question The user's question
	 * @param string $userId The current user ID
	 *
	 * @return array Answer with source citations
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function askQuestion(string $caseId, string $question, string $userId): array;

	/**
	 * Classify a document using AI.
	 *
	 * Sends document content to the configured AI model for classification.
	 * Returns a suggested document type with confidence score.
	 *
	 * @param string $caseId The case ID
	 * @param string $documentId The document ID to classify
	 * @param string $userId The current user ID
	 *
	 * @return array Classification result with suggestion and confidence
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function classifyDocument(string $caseId, string $documentId, string $userId): array;

	/**
	 * Extract structured data from case documents using AI.
	 *
	 * @param string $caseId The case ID
	 * @param string|null $documentId Optional specific document ID
	 * @param string $userId The current user ID
	 *
	 * @return array Extraction result with field suggestions and confidence
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function extractData(string $caseId, ?string $documentId, string $userId): array;

	/**
	 * Get AI settings for the frontend.
	 *
	 * @return array AI settings (without sensitive data like API keys)
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getAiSettings(): array;

	/**
	 * Suggest next steps for a case based on current state.
	 *
	 * @param string $caseId The case ID
	 * @param string $userId The current user ID
	 *
	 * @return array Next-step suggestions
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestNextStep(string $caseId, string $userId): array;

	/**
	 * Suggest case routing based on expertise and workload.
	 *
	 * @param string $caseId The case ID
	 * @param string $userId The current user ID
	 *
	 * @return array Routing suggestion with recommended case worker
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestRouting(string $caseId, string $userId): array;

	/**
	 * Generate a summary for a case, document, or timeline.
	 *
	 * @param string $caseId The case ID
	 * @param string $type Summary type: case, document, or timeline
	 * @param string|null $documentId Optional document ID for document summaries
	 * @param string $userId The current user ID
	 *
	 * @return array Summary text
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function summarize(string $caseId, string $type, ?string $documentId, string $userId): array;

	/**
	 * Test AI model connectivity.
	 *
	 * The result key is `healthy`, and BOTH branches carry a `message`. It used
	 * to be `success`, with a `message` only on the error branch, while the one
	 * caller — the admin tab's connection test — read `healthy` and `message`.
	 * A working model therefore rendered as a red note card with no text in it:
	 * `healthy` was undefined, so the ternary picked `error`, and there was no
	 * message to print. Renamed rather than aliased, because a second key
	 * meaning the same thing is how the two halves drifted apart in the first
	 * place.
	 *
	 * @return array{healthy: bool, status: string, model: string, message: string, responseTimeMs: int}
	 *                                                                                                   Health check result
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function testHealth(): array;
}//end interface
