<?php

/**
 * Dossiq document redaction: a partly public document gets its redacted copy.
 *
 * A document assessed as partly public may only be published as its redacted
 * copy (`redactedDocumentRef` on the assessment). This service is the
 * redaction step that writes that reference, in one of two ways.
 *
 * Through filinq: detect, check, redact. Detection merges filinq's entities
 * with the rules floor and Hermiq's spans. The officer then confirms the list
 * they reviewed, and only that confirmation writes filinq's review mark, in
 * the officer's name, for exactly that list. The redaction asks filinq for
 * the copy of the same list; filinq refuses a list nobody checked.
 *
 * By upload: the officer uploads a version they redacted elsewhere.
 *
 * Either way the copy is recorded as an informatieobject of the case and the
 * assessment points at it. Nothing here names a procedure: any case type that
 * assesses documents as partly public uses it.
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
 * @spec openspec/specs/document-redaction/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Redaction;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\DocumentText;
use OCA\Dossiq\Service\FilinqRedactionClient;
use OCA\Dossiq\Service\WOOAnonymisationAssistService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\IL10N;
use RuntimeException;
use Throwable;

/**
 * Detects, checks and redacts a partly public document, or records an uploaded redacted version.
 *
 * @spec openspec/specs/document-redaction/spec.md
 */
class DocumentRedactionService {

	/**
	 * Constructor.
	 *
	 * @param WOODocumentAssessmentService  $assessments The assessments the redaction is recorded on.
	 * @param FilinqRedactionClient         $filinq      Detection, the review mark and the redaction.
	 * @param WOOAnonymisationAssistService $assist      The rules floor and Hermiq's spans.
	 * @param DocumentText                  $text        The text OpenRegister extracted from the file.
	 * @param WooCaseDocuments              $documents   The original's informatieobject.
	 * @param ZgwDocumentService            $zgw         Finds a legacy document's file by its name.
	 * @param RedactedCopyRecorder          $copies      Records the copy as a document of the case.
	 * @param RedactionEntities             $entities    One list of passages from every source.
	 * @param IL10N                         $l10n        The refusals, in the officer's language.
	 */
	public function __construct(
		private readonly WOODocumentAssessmentService $assessments,
		private readonly FilinqRedactionClient $filinq,
		private readonly WOOAnonymisationAssistService $assist,
		private readonly DocumentText $text,
		private readonly WooCaseDocuments $documents,
		private readonly ZgwDocumentService $zgw,
		private readonly RedactedCopyRecorder $copies,
		private readonly RedactionEntities $entities,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The partly public documents of a case and where each one's redaction stands.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> `{documentRef, title, redactedDocumentRef, redaction}` per document.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	public function forCase(string $caseId): array {
		$rows = [];
		foreach ($this->assessments->partlyPublicAssessments(caseId: $caseId) as $assessment) {
			$documentRef = (string)($assessment['documentRef'] ?? '');
			$original = ($this->documents->meta(documentId: $documentRef) ?? []);
			$rows[] = [
				'documentRef' => $documentRef,
				'title' => (string)($original['title'] ?? ($original['fileName'] ?? '')),
				'weigeringsgronden' => (array)($assessment['weigeringsgronden'] ?? []),
				'redactedDocumentRef' => (string)($assessment['redactedDocumentRef'] ?? ''),
				'redaction' => (array)($assessment['redaction'] ?? []),
			];
		}

		return $rows;
	}//end forCase()

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
	public function detect(string $caseId, string $documentRef, string $userId): array {
		$assessment = $this->partlyPublic(caseId: $caseId, documentRef: $documentRef);
		$original = $this->original(documentRef: $documentRef);
		$fileId = $this->fileIdOf(documentRef: $documentRef, original: $original);

		try {
			$found = $this->entities->fromFilinq(entities: $this->filinq->detect(fileId: $fileId));
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'detection-unavailable',
				sentence: $this->l10n->t('Filinq could not detect the passages of this document. Upload a redacted version instead.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}

		$text = ($this->text->extracted(document: ['fileId' => $fileId]) ?? '');
		$spans = $this->assist->spansFor(text: $text, documentRef: $documentRef);

		$redaction = [
			'entities' => $this->entities->merge($found, $this->entities->fromSpans(spans: (array)($spans['spans'] ?? []), text: $text)),
			'sourceFileId' => $fileId,
			'detectedBy' => $userId,
			'detectedAt' => gmdate('c'),
			'spanSource' => (string)($spans['source'] ?? ''),
		];

		$this->assessments->saveRedaction(caseId: $caseId, documentRef: $documentRef, redaction: $redaction);

		return [
			'documentRef' => $documentRef,
			'title' => (string)($original['title'] ?? ($original['fileName'] ?? '')),
			'text' => $text,
			'redaction' => $redaction,
			'redactedDocumentRef' => (string)($assessment['redactedDocumentRef'] ?? ''),
		];
	}//end detect()

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
	public function check(string $caseId, string $documentRef, array $entities, string $userId): array {
		$assessment = $this->partlyPublic(caseId: $caseId, documentRef: $documentRef);
		$fileId = $this->fileIdOf(documentRef: $documentRef, original: $this->original(documentRef: $documentRef));

		$checked = $this->entities->fromOfficer(entities: $entities);
		if ($checked === []) {
			throw new RefusedException(
				rule: 'nothing-to-redact',
				sentence: $this->l10n->t('Mark at least one passage to redact, or upload a redacted version.'),
				status: RefusedException::STATUS_UNPROCESSABLE
			);
		}

		try {
			$this->filinq->markChecked(fileId: $fileId, entities: $checked, checkedBy: $userId);
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'check-not-recorded',
				sentence: $this->l10n->t('Filinq could not record your check. Try again, or upload a redacted version.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}

		$redaction = array_merge(
			(array)($assessment['redaction'] ?? []),
			['entities' => $checked, 'sourceFileId' => $fileId, 'checkedBy' => $userId, 'checkedAt' => gmdate('c')]
		);
		$this->assessments->saveRedaction(caseId: $caseId, documentRef: $documentRef, redaction: $redaction);

		return $redaction;
	}//end check()

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
	public function redact(string $caseId, string $documentRef, string $userId): array {
		$assessment = $this->partlyPublic(caseId: $caseId, documentRef: $documentRef);
		$redaction = (array)($assessment['redaction'] ?? []);
		$entities = (array)($redaction['entities'] ?? []);
		if ((string)($redaction['checkedBy'] ?? '') === '' || $entities === []) {
			throw new RefusedException(
				rule: 'not-checked',
				sentence: $this->l10n->t('Check the passages first: confirm the list before the redacted copy is made.')
			);
		}

		$original = $this->original(documentRef: $documentRef);
		$fileId = $this->fileIdOf(documentRef: $documentRef, original: $original);
		if ((int)($redaction['sourceFileId'] ?? 0) !== $fileId) {
			throw new RefusedException(
				rule: 'checked-another-file',
				sentence: $this->l10n->t('The document changed after the passages were checked. Detect and check them again.')
			);
		}

		try {
			$outcome = $this->filinq->anonymize(fileId: $fileId, entities: $entities, userId: $userId);
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'redaction-failed',
				sentence: $this->l10n->t('Filinq could not make the redacted copy. Try again, or upload a redacted version.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}

		if (($outcome['status'] ?? '') !== 'redacted') {
			throw $this->refusalFor(status: (string)($outcome['status'] ?? ''));
		}

		$copyId = $this->recordCopy(caseId: $caseId, original: $original, fileId: (int)$outcome['anonymizedFileId']);
		$redaction = array_merge(
			$redaction,
			[
				'method' => 'filinq',
				'redactedFileId' => (int)$outcome['anonymizedFileId'],
				'redactedBy' => $userId,
				'redactedAt' => gmdate('c'),
				'detectionBackend' => ($outcome['detectionBackend'] ?? null),
			]
		);
		$this->assessments->saveRedaction(caseId: $caseId, documentRef: $documentRef, redaction: $redaction, redactedDocumentRef: $copyId);

		return ['redactedDocumentRef' => $copyId, 'redaction' => $redaction];
	}//end redact()

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
	public function upload(string $caseId, string $documentRef, string $fileName, string $content, string $userId): array {
		$assessment = $this->partlyPublic(caseId: $caseId, documentRef: $documentRef);
		if ($content === '') {
			throw new RefusedException(
				rule: 'empty-upload',
				sentence: $this->l10n->t('Choose the redacted version to upload.'),
				status: RefusedException::STATUS_UNPROCESSABLE
			);
		}

		$original = $this->original(documentRef: $documentRef);

		try {
			$stored = $this->copies->storeUpload(caseId: $caseId, original: $original, fileName: $fileName, content: $content);
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'upload-not-stored',
				sentence: $this->l10n->t('The redacted version could not be stored. Try again.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}

		$redaction = array_merge(
			(array)($assessment['redaction'] ?? []),
			['method' => 'upload', 'redactedFileId' => $stored['fileId'], 'redactedBy' => $userId, 'redactedAt' => gmdate('c')]
		);
		$this->assessments->saveRedaction(
			caseId: $caseId,
			documentRef: $documentRef,
			redaction: $redaction,
			redactedDocumentRef: $stored['recordId']
		);

		return ['redactedDocumentRef' => $stored['recordId'], 'redaction' => $redaction];
	}//end upload()

	/**
	 * The assessment of a document of this case that judged it partly public.
	 *
	 * @param string $caseId      The case.
	 * @param string $documentRef The document.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RefusedException When the document has no such assessment on this case.
	 */
	private function partlyPublic(string $caseId, string $documentRef): array {
		$assessment = $this->assessments->findAssessment(caseId: $caseId, documentRef: $documentRef);
		if ($assessment === null || (string)($assessment['classification'] ?? '') !== 'deels_openbaar') {
			throw new RefusedException(
				rule: 'not-partly-public',
				sentence: $this->l10n->t('Only a document assessed as disclosed in part is redacted.')
			);
		}

		return $assessment;
	}//end partlyPublic()

	/**
	 * The original's informatieobject.
	 *
	 * @param string $documentRef The document.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RefusedException When the document cannot be read.
	 */
	private function original(string $documentRef): array {
		$original = $this->documents->meta(documentId: $documentRef);
		if ($original === null) {
			throw new RefusedException(
				rule: 'document-unreadable',
				sentence: $this->l10n->t('This document could not be read.'),
				status: RefusedException::STATUS_INDETERMINATE
			);
		}

		return $original;
	}//end original()

	/**
	 * The Nextcloud file of the original.
	 *
	 * @param string               $documentRef The document.
	 * @param array<string, mixed> $original    Its informatieobject.
	 *
	 * @return int The file id.
	 *
	 * @throws RefusedException When the document names no file that exists.
	 */
	private function fileIdOf(string $documentRef, array $original): int {
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

	/**
	 * Record the copy filinq wrote.
	 *
	 * @param string               $caseId   The case.
	 * @param array<string, mixed> $original The original's informatieobject.
	 * @param int                  $fileId   The copy's file id.
	 *
	 * @return string The copy's informatieobject id.
	 *
	 * @throws RefusedException When the copy cannot be recorded.
	 */
	private function recordCopy(string $caseId, array $original, int $fileId): string {
		try {
			return $this->copies->recordFile(caseId: $caseId, original: $original, fileId: $fileId);
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'copy-not-recorded',
				sentence: $this->l10n->t('Filinq made the redacted copy, but it could not be recorded on the case. Try again.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}
	}//end recordCopy()

	/**
	 * The refusal for an outcome in which filinq wrote no redacted copy.
	 *
	 * @param string $status Filinq's outcome.
	 *
	 * @return RefusedException
	 */
	private function refusalFor(string $status): RefusedException {
		return match ($status) {
			'detection_unavailable' => new RefusedException(
				rule: 'detection-unavailable',
				sentence: $this->l10n->t(
					'Entity detection is off, so filinq makes no redacted copy. Ask an administrator to turn it on, or upload a redacted version.'
				),
				status: RefusedException::STATUS_INDETERMINATE
			),
			'not_checked' => new RefusedException(
				rule: 'not-checked',
				sentence: $this->l10n->t('Filinq has no check for these passages. Confirm the list again.')
			),
			default => new RefusedException(
				rule: 'no-redacted-copy',
				sentence: $this->l10n->t('Filinq wrote no redacted copy of this document. Upload a redacted version instead.')
			),
		};
	}//end refusalFor()
}//end class
