<?php

/**
 * Dossiq redacted copy recorder: the informatieobject of a redacted copy.
 *
 * A redacted copy is a document of the case like any other: an
 * informatieobject carrying the Nextcloud file id, joined to the case. The
 * case folder's node listener may already have written that record when the
 * file appeared, so the record is looked up by file id first and written only
 * when there is none. The copy takes its document type and confidentiality
 * from the original, because it is the same document with passages removed.
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
 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redacted-copy-is-recorded-as-a-document-of-the-case-req-drd-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Redaction;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\FindsFilesById;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use RuntimeException;

/**
 * Records a redacted copy as an informatieobject of the case.
 *
 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redacted-copy-is-recorded-as-a-document-of-the-case-req-drd-003
 */
class RedactedCopyRecorder {

	use FindsFilesById;

	/**
	 * Fields the copy takes over from the original's record.
	 *
	 * @var array<int, string>
	 */
	private const INHERITED = ['informatieobjecttype', 'vertrouwelijkheidaanduiding', 'bronorganisatie', 'taal'];

	/**
	 * Constructor.
	 *
	 * @param DocumentRecordStore  $store           Reads and writes informatieobjecten and their case joins.
	 * @param IRootFolder          $rootFolder      Reads the copy's file by its id, outside OpenRegister's tree.
	 * @param SettingsService|null $settingsService OpenRegister's file service, which reads the copy on the case.
	 * @param RestrictedDocumentReaders|null $readers Gives the copy the original's access exception. Null only in legacy fixtures.
	 */
	public function __construct(
		private readonly DocumentRecordStore $store,
		private readonly IRootFolder $rootFolder,
		private readonly ?SettingsService $settingsService = null,
		private readonly ?RestrictedDocumentReaders $readers = null,
	) {
	}//end __construct()

	/**
	 * Record a file that already exists as the redacted copy of an original.
	 *
	 * @param string               $caseId   The case.
	 * @param array<string, mixed> $original The original's informatieobject.
	 * @param int                  $fileId   The copy's Nextcloud file id.
	 *
	 * @return string The copy's informatieobject id.
	 *
	 * @throws RuntimeException When the file cannot be found or the record not written.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redacted-copy-is-recorded-as-a-document-of-the-case-req-drd-003
	 */
	public function recordFile(string $caseId, array $original, int $fileId): string {
		$recordId = (string)($this->store->findRecord(fileId: $fileId)['id'] ?? '');
		if ($recordId === '') {
			$recordId = $this->store->saveRecord(record: $this->recordFor(original: $original, file: $this->file(fileId: $fileId)));
		}

		if ($recordId === '') {
			throw new RuntimeException('copy_not_recorded: the redacted copy got no informatieobject');
		}

		$this->store->ensureJoin(caseId: $caseId, recordId: $recordId);

		// A copy of a document only some people may see is seen by the same people.
		$this->readers?->inherit(original: $original, fileId: $fileId);

		return $recordId;
	}//end recordFile()

	/**
	 * Store an uploaded redacted version on the case and record it.
	 *
	 * The file is named after the original with `_gelakt` and the moment of
	 * upload, so it never overwrites the original or an earlier version.
	 *
	 * @param string               $caseId   The case.
	 * @param array<string, mixed> $original The original's informatieobject.
	 * @param string               $fileName The uploaded file's name, for its extension.
	 * @param string               $content  The uploaded bytes.
	 *
	 * @return array{fileId: int, recordId: string} The stored file and its informatieobject.
	 *
	 * @throws RuntimeException When the file cannot be stored or recorded.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-an-officer-can-upload-a-redacted-version-instead-req-drd-004
	 */
	public function storeUpload(string $caseId, array $original, string $fileName, string $content): array {
		$base = pathinfo((string)($original['fileName'] ?? ($original['title'] ?? 'document')), PATHINFO_FILENAME);
		$extension = pathinfo($fileName, PATHINFO_EXTENSION);
		$name = $base . '_gelakt_' . gmdate('Ymd\THis');
		if ($extension !== '') {
			$name .= '.' . $extension;
		}

		$fileId = $this->store->storeFileOnObject(objectId: $caseId, fileName: $name, content: $content);
		if ($fileId <= 0) {
			throw new RuntimeException('upload_not_stored: the uploaded version could not be read back');
		}

		return ['fileId' => $fileId, 'recordId' => $this->recordFile(caseId: $caseId, original: $original, fileId: $fileId)];
	}//end storeUpload()

	/**
	 * The record a copy gets when nothing recorded it yet.
	 *
	 * @param array<string, mixed> $original The original's informatieobject.
	 * @param File                 $file     The copy's file.
	 *
	 * @return array<string, mixed>
	 */
	private function recordFor(array $original, File $file): array {
		$record = [
			'title' => pathinfo($file->getName(), PATHINFO_FILENAME),
			'fileName' => $file->getName(),
			'fileId' => $file->getId(),
			'bestandsomvang' => $file->getSize(),
			'format' => $file->getMimeType(),
			'status' => 'draft',
			'creatiedatum' => gmdate('Y-m-d'),
			'description' => 'Redacted copy of ' . (string)($original['title'] ?? ($original['fileName'] ?? '')),
		];

		foreach (self::INHERITED as $field) {
			if (($original[$field] ?? '') !== '') {
				$record[$field] = $original[$field];
			}
		}

		return $record;
	}//end recordFor()

	/**
	 * The copy's file.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return File
	 *
	 * @throws RuntimeException When no file has that id.
	 */
	private function file(int $fileId): File {
		$file = $this->fileById(fileService: $this->settingsService?->getFileService(), rootFolder: $this->rootFolder, fileId: $fileId);
		if ($file === null) {
			throw new RuntimeException('copy_file_missing: no file ' . $fileId);
		}

		return $file;
	}//end file()
}//end class
