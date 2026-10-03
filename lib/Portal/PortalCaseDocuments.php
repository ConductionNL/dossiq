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
 * @spec openspec/changes/portal-case-documents/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
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
 * THE READ RUNS AS THE SYSTEM, for this one case only. Portaliq calls the
 * method after it proved the case is the resident's, in a request with no
 * Nextcloud user, where OpenRegister's RBAC would answer nothing. The case id
 * is the only input and every read is filtered on it.
 *
 * The file reference names the CASE object, because the document's bytes live
 * in the case's folder (DocumentRecordStore::storeFileOnObject). Portaliq
 * never sends it to the browser; it streams only an entry this answered.
 *
 * @spec openspec/changes/portal-case-documents/tasks.md
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
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The entries a resident may see on one case, in portaliq's shape.
	 *
	 * @param string $caseId The case, already proven to be the resident's.
	 *
	 * @return array<int, array<string, mixed>> `{id, title, kind, date, file, mimeType?, size?}` per document.
	 *
	 * @spec openspec/changes/portal-case-documents/tasks.md
	 */
	public function forCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($caseId === '' || $objectService === null || $register === '') {
			return [];
		}

		try {
			return (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->entries(objectService: $objectService, register: $register, caseId: $caseId)
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
	 * @spec openspec/changes/portal-case-documents/tasks.md
	 */
	public function isPublished(array $document, bool $isDecision): bool {
		if (in_array(($document['status'] ?? ''), self::PUBLISHED_STATUSES, true) === false) {
			return false;
		}

		if (in_array(($document['vertrouwelijkheidaanduiding'] ?? ''), self::READABLE_CONFIDENTIALITY, true) === false) {
			return false;
		}

		return $isDecision === true || ($document['direction'] ?? '') === 'outgoing';
	}//end isPublished()

	/**
	 * The entries, read inside the system context.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register.
	 * @param string $caseId        The case.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function entries(object $objectService, string $register, string $caseId): array {
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		if ($caseSchema === '' || $infoSchema === '' || $joinSchema === '') {
			return [];
		}

		$decisionDates = $this->decisionDocuments(objectService: $objectService, register: $register, caseId: $caseId);
		$joins = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $joinSchema,
			filters: ['case' => $caseId, '_limit' => 500]
		);

		$entries = [];
		foreach ($joins as $join) {
			$infoId = (string)($join['informatieobject'] ?? '');
			if ($infoId === '' || ($join['case'] ?? '') !== $caseId) {
				continue;
			}

			$document = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $infoSchema, id: $infoId);
			$entry = $this->entry(
				document: (array)$document,
				infoId: $infoId,
				decisionDate: ($decisionDates[$infoId] ?? null),
				file: ['register' => $register, 'schema' => $caseSchema, 'id' => $caseId]
			);
			if ($entry !== null) {
				$entries[$infoId] = $entry;
			}
		}

		return array_values($entries);
	}//end entries()

	/**
	 * One entry, or null when the document may not reach the resident.
	 *
	 * @param array<string, mixed>  $document     The informatieobject.
	 * @param string                $infoId       Its uuid.
	 * @param string|null           $decisionDate The decision's date when a decision links it, else null.
	 * @param array<string, string> $file         The case object the file lives on.
	 *
	 * @return array<string, mixed>|null
	 */
	private function entry(array $document, string $infoId, ?string $decisionDate, array $file): ?array {
		$isDecision = ($decisionDate !== null);
		$fileId = (int)($document['fileId'] ?? 0);
		$title = trim((string)($document['title'] ?? ''));
		if ($fileId <= 0 || $title === '' || $this->isPublished(document: $document, isDecision: $isDecision) === false) {
			return null;
		}

		$kind = 'document';
		$date = (string)($document['creatiedatum'] ?? '');
		if ($isDecision === true) {
			$kind = 'decision';
			if ($decisionDate !== '') {
				$date = $decisionDate;
			}
		}

		$entry = [
			'id' => $infoId,
			'title' => $title,
			'kind' => $kind,
			'date' => $date,
			'file' => $file + ['fileId' => (string)$fileId],
		];
		if (is_string(($document['format'] ?? null)) === true && $document['format'] !== '') {
			$entry['mimeType'] = $document['format'];
		}

		if (is_int(($document['bestandsomvang'] ?? null)) === true) {
			$entry['size'] = $document['bestandsomvang'];
		}

		return $entry;
	}//end entry()

	/**
	 * The documents the decisions on this case link, keyed by document uuid, with the decision's date.
	 *
	 * `decisionDocument.document` is a uri; its last path segment is the
	 * informatieobject's uuid.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register.
	 * @param string $caseId        The case.
	 *
	 * @return array<string, string>
	 */
	private function decisionDocuments(object $objectService, string $register, string $caseId): array {
		$decisionSchema = $this->settingsService->getConfigValue('decision_schema');
		$linkSchema = $this->settingsService->getConfigValue('decision_document_schema');
		if ($decisionSchema === '' || $linkSchema === '') {
			return [];
		}

		$dates = [];
		$decisions = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $decisionSchema,
			filters: ['case' => $caseId, '_limit' => 100]
		);
		foreach ($decisions as $decision) {
			$decisionId = (string)($decision['id'] ?? ($decision['@self']['id'] ?? ''));
			if ($decisionId === '' || ($decision['case'] ?? '') !== $caseId) {
				continue;
			}

			$links = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $linkSchema,
				filters: ['decision' => $decisionId, '_limit' => 100]
			);
			foreach ($links as $link) {
				$uuid = basename(rtrim((string)($link['document'] ?? ''), '/'));
				if ($uuid !== '' && ($link['decision'] ?? '') === $decisionId) {
					$dates[$uuid] = (string)($decision['decisionDate'] ?? '');
				}
			}
		}

		return $dates;
	}//end decisionDocuments()
}//end class
