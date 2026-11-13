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
	 * The filinq calls, see {@see filinqSteps()}.
	 *
	 * @var FilinqRedaction|null
	 */
	private ?FilinqRedaction $filinqSteps = null;

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
		return $this->detectFor(run: new RedactionRun(caseId: $caseId, documentRef: $documentRef, userId: $userId));
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
		return $this->checkFor(run: new RedactionRun(caseId: $caseId, documentRef: $documentRef, userId: $userId), entities: $entities);
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
		return $this->redactFor(run: new RedactionRun(caseId: $caseId, documentRef: $documentRef, userId: $userId));
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
		return $this->uploadFor(
			run: new RedactionRun(caseId: $caseId, documentRef: $documentRef, userId: $userId),
			upload: new RedactedUpload(fileName: $fileName, content: $content)
		);
	}//end upload()

	/**
	 * Detect the passages of a partly public document, as detect() does.
	 *
	 * @param RedactionRun $run The step.
	 *
	 * @return array<string, mixed> The document, its text and the unchecked redaction record.
	 *
	 * @throws RefusedException When the document is not partly public, unreadable, or filinq cannot detect.
	 */
	private function detectFor(RedactionRun $run): array {
		$run->assessment = $this->partlyPublic(caseId: $run->caseId, documentRef: $run->documentRef);
		$this->readOriginal(run: $run);

		$found = $this->entities->fromFilinq(entities: $this->filinqSteps()->detect(fileId: $run->fileId));
		$run->text = ($this->text->extracted(document: ['fileId' => $run->fileId]) ?? '');
		$redaction = $this->detection(run: $run, found: $found);

		$this->assessments->saveRedaction(caseId: $run->caseId, documentRef: $run->documentRef, redaction: $redaction);

		return [
			'documentRef' => $run->documentRef,
			'title' => (string)($run->original['title'] ?? ($run->original['fileName'] ?? '')),
			'text' => $run->text,
			'redaction' => $redaction,
			'redactedDocumentRef' => (string)($run->assessment['redactedDocumentRef'] ?? ''),
		];
	}//end detectFor()

	/**
	 * The unchecked redaction record: filinq's passages and the assist's spans of the text.
	 *
	 * @param RedactionRun $run   The step, its text read.
	 * @param array<mixed> $found The passages filinq detected.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function detection(RedactionRun $run, array $found): array {
		$spans = $this->assist->spansFor(text: $run->text, documentRef: $run->documentRef);

		return [
			'entities' => $this->entities->merge($found, $this->entities->fromSpans(spans: (array)($spans['spans'] ?? []), text: $run->text)),
			'sourceFileId' => $run->fileId,
			'detectedBy' => $run->userId,
			'detectedAt' => gmdate('c'),
			'spanSource' => (string)($spans['source'] ?? ''),
		];
	}//end detection()

	/**
	 * Record the officer's check, as check() does.
	 *
	 * @param RedactionRun $run      The step.
	 * @param array<mixed> $entities The passages the officer checked and wants redacted.
	 *
	 * @return array<string, mixed> The redaction record.
	 *
	 * @throws RefusedException When the document is not partly public, the list is empty, or filinq cannot record the check.
	 */
	private function checkFor(RedactionRun $run, array $entities): array {
		$run->assessment = $this->partlyPublic(caseId: $run->caseId, documentRef: $run->documentRef);
		$this->readOriginal(run: $run);

		$checked = $this->checkedEntities(entities: $entities);
		$this->filinqSteps()->markChecked(run: $run, checked: $checked);

		$redaction = array_merge(
			(array)($run->assessment['redaction'] ?? []),
			['entities' => $checked, 'sourceFileId' => $run->fileId, 'checkedBy' => $run->userId, 'checkedAt' => gmdate('c')]
		);
		$this->assessments->saveRedaction(caseId: $run->caseId, documentRef: $run->documentRef, redaction: $redaction);

		return $redaction;
	}//end checkFor()

	/**
	 * The passages the officer checked; refused when there are none.
	 *
	 * @param array<mixed> $entities The passages as the officer sent them.
	 *
	 * @return array<mixed> The checked passages.
	 *
	 * @throws RefusedException When the list is empty.
	 */
	private function checkedEntities(array $entities): array {
		$checked = $this->entities->fromOfficer(entities: $entities);
		if ($checked === []) {
			throw new RefusedException(
				rule: 'nothing-to-redact',
				sentence: $this->l10n->t('Mark at least one passage to redact, or upload a redacted version.'),
				status: RefusedException::STATUS_UNPROCESSABLE
			);
		}

		return $checked;
	}//end checkedEntities()

	/**
	 * Have filinq write the redacted copy, as redact() does.
	 *
	 * @param RedactionRun $run The step.
	 *
	 * @return array{redactedDocumentRef: string, redaction: array<string, mixed>}
	 *
	 * @throws RefusedException When nothing was checked, the file changed, or filinq wrote no copy.
	 */
	private function redactFor(RedactionRun $run): array {
		$run->assessment = $this->partlyPublic(caseId: $run->caseId, documentRef: $run->documentRef);
		$this->assertChecked(run: $run);
		$this->readOriginal(run: $run);
		$this->assertCheckedThisFile(run: $run);

		$outcome = $this->filinqSteps()->anonymize(run: $run);
		$copyId = $this->recordCopy(run: $run, fileId: (int)$outcome['anonymizedFileId']);
		$this->markRedactedByFilinq(run: $run, outcome: $outcome);

		return $this->savedCopy(run: $run, copyId: $copyId);
	}//end redactFor()

	/**
	 * Add filinq's copy to the redaction record.
	 *
	 * @param RedactionRun         $run     The step, its record taken.
	 * @param array<string, mixed> $outcome What filinq answered.
	 *
	 * @return void
	 */
	private function markRedactedByFilinq(RedactionRun $run, array $outcome): void {
		$run->redaction = array_merge(
			$run->redaction,
			[
				'method' => 'filinq',
				'redactedFileId' => (int)$outcome['anonymizedFileId'],
				'redactedBy' => $run->userId,
				'redactedAt' => gmdate('c'),
				'detectionBackend' => ($outcome['detectionBackend'] ?? null),
			]
		);
	}//end markRedactedByFilinq()

	/**
	 * Take the stored redaction record; refuse when the officer has not checked its passages.
	 *
	 * @param RedactionRun $run The step, its assessment read.
	 *
	 * @return void
	 *
	 * @throws RefusedException When nothing was checked.
	 */
	private function assertChecked(RedactionRun $run): void {
		$run->redaction = (array)($run->assessment['redaction'] ?? []);
		$entities = (array)($run->redaction['entities'] ?? []);
		if ((string)($run->redaction['checkedBy'] ?? '') === '' || $entities === []) {
			throw new RefusedException(
				rule: 'not-checked',
				sentence: $this->l10n->t('Check the passages first: confirm the list before the redacted copy is made.')
			);
		}
	}//end assertChecked()

	/**
	 * Refuse when the passages were checked on another file than the original's.
	 *
	 * @param RedactionRun $run The step, its record taken and its file resolved.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the document changed after the check.
	 */
	private function assertCheckedThisFile(RedactionRun $run): void {
		if ((int)($run->redaction['sourceFileId'] ?? 0) !== $run->fileId) {
			throw new RefusedException(
				rule: 'checked-another-file',
				sentence: $this->l10n->t('The document changed after the passages were checked. Detect and check them again.')
			);
		}
	}//end assertCheckedThisFile()

	/**
	 * Record a version the officer redacted elsewhere, as upload() does.
	 *
	 * @param RedactionRun   $run    The step.
	 * @param RedactedUpload $upload The uploaded file.
	 *
	 * @return array{redactedDocumentRef: string, redaction: array<string, mixed>}
	 *
	 * @throws RefusedException When the document is not partly public, the upload is empty, or it cannot be stored.
	 */
	private function uploadFor(RedactionRun $run, RedactedUpload $upload): array {
		$run->assessment = $this->partlyPublic(caseId: $run->caseId, documentRef: $run->documentRef);
		$this->assertUploaded(upload: $upload);
		$run->original = $this->original(documentRef: $run->documentRef);

		$stored = $this->storedUpload(run: $run, upload: $upload);
		$run->redaction = array_merge(
			(array)($run->assessment['redaction'] ?? []),
			['method' => 'upload', 'redactedFileId' => $stored['fileId'], 'redactedBy' => $run->userId, 'redactedAt' => gmdate('c')]
		);

		return $this->savedCopy(run: $run, copyId: $stored['recordId']);
	}//end uploadFor()

	/**
	 * Refuse an empty upload.
	 *
	 * @param RedactedUpload $upload The uploaded file.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the upload is empty.
	 */
	private function assertUploaded(RedactedUpload $upload): void {
		if ($upload->content === '') {
			throw new RefusedException(
				rule: 'empty-upload',
				sentence: $this->l10n->t('Choose the redacted version to upload.'),
				status: RefusedException::STATUS_UNPROCESSABLE
			);
		}
	}//end assertUploaded()

	/**
	 * Store the uploaded version as a document of the case.
	 *
	 * @param RedactionRun   $run    The step, its original read.
	 * @param RedactedUpload $upload The uploaded file.
	 *
	 * @return array{fileId: int, recordId: string} The stored file and its informatieobject.
	 *
	 * @throws RefusedException When it cannot be stored.
	 */
	private function storedUpload(RedactionRun $run, RedactedUpload $upload): array {
		try {
			return $this->copies->storeUpload(caseId: $run->caseId, original: $run->original, fileName: $upload->fileName, content: $upload->content);
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'upload-not-stored',
				sentence: $this->l10n->t('The redacted version could not be stored. Try again.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}
	}//end storedUpload()

	/**
	 * Store the redaction record with its copy on the assessment, and answer both.
	 *
	 * @param RedactionRun $run    The step, its record written.
	 * @param string       $copyId The redacted copy's informatieobject.
	 *
	 * @return array{redactedDocumentRef: string, redaction: array<string, mixed>}
	 */
	private function savedCopy(RedactionRun $run, string $copyId): array {
		$this->assessments->saveRedaction(
			caseId: $run->caseId,
			documentRef: $run->documentRef,
			redaction: $run->redaction,
			redactedDocumentRef: $copyId
		);

		return ['redactedDocumentRef' => $copyId, 'redaction' => $run->redaction];
	}//end savedCopy()

	/**
	 * The filinq calls of a redaction, built on first use from the client and the sentences.
	 *
	 * @return FilinqRedaction
	 */
	private function filinqSteps(): FilinqRedaction {
		$this->filinqSteps ??= new FilinqRedaction(filinq: $this->filinq, l10n: $this->l10n);

		return $this->filinqSteps;
	}//end filinqSteps()

	/**
	 * Read the original and resolve its file.
	 *
	 * @param RedactionRun $run The step.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the document cannot be read or names no file.
	 */
	private function readOriginal(RedactionRun $run): void {
		$run->original = $this->original(documentRef: $run->documentRef);
		$run->fileId = $this->fileIdOf(documentRef: $run->documentRef, original: $run->original);
	}//end readOriginal()

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
	 * @param RedactionRun $run    The step: the case and the original's informatieobject.
	 * @param int          $fileId The copy's file id.
	 *
	 * @return string The copy's informatieobject id.
	 *
	 * @throws RefusedException When the copy cannot be recorded.
	 */
	private function recordCopy(RedactionRun $run, int $fileId): string {
		try {
			return $this->copies->recordFile(caseId: $run->caseId, original: $run->original, fileId: $fileId);
		} catch (RuntimeException $e) {
			throw new RefusedException(
				rule: 'copy-not-recorded',
				sentence: $this->l10n->t('Filinq made the redacted copy, but it could not be recorded on the case. Try again.'),
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e
			);
		}
	}//end recordCopy()

}//end class
