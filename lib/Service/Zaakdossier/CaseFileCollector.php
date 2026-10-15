<?php

/**
 * Dossiq case file collector
 *
 * Stores bytes found elsewhere in a case's folder as a document on that
 * case, with where they were found (`provenance`, document-provenance
 * REQ-DPV-001). A collected e-mail also keeps its header fields in
 * `provenance.mail` (REQ-WRR-003), read by the MailHeaderReader.
 *
 * Generic: any case type that collects files from elsewhere uses it, and
 * nothing in it knows which procedure the case follows. Deciding WHETHER a
 * pick may be collected (a duplicate, a custodian, the caller's rights) is
 * the caller's job; this class only writes it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCP\Files\File;

/**
 * Writes collected bytes as a document on a case, with their provenance.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
 */
class CaseFileCollector {

	/**
	 * Constructor.
	 *
	 * @param DocumentRecordStore       $store       Records and joins in OpenRegister.
	 * @param DocumentProjectionService $projection  The case folder, and a record for a file in it.
	 * @param MailHeaderReader          $mailHeaders Reads a collected mail's header fields.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentRecordStore $store,
		private readonly DocumentProjectionService $projection,
		private readonly MailHeaderReader $mailHeaders,
	) {
	}//end __construct()

	/**
	 * Store the bytes in the case folder and write the provenance on the record the file gets.
	 *
	 * The node listener normally projects the record while the file is
	 * written; when it has not (the write ran as another user, or no listener
	 * ran), the file in the case folder is projected here, through the same
	 * service.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param string               $fileName   The file name.
	 * @param string               $content    The bytes.
	 * @param array<string, mixed> $provenance Where it was found.
	 * @param string               $mimeType   The MIME type the source gave, '' when none.
	 *
	 * @return string The document record uuid, '' when the file could not be written or no record resulted.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
	 */
	public function collect(string $caseId, string $fileName, string $content, array $provenance, string $mimeType = ''): string {
		$fileId = $this->store->storeFileOnObject(objectId: $caseId, fileName: $fileName, content: $content);
		if ($fileId <= 0) {
			return '';
		}

		$file = $this->storedFile(caseId: $caseId, fileId: $fileId);
		$record = $this->store->findRecord(fileId: $fileId);
		if ($record === null && $file !== null) {
			$record = $this->projection->projectNode(node: $file);
		}

		if ($record === null) {
			return '';
		}

		if ($this->mailHeaders->isMail(fileName: $fileName, mimeType: $mimeType) === true) {
			$provenance['mail'] = ['unreadable' => true];
			if ($file !== null) {
				$provenance['mail'] = $this->mailHeaders->read(file: $file);
			}
		}

		$record['provenance'] = $provenance;
		$recordId = $this->store->saveRecord(record: $record);
		$this->store->ensureJoin(caseId: $caseId, recordId: $recordId);

		return $recordId;
	}//end collect()

	/**
	 * The file just stored in the case folder.
	 *
	 * @param string $caseId The case uuid.
	 * @param int    $fileId The file id.
	 *
	 * @return File|null The file, null when the folder or the file is not found.
	 */
	private function storedFile(string $caseId, int $fileId): ?File {
		$folder = $this->projection->folderOf(objectId: $caseId);
		if ($folder === null) {
			return null;
		}

		foreach ($folder->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}//end storedFile()
}//end class
