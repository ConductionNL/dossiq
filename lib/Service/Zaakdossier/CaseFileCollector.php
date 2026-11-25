<?php

/**
 * Dossiq case file collector
 *
 * Stores bytes found elsewhere in a case's folder as a document on that
 * case, with where they were found (`provenance`, document-provenance
 * REQ-DPV-001). A collected e-mail also keeps its header fields in
 * `provenance.mail` (REQ-WRR-003), read by the MailHeaderReader, and the
 * conversation it belongs to (`threadId`, REQ-WRC-006), placed by CaseMailThreads.
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

use OCA\Dossiq\Kernel\Contracts\CaseFileCollectorInterface;
use OCP\Files\File;

/**
 * Writes collected bytes as a document on a case, with their provenance.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
 */
class CaseFileCollector implements CaseFileCollectorInterface {

	/**
	 * Constructor.
	 *
	 * @param DocumentRecordStore       $store       Records and joins in OpenRegister.
	 * @param DocumentProjectionService $projection  The case folder, and a record for a file in it.
	 * @param MailHeaderReader          $mailHeaders Reads a collected mail's header fields.
	 * @param CaseMailThreads           $mailThreads Places a collected mail in its conversation (REQ-WRC-006).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentRecordStore $store,
		private readonly DocumentProjectionService $projection,
		private readonly MailHeaderReader $mailHeaders,
		private readonly CaseMailThreads $mailThreads,
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
	 * @spec openspec/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 */
	public function collect(string $caseId, string $fileName, string $content, array $provenance, string $mimeType = ''): string {
		$collected = ['caseId' => $caseId, 'fileName' => $fileName, 'content' => $content, 'mimeType' => $mimeType];
		$stored = $this->storedRecord(caseId: $caseId, file: $collected);
		if ($stored === null) {
			return '';
		}

		[$record, $file] = $stored;
		$provenance = $this->withMailProvenance(provenance: $provenance, collected: $collected + ['file' => $file]);

		return $this->saveProvenance(record: array_replace($record, ['provenance' => $provenance]), caseId: $caseId);
	}//end collect()

	/**
	 * The provenance, with the mail headers and the conversation when the collected file is a mail.
	 *
	 * @param array<string, mixed> $provenance Where it was found.
	 * @param array<string, mixed> $collected  The `caseId`, `fileName`, `content` and `mimeType` collected, and the stored `file`.
	 *
	 * @return array<string, mixed> The provenance.
	 */
	private function withMailProvenance(array $provenance, array $collected): array {
		if ($this->mailHeaders->isMail(fileName: $collected['fileName'], mimeType: $collected['mimeType']) === true) {
			$provenance['mail'] = $this->mailOf(file: $collected['file']);
			$provenance = array_replace(
				$provenance,
				$this->mailThreads->conversationOf(caseId: $collected['caseId'], fileName: $collected['fileName'], bytes: $collected['content'])
			);
		}

		return $provenance;
	}//end withMailProvenance()

	/**
	 * Store the bytes and find, or project, the record the file gets.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $file   The `fileName` and the bytes as `content`.
	 *
	 * @return array{0: array<string, mixed>, 1: File|null}|null The record and the stored file, null when either write failed.
	 */
	private function storedRecord(string $caseId, array $file): array|null {
		$fileId = $this->store->storeFileOnObject(objectId: $caseId, fileName: $file['fileName'], content: $file['content']);
		if ($fileId <= 0) {
			return null;
		}

		$file = $this->storedFile(caseId: $caseId, fileId: $fileId);
		$record = $this->store->findRecord(fileId: $fileId);
		if ($record === null && $file !== null) {
			$record = $this->projection->projectNode(node: $file);
		}

		if ($record === null) {
			return null;
		}

		return [$record, $file];
	}//end storedRecord()

	/**
	 * The header fields of a collected mail, or a marker when the file is not in hand.
	 *
	 * @param File|null $file The stored file.
	 *
	 * @return array<string, mixed>
	 */
	private function mailOf(File|null $file): array {
		$mail = ['unreadable' => true];
		if ($file !== null) {
			$mail = $this->mailHeaders->read(file: $file);
		}

		return $mail;
	}//end mailOf()

	/**
	 * Save the record with its provenance and join it to the case.
	 *
	 * @param array<string, mixed> $record The document record, with where it was found as `provenance`.
	 * @param string               $caseId The case uuid.
	 *
	 * @return string The document record uuid.
	 */
	private function saveProvenance(array $record, string $caseId): string {
		$recordId = $this->store->saveRecord(record: $record);
		$this->store->ensureJoin(caseId: $caseId, recordId: $recordId);

		return $recordId;
	}//end saveProvenance()

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
