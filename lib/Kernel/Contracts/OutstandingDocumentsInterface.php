<?php

/**
 * Contract of OutstandingDocuments
 *
 * The public surface of OutstandingDocuments that other components call, so they depend
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
 * Contract of OutstandingDocuments.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OutstandingDocumentsInterface {

	/**
	 * The outstanding documents of a case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{count: int, documents: array<int, array{id: string, title: string}>}
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
	 */
	public function forCase(string $caseId): array;

	/**
	 * The title of one document, or '' when no record carries one.
	 *
	 * The case file's record first, then the schema documents were filed in
	 * before the case file existed. A document whose record is gone keeps its
	 * place in the list with an empty title: it is still outstanding. A
	 * register that cannot answer is not caught here, so the route says 503
	 * instead of a list of untitled documents.
	 *
	 * @param string $documentId The document uuid.
	 *
	 * @return string The title, the file name when there is no title, or ''.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
	 */
	public function titleOf(string $documentId): string;
}//end interface
