<?php

/**
 * Dossiq Uploaded Document Writer
 *
 * Writes an uploaded document onto its case: the file on the case, the
 * informatieobject record it belongs to (the one the file already has, or a
 * new one), and the summary the upload answers with.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\DocumentUpload;
use OCA\Dossiq\Service\InformatieobjectStore;
use OCA\Dossiq\Service\UploadedDocumentProfile;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Writes an uploaded document and its record onto the case.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class UploadedDocumentWriter {

	/**
	 * Constructor.
	 *
	 * @param InformatieobjectMetadataNormaliser $normaliser     Coerces the keywords and the direction onto the schema.
	 * @param CorrespondentWriter                $correspondents Who a document came from and went to.
	 * @param DocumentRecordStore                $recordStore    Records and joins, and the file on the case.
	 * @param DossierRecords                     $records        Where the record goes, its classification and its saved uuid.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly InformatieobjectMetadataNormaliser $normaliser,
		private readonly CorrespondentWriter $correspondents,
		private readonly DocumentRecordStore $recordStore,
		private readonly DossierRecords $records,
	) {
	}//end __construct()

	/**
	 * Store an uploaded file on its case and save its record.
	 *
	 * The register is required first, then the case, the file name and the
	 * type; the classification is the submitted one or the type default.
	 *
	 * @param string               $caseId   The case (zaak) UUID the document is linked to.
	 * @param string               $fileName The original filename.
	 * @param string               $content  The raw binary file content.
	 * @param array<string, mixed> $metadata The submitted document metadata.
	 *
	 * @return array{id: string, summary: array<string, mixed>, correspondents: array<string, mixed>} The stored id,
	 *         the summary the upload answers with, and the resolved sender and recipients.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or required fields/config missing.
	 * @throws \InvalidArgumentException When the submitted level is less restrictive than the default.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function write(string $caseId, string $fileName, string $content, array $metadata): array {
		$store = $this->records->informatieobjects();

		$upload = new DocumentUpload(caseId: $caseId, fileName: $fileName, content: $content, metadata: $metadata);
		$type = $upload->requireType();
		$classification = $this->records->uploadClassification(type: $type, metadata: $metadata);

		$profile = $this->profileOf(upload: $upload, typed: [$type, $classification]);
		$informatieobject = $this->uploadedRecord(upload: $upload, profile: $profile);

		$infoId = $this->storeUploadedRecord(store: $store, upload: $upload, informatieobject: $informatieobject);

		return [
			'id' => $infoId,
			'summary' => $this->uploadSummary(infoId: $infoId, informatieobject: $informatieobject),
			'correspondents' => $profile->correspondents,
		];
	}//end write()

	/**
	 * The upload's type and classification, with who it came from and who it went to.
	 *
	 * @param DocumentUpload              $upload The upload: its case and metadata.
	 * @param array{0: string, 1: string} $typed  The resolved type and classification.
	 *
	 * @return UploadedDocumentProfile The profile.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	private function profileOf(DocumentUpload $upload, array $typed): UploadedDocumentProfile {
		// Who the document came from and who it went to, resolved against the
		// parties this case has. See CorrespondentWriter::resolveFor().
		$direction = $this->normaliser->direction(value: ($upload->metadata['direction'] ?? null));

		return new UploadedDocumentProfile(
			type: $typed[0],
			classification: $typed[1],
			direction: $direction,
			correspondents: $this->correspondentsOf(upload: $upload, direction: $direction),
		);
	}//end profileOf()

	/**
	 * Who the document came from and who it went to, resolved against the case's parties.
	 *
	 * @param DocumentUpload $upload    The upload: its case and metadata.
	 * @param string         $direction The normalised direction.
	 *
	 * @return array<string, mixed> The sender and recipients.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	private function correspondentsOf(DocumentUpload $upload, string $direction): array {
		return $this->correspondents->resolveFor(
			caseId: $upload->caseId,
			sender: ($upload->metadata['sender'] ?? null),
			recipients: ($upload->metadata['recipients'] ?? null),
			direction: $direction,
		);
	}//end correspondentsOf()

	/**
	 * The summary of an uploaded informatieobject.
	 *
	 * @param string               $infoId           The stored informatieobject id.
	 * @param array<string, mixed> $informatieobject The record as built for the upload, carrying the original filename.
	 *
	 * @return array<string, mixed> The created informatieobject summary.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	private function uploadSummary(string $infoId, array $informatieobject): array {
		return [
			'id' => $infoId,
			'title' => $informatieobject['title'],
			'fileName' => $informatieobject['fileName'],
			'status' => 'draft',
			'vertrouwelijkheidaanduiding' => $informatieobject['vertrouwelijkheidaanduiding'],
			'informatieobjecttype' => $informatieobject['informatieobjecttype'],
			'direction' => $informatieobject['direction'],
			'sender' => $informatieobject['sender'],
			'recipients' => $informatieobject['recipients'],
			'keywords' => $informatieobject['keywords'],
			'integrity' => $informatieobject['integrity'],
		];
	}//end uploadSummary()

	/**
	 * Store the file on the case and save its informatieobject record.
	 *
	 * Documents live on the case: the file is stored on the CASE first, so
	 * the node listener may already have projected a record for it by the
	 * time this runs; that record gets the metadata, and only when there is
	 * none is one created here.
	 *
	 * @param InformatieobjectStore $store Where the informatieobject record is saved.
	 * @param DocumentUpload $upload The upload: its case, file name and content.
	 * @param array<string, mixed> $informatieobject The record to store.
	 *
	 * @return string The informatieobject UUID.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	private function storeUploadedRecord(InformatieobjectStore $store, DocumentUpload $upload, array $informatieobject): string {
		[$record, $infoId] = $this->recordOfFile(upload: $upload, informatieobject: $informatieobject);

		return $this->savedRecordId(store: $store, record: [$record, $infoId]);
	}//end storeUploadedRecord()

	/**
	 * Store the upload's bytes on its case, and the record they belong to: the one the file already has, merged.
	 *
	 * @param DocumentUpload       $upload           The upload: its case, file name and bytes.
	 * @param array<string, mixed> $informatieobject The record as built for the upload.
	 *
	 * @return array{0: array<string, mixed>, 1: string} The record to save, and its id ('' for a new one).
	 */
	private function recordOfFile(DocumentUpload $upload, array $informatieobject): array {
		$fileId = $this->recordStore->storeFileOnObject(objectId: $upload->caseId, fileName: $upload->fileName, content: $upload->content);
		$existing = [];
		if ($fileId > 0) {
			$existing = ($this->recordStore->findRecord(fileId: $fileId) ?? []);
			$informatieobject['fileId'] = $fileId;
		}

		$infoId = (string)($existing['id'] ?? '');
		unset($existing['id'], $existing['@self']);

		return [array_merge($existing, $informatieobject), $infoId];
	}//end recordOfFile()

	/**
	 * Save the record, updating it when it has an id, and answer its id.
	 *
	 * @param InformatieobjectStore                     $store  The object service, register and informatieobject schema.
	 * @param array{0: array<string, mixed>, 1: string} $record The record, and its id ('' for a new one).
	 *
	 * @return string The record's id.
	 */
	private function savedRecordId(InformatieobjectStore $store, array $record): string {
		[$record, $infoId] = $record;
		$objectService = $store->objectService;
		$register = $store->register;
		$infoSchema = $store->infoSchema;
		if ($infoId !== '') {
			$objectService->saveObject(
				object: $record,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $infoSchema), uuid: $infoId)
			);
		}

		if ($infoId === '') {
			$saved = $objectService->saveObject(
				object: $record,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $infoSchema))
			);
			$infoId = $this->records->savedUuid(saved: $saved);
		}

		return $infoId;
	}//end savedRecordId()

	/**
	 * The record an uploaded document gets.
	 *
	 * The counterpart of {@see DocumentDefaults::forNewFile()}, which says the
	 * same thing for a file somebody dropped in the case folder. Split out so
	 * `uploadDocument()` reads as the steps of an upload rather than as one
	 * long literal in the middle of them.
	 *
	 * @param DocumentUpload $upload The upload: file name, bytes (for the size and the hash) and submitted metadata.
	 * @param UploadedDocumentProfile $profile The type, confidentiality, direction and correspondents already resolved.
	 *
	 * @return array<string, mixed> The record to store.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	private function uploadedRecord(DocumentUpload $upload, UploadedDocumentProfile $profile): array {
		$fileName = $upload->fileName;
		$content = $upload->content;
		$metadata = $upload->metadata;
		$correspondents = $profile->correspondents;
		$now = date('Y-m-d\TH:i:s');

		return [
			'title' => (string)($metadata['title'] ?? $fileName),
			'fileName' => $fileName,
			'bestandsomvang' => strlen($content),
			'format' => (string)($metadata['format'] ?? 'application/octet-stream'),
			'vertrouwelijkheidaanduiding' => $profile->classification,
			'auteur' => (string)($metadata['auteur'] ?? ''),
			'status' => 'draft',
			'informatieobjecttype' => $profile->type,
			'direction' => $profile->direction,
			'sender' => $correspondents['sender'],
			'recipients' => $correspondents['recipients'],
			'keywords' => $this->normaliser->keywords(value: ($metadata['keywords'] ?? null)),
			'creatiedatum' => (string)($metadata['creatiedatum'] ?? date('Y-m-d')),
			'bronorganisatie' => (string)($metadata['bronorganisatie'] ?? ''),
			'taal' => (string)($metadata['taal'] ?? 'nld'),
			'description' => (string)($metadata['description'] ?? $metadata['beschrijving'] ?? ''),
			'integrity' => [
				'algorithm' => 'sha256',
				'value' => hash('sha256', $content),
				'date' => $now,
			],
		];
	}//end uploadedRecord()
}//end class
