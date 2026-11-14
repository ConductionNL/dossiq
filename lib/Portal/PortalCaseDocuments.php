<?php

/**
 * The documents a resident may see on their own case in the portal.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\UnscopedObjectReader;
use OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers portaliq's `documents` provider method for one case (dossiq#3205).
 *
 * DOSSIQ DECIDES WHAT IS PUBLISHED, and the rule is written here once. A
 * document on the case (an `informatieobject` joined to the case through a
 * `zaakinformatieobject`) reaches the resident only when all three hold:
 *  - its `status` is `final` or `archived`: a draft is still being written,
 *    and an archived document was final before it was archived;
 *  - its confidentiality is one the parties to a case may read
 *    (`openbaar`, `beperkt_openbaar`, `zaakvertrouwelijk`); `intern` and
 *    everything stricter stay inside;
 *  - it is the decision, or the organisation sent it (`direction`
 *    `outgoing`). An incoming letter can be a third party's, a neighbour's
 *    zienswijze for instance, and the resident's own uploads portaliq lists
 *    by itself under "Sent by you".
 * A document a `decisionDocument` links to a decision on this case is
 * returned with `kind: decision` and the decision's date.
 *
 * THE READ RUNS AS THE SYSTEM AND UNSCOPED, for this one case only. Portaliq
 * calls the method after it proved the case is the resident's, in a request
 * with no Nextcloud user, where OpenRegister's RBAC would answer nothing, or
 * signed in as someone reading the case under a mandate, whom RBAC does not
 * know as the case's party. The case id is the only input and every read is
 * filtered on it.
 *
 * The file reference names the CASE object, because the document's bytes live
 * in the case's folder (DocumentRecordStore::storeFileOnObject). Portaliq
 * never sends it to the browser; it streams only an entry this answered.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalCaseDocuments {
	use SearchesObjects;

	/**
	 * The document statuses a resident may see: settled, never a draft.
	 *
	 * @var array<int, string>
	 */
	public const PUBLISHED_STATUSES = ['final', 'archived'];

	/**
	 * The confidentiality levels a party to the case may read.
	 *
	 * @var array<int, string>
	 */
	public const READABLE_CONFIDENTIALITY = ['openbaar', 'beperkt_openbaar', 'zaakvertrouwelijk'];

	/**
	 * Reads each document unscoped.
	 *
	 * @var UnscopedObjectReader
	 */
	private readonly UnscopedObjectReader $reader;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface           $settingsService OpenRegister access and the configured register and schemas.
	 * @param LoggerInterface           $logger          Logger.
	 * @param UnscopedObjectReader|null $reader          Reads unscoped; a fresh one when null.
	 *
	 * @spec openspec/changes/mandated-case-steps-read-unscoped/specs/portal-contribution/spec.md#requirement-a-case-read-under-a-mandate-shows-its-steps-and-documents-req-portal-023
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
		?UnscopedObjectReader $reader = null,
	) {
		$this->reader = ($reader ?? new UnscopedObjectReader());
	}//end __construct()

	/**
	 * The entries a resident may see on one case, in portaliq's shape.
	 *
	 * @param string $caseId The case, already proven to be the resident's.
	 *
	 * @return array<int, array<string, mixed>> `{id, title, kind, date, file, mimeType?, size?}` per document.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function forCase(string $caseId): array {
		$read = (new PortalCaseReads(settingsService: $this->settingsService))->open(caseId: $caseId);
		if ($read === null) {
			return [];
		}

		try {
			return (array)$this->runAsSystemIfAvailable(
				objectService: $read->objectService,
				operation: fn (): array => $this->entries(read: $read)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the portal documents of a case could not be read', ['reason' => $e->getMessage()]);
			return [];
		}
	}//end forCase()

	/**
	 * Whether a document on the case may reach the resident.
	 *
	 * @param array<string, mixed> $document   The informatieobject.
	 * @param bool                 $isDecision Whether a decision on the case links it.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
	 */
	public function isPublished(array $document, bool $isDecision): bool {
		if (in_array(($document['status'] ?? ''), self::PUBLISHED_STATUSES, true) === false) {
			return false;
		}

		if (in_array(($document['vertrouwelijkheidaanduiding'] ?? ''), self::READABLE_CONFIDENTIALITY, true) === false) {
			return false;
		}

		// A document that names its only readers never reaches the resident, who is never one of them.
		if ((new AccessExceptionValue())->exceptionOf(record: $document) !== null) {
			return false;
		}

		return $isDecision === true || ($document['direction'] ?? '') === 'outgoing';
	}//end isPublished()

	/**
	 * The entries, read inside the system context.
	 *
	 * @param PortalCaseRead $read The case being read.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/mandated-case-steps-read-unscoped/specs/portal-contribution/spec.md#requirement-a-case-read-under-a-mandate-shows-its-steps-and-documents-req-portal-023
	 */
	private function entries(PortalCaseRead $read): array {
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		if ($caseSchema === '' || $infoSchema === '' || $joinSchema === '') {
			return [];
		}

		$run = new PortalDocumentsRun();
		$run->read = $read;
		$run->infoSchema = $infoSchema;
		$run->file = ['register' => $read->register, 'schema' => $caseSchema, 'id' => $read->caseId];
		$run->decisionDates = $this->decisionDocuments(read: $read);

		$joins = $this->searchObjectsAsArraysUnscoped(
			objectService: $read->objectService,
			register: $read->register,
			schema: $joinSchema,
			filters: ['case' => $read->caseId, '_limit' => 500]
		);

		return $this->entriesOf(run: $run, joins: $joins);
	}//end entries()

	/**
	 * One entry per document the joins of this case link, once each, in join order.
	 *
	 * A join of another case is never trusted.
	 *
	 * @param PortalDocumentsRun               $run   The listing.
	 * @param array<int, array<string, mixed>> $joins The zaakinformatieobject rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function entriesOf(PortalDocumentsRun $run, array $joins): array {
		$entries = [];
		foreach ($joins as $join) {
			$infoId = (string)($join['informatieobject'] ?? '');
			if ($infoId === '' || ($join['case'] ?? '') !== $run->read->caseId) {
				continue;
			}

			$entry = $this->entry(run: $run, infoId: $infoId);
			if ($entry !== null) {
				$entries[$infoId] = $entry;
			}
		}

		return array_values($entries);
	}//end entriesOf()

	/**
	 * One entry, or null when the document may not reach the resident.
	 *
	 * @param PortalDocumentsRun $run    The listing.
	 * @param string             $infoId The informatieobject's uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function entry(PortalDocumentsRun $run, string $infoId): ?array {
		$document = (array)$this->reader->find(
			objectService: $run->read->objectService,
			register: $run->read->register,
			schema: $run->infoSchema,
			id: $infoId
		);
		$decisionDate = ($run->decisionDates[$infoId] ?? null);
		$fileId = (int)($document['fileId'] ?? 0);
		$title = trim((string)($document['title'] ?? ''));
		if ($fileId <= 0 || $title === '' || $this->isPublished(document: $document, isDecision: $decisionDate !== null) === false) {
			return null;
		}

		$entry = [
			'id' => $infoId,
			'title' => $title,
			'kind' => $this->kindOf(decisionDate: $decisionDate),
			'date' => $this->dateOf(document: $document, decisionDate: $decisionDate),
			'file' => $run->file + ['fileId' => (string)$fileId],
		];

		return $this->withFileDetails(entry: $entry, document: $document);
	}//end entry()

	/**
	 * A document a decision links is a decision; anything else is a document.
	 *
	 * @param string|null $decisionDate The decision's date when a decision links it, else null.
	 *
	 * @return string `decision` or `document`.
	 */
	private function kindOf(?string $decisionDate): string {
		if ($decisionDate === null) {
			return 'document';
		}

		return 'decision';
	}//end kindOf()

	/**
	 * The decision's date for a decision that has one, else the document's creation date.
	 *
	 * @param array<string, mixed> $document     The informatieobject.
	 * @param string|null          $decisionDate The decision's date when a decision links it, else null.
	 *
	 * @return string
	 */
	private function dateOf(array $document, ?string $decisionDate): string {
		if ($decisionDate === null || $decisionDate === '') {
			return (string)($document['creatiedatum'] ?? '');
		}

		return $decisionDate;
	}//end dateOf()

	/**
	 * Add the file's mime type and size when the document carries them.
	 *
	 * @param array<string, mixed> $entry    The entry.
	 * @param array<string, mixed> $document The informatieobject.
	 *
	 * @return array<string, mixed>
	 */
	private function withFileDetails(array $entry, array $document): array {
		if (is_string(($document['format'] ?? null)) === true && $document['format'] !== '') {
			$entry['mimeType'] = $document['format'];
		}

		if (is_int(($document['bestandsomvang'] ?? null)) === true) {
			$entry['size'] = $document['bestandsomvang'];
		}

		return $entry;
	}//end withFileDetails()

	/**
	 * The documents the decisions on this case link, keyed by document uuid, with the decision's date.
	 *
	 * `decisionDocument.document` is a uri; its last path segment is the
	 * informatieobject's uuid.
	 *
	 * @param PortalCaseRead $read The case being read.
	 *
	 * @return array<string, string>
	 */
	private function decisionDocuments(PortalCaseRead $read): array {
		$decisionSchema = $this->settingsService->getConfigValue('decision_schema');
		$linkSchema = $this->settingsService->getConfigValue('decision_document_schema');
		if ($decisionSchema === '' || $linkSchema === '') {
			return [];
		}

		$dates = [];
		foreach ($this->decisionsOnCase(read: $read, decisionSchema: $decisionSchema) as $decision) {
			foreach ($this->linkedDocuments(read: $read, decisionId: $decision['id']) as $uuid) {
				$dates[$uuid] = $decision['date'];
			}
		}

		return $dates;
	}//end decisionDocuments()

	/**
	 * The decisions on this case, in read order, each with its id and date.
	 *
	 * A decision of another case is never trusted.
	 *
	 * @param PortalCaseRead $read           The case being read.
	 * @param string         $decisionSchema The decision schema.
	 *
	 * @return array<int, array{id: string, date: string}>
	 */
	private function decisionsOnCase(PortalCaseRead $read, string $decisionSchema): array {
		$decisions = $this->searchObjectsAsArraysUnscoped(
			objectService: $read->objectService,
			register: $read->register,
			schema: $decisionSchema,
			filters: ['case' => $read->caseId, '_limit' => 100]
		);

		$found = [];
		foreach ($decisions as $decision) {
			$decisionId = (string)($decision['id'] ?? ($decision['@self']['id'] ?? ''));
			if ($decisionId !== '' && ($decision['case'] ?? '') === $read->caseId) {
				$found[] = ['id' => $decisionId, 'date' => (string)($decision['decisionDate'] ?? '')];
			}
		}

		return $found;
	}//end decisionsOnCase()

	/**
	 * The uuids of the documents one decision links.
	 *
	 * `decisionDocument.document` is a uri; its last path segment is the
	 * informatieobject's uuid. A link of another decision is never trusted.
	 *
	 * @param PortalCaseRead $read       The case being read.
	 * @param string         $decisionId The decision.
	 *
	 * @return array<int, string>
	 */
	private function linkedDocuments(PortalCaseRead $read, string $decisionId): array {
		$links = $this->searchObjectsAsArraysUnscoped(
			objectService: $read->objectService,
			register: $read->register,
			schema: $this->settingsService->getConfigValue('decision_document_schema'),
			filters: ['decision' => $decisionId, '_limit' => 100]
		);

		$uuids = [];
		foreach ($links as $link) {
			$uuid = basename(rtrim((string)($link['document'] ?? ''), '/'));
			if ($uuid !== '' && ($link['decision'] ?? '') === $decisionId) {
				$uuids[] = $uuid;
			}
		}

		return $uuids;
	}//end linkedDocuments()
}//end class
