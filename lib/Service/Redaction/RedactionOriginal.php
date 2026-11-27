<?php

/**
 * Dossiq redaction original.
 *
 * Reads the original of a document being redacted: its informatieobject,
 * refused when it cannot be read, and the Nextcloud file behind it, by id
 * or, for a legacy document, by its name. Split out of
 * {@see \OCA\Dossiq\Service\Redaction\DocumentRedactionService}, which
 * keeps the assessment, the detection, the check, the redaction and the
 * upload.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Redaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-redaction/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Redaction;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\WooCaseDocumentsInterface;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\IL10N;
use Throwable;

/**
 * The informatieobject and the file of the document being redacted.
 *
 * @spec openspec/specs/document-redaction/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class RedactionOriginal {

	/**
	 * Constructor.
	 *
	 * @param WooCaseDocumentsInterface $documents The original's informatieobject.
	 * @param ZgwDocumentService        $zgw       Finds a legacy document's file by its name.
	 * @param IL10N                     $l10n      The refusals, in the officer's language.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly WooCaseDocumentsInterface $documents,
		private readonly ZgwDocumentService $zgw,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The document's informatieobject, or null when it cannot be read.
	 *
	 * @param string $documentRef The document.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/document-redaction/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function meta(string $documentRef): ?array {
		return $this->documents->meta(documentId: $documentRef);
	}//end meta()

	/**
	 * The original's informatieobject.
	 *
	 * @param string $documentRef The document.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RefusedException When the document cannot be read.
	 *
	 * @spec openspec/specs/document-redaction/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function read(string $documentRef): array {
		$original = $this->meta(documentRef: $documentRef);
		if ($original === null) {
			throw new RefusedException(
				rule: 'document-unreadable',
				sentence: $this->l10n->t('This document could not be read.'),
				status: RefusedException::STATUS_INDETERMINATE
			);
		}

		return $original;
	}//end read()

	/**
	 * The Nextcloud file of the original.
	 *
	 * @param string               $documentRef The document.
	 * @param array<string, mixed> $original    Its informatieobject.
	 *
	 * @return int The file id.
	 *
	 * @throws RefusedException When the document names no file that exists.
	 *
	 * @spec openspec/specs/document-redaction/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function fileIdOf(string $documentRef, array $original): int {
		$fileId = (int)($original['fileId'] ?? 0);
		if ($fileId > 0) {
			return $fileId;
		}

		$fileName = (string)($original['fileName'] ?? ($original['bestandsnaam'] ?? ''));
		$missing = null;
		if ($fileName !== '') {
			try {
				return $this->zgw->getFileId(uuid: $documentRef, fileName: $fileName);
			} catch (Throwable $e) {
				$missing = $e;
			}
		}

		throw new RefusedException(
			rule: 'file-unresolved',
			sentence: $this->l10n->t('This document has no file to redact. Upload a redacted version instead.'),
			status: RefusedException::STATUS_UNPROCESSABLE,
			previous: $missing
		);
	}//end fileIdOf()
}//end class
