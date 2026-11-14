<?php

/**
 * Contract of DocumentRedactionService
 *
 * The public surface of DocumentRedactionService that other components call, so they depend
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
 * Contract of DocumentRedactionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentRedactionServiceInterface {

	/**
	 * Record that the officer checked this list of passages, in filinq and on the assessment.
	 *
	 * @param string       $caseId      The case.
	 * @param string       $documentRef The partly public document.
	 * @param array<mixed> $entities    The passages the officer checked and wants redacted.
	 * @param string       $userId      The officer.
	 *
	 * @return array<string, mixed> The redaction record.
	 *
	 * @throws RefusedException When the document is not partly public, the list is empty, or filinq cannot record the check.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-a-redacted-copy-is-written-only-after-a-named-officer-checked-the-entities-req-drd-002
	 */
	public function check(string $caseId, string $documentRef, array $entities, string $userId): array;

	/**
	 * Detect the passages to redact and store them, unchecked, on the assessment.
	 *
	 * A new detection replaces the list and drops an earlier check: the check
	 * was about the earlier list.
	 *
	 * @param string $caseId      The case.
	 * @param string $documentRef The partly public document.
	 * @param string $userId      Who asked.
	 *
	 * @return array<string, mixed> `{documentRef, title, text, redaction, redactedDocumentRef}`.
	 *
	 * @throws RefusedException When the document is not partly public, has no file, or filinq cannot detect.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	public function detect(string $caseId, string $documentRef, string $userId): array;

	/**
	 * The partly public documents of a case and where each one's redaction stands.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> `{documentRef, title, redactedDocumentRef, redaction}` per document.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	public function forCase(string $caseId): array;

	/**
	 * Have filinq write the redacted copy of the checked list, and record it.
	 *
	 * @param string $caseId      The case.
	 * @param string $documentRef The partly public document.
	 * @param string $userId      The officer.
	 *
	 * @return array{redactedDocumentRef: string, redaction: array<string, mixed>}
	 *
	 * @throws RefusedException When nothing was checked, the file changed, or filinq wrote no copy.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-a-redacted-copy-is-written-only-after-a-named-officer-checked-the-entities-req-drd-002
	 */
	public function redact(string $caseId, string $documentRef, string $userId): array;

	/**
	 * Record a version the officer redacted elsewhere as the redacted copy.
	 *
	 * @param string $caseId      The case.
	 * @param string $documentRef The partly public document.
	 * @param string $fileName    The uploaded file's name.
	 * @param string $content     The uploaded bytes.
	 * @param string $userId      The officer.
	 *
	 * @return array{redactedDocumentRef: string, redaction: array<string, mixed>}
	 *
	 * @throws RefusedException When the document is not partly public, the upload is empty, or it cannot be stored.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-an-officer-can-upload-a-redacted-version-instead-req-drd-004
	 */
	public function upload(string $caseId, string $documentRef, string $fileName, string $content, string $userId): array;
}//end interface
