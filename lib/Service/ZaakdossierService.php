<?php

/**
 * Dossiq Zaakdossier Service
 *
 * Orchestrates the ZGW DRC case dossier: it stores uploaded documents as
 * Nextcloud files (via {@see ZgwDocumentService}) and as `informatieobject`
 * register objects carrying full ZGW metadata, links them to a case through a
 * `zaakinformatieobject` join, enforces the `concept -> definitief ->
 * gearchiveerd` status lifecycle, computes a SHA-256 integrity hash, lists a
 * dossier grouped by informatieobjecttype, and performs bulk status
 * transitions. Confidentiality is enforced by {@see InformatieobjectAccessGuard}
 * at the controller boundary.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\ZaakdossierServiceInterface;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\DossierRecords;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectMetadataNormaliser;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use OCA\Dossiq\Service\Zaakdossier\UploadedDocumentWriter;
use Psr\Log\LoggerInterface;

/**
 * Service orchestrating the ZGW DRC zaakdossier.
 *
 * The per-document status state machine is owned by
 * {@see InformatieobjectStatusLifecycle}; this service orchestrates the
 * dossier around it.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 */
class ZaakdossierService implements ZaakdossierServiceInterface {

	/**
	 * Valid informatieobject statuses.
	 *
	 * Canonically owned by {@see InformatieobjectStatusLifecycle}; aliased here
	 * so existing callers of `ZaakdossierService::VALID_STATUSES` keep working.
	 *
	 * @var string[]
	 */
	public const VALID_STATUSES = InformatieobjectStatusLifecycle::VALID_STATUSES;

	/**
	 * Allowed forward-only status transitions (from => [allowed-to, ...]).
	 *
	 * Canonically owned by {@see InformatieobjectStatusLifecycle}; aliased here
	 * for backwards compatibility.
	 *
	 * @var array<string, string[]>
	 */
	public const STATUS_TRANSITIONS = InformatieobjectStatusLifecycle::STATUS_TRANSITIONS;

	/**
	 * The values `informatieobject.direction` accepts.
	 *
	 * Canonically owned by {@see InformatieobjectMetadataNormaliser}; aliased
	 * here so a caller reading the dossier service finds the vocabulary it
	 * writes.
	 *
	 * @var string[]
	 */
	public const DIRECTIONS = InformatieobjectMetadataNormaliser::DIRECTIONS;

	/**
	 * The direction a document carries when nobody chose one.
	 *
	 * @var string
	 */
	public const DEFAULT_DIRECTION = InformatieobjectMetadataNormaliser::DEFAULT_DIRECTION;

	/**
	 * The longest a single keyword may be, per the schema's items.maxLength.
	 *
	 * @var int
	 */
	public const KEYWORD_MAX_LENGTH = InformatieobjectMetadataNormaliser::KEYWORD_MAX_LENGTH;

	/**
	 * The joins and informatieobject records of the dossier in the register.
	 *
	 * @var DossierRecords
	 */
	private readonly DossierRecords $records;

	/**
	 * Writes an uploaded document and its record onto the case.
	 *
	 * @var UploadedDocumentWriter
	 */
	private readonly UploadedDocumentWriter $uploads;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + ObjectService).
	 * @param InformatieobjectAccessGuard $accessGuard Classification access guard.
	 * @param InformatieobjectStatusLifecycle $statusLifecycle Per-document status state machine.
	 * @param InformatieobjectMetadataNormaliser $normaliser Coerces the freely
	 *                                                       typed keywords and
	 *                                                       direction onto the
	 *                                                       schema.
	 * @param LoggerInterface $logger Logger.
	 * @param DocumentRecordStore $recordStore Records and joins, and the file on the case.
	 * @param CorrespondentWriter $correspondents Who a document came from and went to.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		SettingsService $settingsService,
		InformatieobjectAccessGuard $accessGuard,
		private readonly InformatieobjectStatusLifecycle $statusLifecycle,
		private readonly InformatieobjectMetadataNormaliser $normaliser,
		private readonly LoggerInterface $logger,
		DocumentRecordStore $recordStore,
		private readonly CorrespondentWriter $correspondents,
	) {
		$this->records = new DossierRecords(settingsService: $settingsService, accessGuard: $accessGuard);
		$this->uploads = new UploadedDocumentWriter(
			normaliser: $normaliser,
			correspondents: $correspondents,
			recordStore: $recordStore,
			records: $this->records
		);
	}//end __construct()

	/**
	 * Upload a document, creating an informatieobject and a zaakinformatieobject join.
	 *
	 * @param string $caseId The case (zaak) UUID the document is linked to.
	 * @param string $fileName The original filename.
	 * @param string $content The raw binary file content.
	 * @param array<string, mixed> $metadata Document metadata (titel, informatieobjecttype,
	 *                                       vertrouwelijkheidaanduiding, auteur, beschrijving, …).
	 *
	 * @return array<string, mixed> The created informatieobject summary.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or required fields/config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function uploadDocument(string $caseId, string $fileName, string $content, array $metadata): array {
		$written = $this->uploads->write(caseId: $caseId, fileName: $fileName, content: $content, metadata: $metadata);

		$this->linkAndRecordDispatches(caseId: $caseId, infoId: $written['id'], correspondents: $written['correspondents']);

		return $written['summary'];
	}//end uploadDocument()

	/**
	 * Join the uploaded document to its case and record one dispatch per correspondent.
	 *
	 * @param string               $caseId         The case (zaak) UUID.
	 * @param string               $infoId         The stored informatieobject id.
	 * @param array<string, mixed> $correspondents The resolved sender and recipients.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	private function linkAndRecordDispatches(string $caseId, string $infoId, array $correspondents): void {
		// Create the case <-> document join, unless the listener already did.
		$this->linkExistingInformatieobject(caseId: $caseId, infoObjectId: $infoId);

		// One dispatch row per correspondent: the audit of the send itself.
		$this->correspondents->recordDispatches(caseId: $caseId, documentId: $infoId, correspondents: $correspondents);

		$this->logger->info(
			'Dossiq dossier: uploaded informatieobject ' . $infoId . ' for case ' . $caseId,
			['app' => AppIdentity::APP_ID],
		);
	}//end linkAndRecordDispatches()

	/**
	 * Link an existing informatieobject to a case without duplicating the document.
	 *
	 * Deduplicates: when the join already exists no second join is created.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed> The join summary.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function linkExistingInformatieobject(string $caseId, string $infoObjectId): array {
		return $this->records->link(caseId: $caseId, infoObjectId: $infoObjectId);
	}//end linkExistingInformatieobject()

	/**
	 * Unlink an informatieobject from a case, preserving the document itself.
	 *
	 * Only the `zaakinformatieobject` join records are deleted; the
	 * informatieobject record and the Nextcloud file remain intact.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return bool True when at least one join was removed.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function unlinkInformatieobject(string $caseId, string $infoObjectId): bool {
		return $this->records->unlink(caseId: $caseId, infoObjectId: $infoObjectId);
	}//end unlinkInformatieobject()

	/**
	 * Validate and apply a status transition, locking the document on definitief.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 * @param string $newStatus The requested status.
	 *
	 * @return array<string, mixed> The updated informatieobject summary.
	 *
	 * @throws \RuntimeException When OpenRegister/config is unavailable or status invalid.
	 * @throws \InvalidArgumentException When the transition is not permitted (caller maps to HTTP 400).
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function transitionStatus(string $infoObjectId, string $newStatus): array {
		return $this->statusLifecycle->transition(infoObjectId: $infoObjectId, newStatus: $newStatus);
	}//end transitionStatus()

	/**
	 * Determine whether a status transition is permitted (forward-only).
	 *
	 * @param string $from The current status.
	 * @param string $to The requested status.
	 *
	 * @return bool True when allowed.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function isTransitionAllowed(string $from, string $to): bool {
		return $this->statusLifecycle->isTransitionAllowed(from: $from, to: $to);
	}//end isTransitionAllowed()

	/**
	 * List the dossier for a case, grouped by informatieobjecttype with counts.
	 *
	 * The returned `informatieobjecten` list is NOT clearance-filtered here;
	 * the controller applies {@see InformatieobjectAccessGuard::filterDossierForUser()}
	 * so the service stays testable without a user context.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param string $correspondent Narrow to the documents naming this party, or '' for all.
	 *
	 * @return array<string, mixed> Structure with `total`, `groups` and `informatieobjecten`.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-015-the-correspondents-are-on-screen-and-can-be-filtered
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function getDossierForCase(string $caseId, string $correspondent = ''): array {
		$documents = $this->records->documentsOf(caseId: $caseId);

		// Every row carries who the document was from and to, named. The
		// parties are read once for the whole listing rather than once per
		// document, which is the difference between one OpenRegister read and
		// forty to draw one column.
		$described = $this->correspondents->describeAll(caseId: $caseId, documents: $documents, correspondent: $correspondent);

		return $this->groupByType(documents: $described);
	}//end getDossierForCase()

	/**
	 * Group a list of informatieobjecten by informatieobjecttype with counts.
	 *
	 * @param array<int, array<string, mixed>> $documents The documents to group.
	 *
	 * @return array<string, mixed> Grouped structure.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function groupByType(array $documents): array {
		$groups = [];
		foreach ($documents as $doc) {
			$type = (string)($doc['informatieobjecttype'] ?? 'unknown');
			if (isset($groups[$type]) === false) {
				$groups[$type] = [];
			}

			$groups[$type][] = $doc;
		}

		$result = [];
		foreach ($groups as $type => $docs) {
			$result[] = [
				'informatieobjecttype' => $type,
				'count' => count($docs),
				'documents' => $docs,
			];
		}

		return [
			'total' => count($documents),
			'groups' => $result,
			'informatieobjecten' => $documents,
		];
	}//end groupByType()

	/**
	 * Apply a bulk status transition, returning a per-id success/failure list.
	 *
	 * @param string[] $infoObjectIds The informatieobject UUIDs.
	 * @param string $newStatus The requested status.
	 *
	 * @return array<int, array<string, mixed>> Per-id results with `id`, `success`, optional `error`.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function bulkTransitionStatus(array $infoObjectIds, string $newStatus): array {
		return $this->statusLifecycle->transitionMany(infoObjectIds: $infoObjectIds, newStatus: $newStatus);
	}//end bulkTransitionStatus()

	/**
	 * Update editable metadata on an informatieobject (titel, beschrijving, type).
	 *
	 * Rejects mutation of a definitief document (caller maps to HTTP 409).
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 * @param array<string, mixed> $metadata Editable fields.
	 *
	 * @return array<string, mixed> The updated summary.
	 *
	 * @throws \RuntimeException When OpenRegister/config unavailable or document missing.
	 * @throws \DomainException When the document is definitief and therefore immutable.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function updateMetadata(string $infoObjectId, array $metadata): array {
		$current = $this->records->find(infoObjectId: $infoObjectId);

		$current = $this->statusLifecycle->requireEditable(current: $current, infoObjectId: $infoObjectId);

		$updateData = $this->normaliser->editableFields(metadata: $metadata);
		$updateData = $this->withCorrespondentEdit(infoObjectId: $infoObjectId, current: $current, metadata: $metadata, updateData: $updateData);

		if (empty($updateData) === true) {
			return ['id' => $infoObjectId, 'updated' => false];
		}

		// Only the edited fields, applied to the stored document: a bare
		// saveObject() with the uuid replaces the document with $updateData,
		// which the schema refuses for the required properties it drops.
		$this->records->patch(infoObjectId: $infoObjectId, changes: $updateData);

		return array_merge(['id' => $infoObjectId, 'updated' => true], $updateData);
	}//end updateMetadata()

	/**
	 * The update data with the correspondent edit merged in.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 * @param array<string, mixed> $current The stored document.
	 * @param array<string, mixed> $metadata Editable fields as submitted.
	 * @param array<string, mixed> $updateData The editable fields picked so far.
	 *
	 * @return array<string, mixed> The update data.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	private function withCorrespondentEdit(string $infoObjectId, array $current, array $metadata, array $updateData): array {
		return array_merge(
			$updateData,
			$this->correspondents->applyEdit(
				documentId: $infoObjectId,
				current: $current,
				metadata: $metadata,
				direction: (string)($updateData['direction'] ?? ($current['direction'] ?? '')),
			)
		);
	}//end withCorrespondentEdit()

	/**
	 * Fetch a single informatieobject as an array.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed>|null The document or null when not found.
	 *
	 * @throws \RuntimeException When OpenRegister/config unavailable.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function getInformatieobject(string $infoObjectId): ?array {
		return $this->records->find(infoObjectId: $infoObjectId);
	}//end getInformatieobject()

	/**
	 * Resolve the default classification for an informatieobjecttype.
	 *
	 * @param string $type The informatieobjecttype UUID/slug.
	 *
	 * @return string The default vertrouwelijkheidaanduiding ('intern' fallback).
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function resolveDefaultClassification(string $type): string {
		return $this->records->defaultClassification(type: $type);
	}//end resolveDefaultClassification()

}//end class
