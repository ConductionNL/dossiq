<?php

/**
 * Dossiq Woo gather add
 *
 * Adds what a handler picked in Gather documents to a Woo case
 * (woo-requests-gather-documents-from-sources, design D-3 and D-4).
 *
 * - A Nextcloud file is read AS THE CALLER, through their own user folder, so
 *   a file they cannot read cannot be added. It is then stored in the case
 *   folder, where the document projection makes it a document on the case,
 *   and so an outstanding item for assessment by the rules that already hold.
 * - An integriq hit is fetched through integriq and stored the same way.
 * - A document of another case is linked, not copied: a second
 *   `zaakinformatieobject` join, offered only when the caller may read a case
 *   the document is on.
 *
 * Every added document records where it was found (`provenance`): the source,
 * its location, the search terms, when and by whom. It is written once, here.
 * A copied file carries it on its own record; a linked document carries it on
 * this case's join, because the record also belongs to the other case.
 *
 * Each pick answers on its own: one refusal does not undo the others.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns picks into documents on a case, one answer per pick.
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 */
class WooGatherAdd {

	/**
	 * Constructor.
	 *
	 * @param IRootFolder               $rootFolder  The caller's own files.
	 * @param DocumentRecordStore       $store       Records and joins in OpenRegister.
	 * @param DocumentProjectionService $projection  The case folder, and a record for a file in it.
	 * @param WooSources                $sources     Fetches an integriq hit.
	 * @param CaseAccessGuard           $accessGuard Whether the caller may read the case a linked document is on.
	 * @param IL10N                     $l10n        The refusal sentences.
	 * @param LoggerInterface           $logger      Logs a pick that failed for a reason the caller cannot fix.
	 * @param WooCollection             $collection  Records a duplicate or unreadable pick as an exclusion.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly DocumentRecordStore $store,
		private readonly DocumentProjectionService $projection,
		private readonly WooSources $sources,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly WooCollection $collection,
	) {
	}//end __construct()

	/**
	 * Add every pick to the case, each on its own.
	 *
	 * @param string                           $caseId The Woo case uuid.
	 * @param array<int, array<string, mixed>> $picks  Each `{source, key, location?}`: `key` is the file id,
	 *                                                 the other case's document uuid, or integriq's handle.
	 * @param string                           $terms  The search terms the picks were found with.
	 * @param IUser                            $user   The caller.
	 * @param array<int, string>               $custodians The recorded plan's custodians; every pick names one.
	 *
	 * @return array<int, array<string, mixed>> One `{key, source, status, documentId, reason, message}` per pick;
	 *         `status` is added, excluded (recorded as an exclusion, such as a duplicate) or refused.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-every-gathered-document-records-where-it-was-found-req-woo-014
	 */
	public function addPicks(string $caseId, array $picks, string $terms, IUser $user, array $custodians): array {
		$results = [];
		foreach ($picks as $pick) {
			if (is_array($pick) === false) {
				continue;
			}

			$results[] = $this->addOne(caseId: $caseId, pick: $pick, terms: $terms, user: $user, custodians: $custodians);
		}

		return $results;
	}//end addPicks()

	/**
	 * Add one pick.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $pick   The pick.
	 * @param string               $terms  The search terms.
	 * @param IUser                $user   The caller.
	 * @param array<int, string>   $custodians The plan's custodians.
	 *
	 * @return array<string, mixed> The answer for this pick.
	 */
	private function addOne(string $caseId, array $pick, string $terms, IUser $user, array $custodians): array {
		$source = (string)($pick['source'] ?? '');
		$key = trim((string)($pick['key'] ?? ''));
		$custodian = trim((string)($pick['custodian'] ?? ''));
		if (in_array($custodian, $custodians, true) === false) {
			return $this->answer(key: $key, source: $source, documentId: '', reason: 'custodian-required');
		}

		$provenance = [
			'source' => $source,
			'sourceSystem' => $source,
			'custodian' => $custodian,
			'location' => (string)($pick['location'] ?? ''),
			'terms' => $terms,
			'searchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'searchedBy' => $user->getUID(),
		];

		try {
			$documentId = match ($source) {
				WooSources::SOURCE_FILES => $this->addFile(caseId: $caseId, fileId: (int)$key, provenance: $provenance, user: $user),
				WooSources::SOURCE_CASES => $this->linkDocument(caseId: $caseId, documentId: $key, provenance: $provenance, user: $user),
				WooSources::SOURCE_MICROSOFT365 => $this->addFetched(caseId: $caseId, handle: $key, provenance: $provenance, user: $user),
				default => throw new WooPickRefused('unknown-source'),
			};
		} catch (WooPickRefused $refused) {
			$this->recordUnreadable(caseId: $caseId, reason: $refused->getMessage(), provenance: $provenance, user: $user);
			return $this->answer(key: $key, source: $source, documentId: '', reason: $refused->getMessage());
		} catch (Throwable $e) {
			$this->logger->warning('WooGatherAdd: a pick could not be added', ['case' => $caseId, 'source' => $source, 'error' => $e->getMessage()]);
			return $this->answer(key: $key, source: $source, documentId: '', reason: 'write-failed');
		}

		return $this->answer(key: $key, source: $source, documentId: $documentId, reason: '');
	}//end addOne()

	/**
	 * Copy a Nextcloud file the caller can read into the case folder.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param int                  $fileId     The file id, as the unified search answered it.
	 * @param array<string, mixed> $provenance Where it was found.
	 * @param IUser                $user       The caller.
	 *
	 * @return string The document record uuid.
	 *
	 * @throws WooPickRefused When the caller cannot read the file.
	 */
	private function addFile(string $caseId, int $fileId, array $provenance, IUser $user): string {
		if ($fileId <= 0) {
			throw new WooPickRefused('not-readable');
		}

		$file = null;
		foreach ($this->rootFolder->getUserFolder($user->getUID())->getById($fileId) as $node) {
			if ($node instanceof File) {
				$file = $node;
				break;
			}
		}

		if ($file === null || $file->isReadable() === false) {
			throw new WooPickRefused('not-readable');
		}

		return $this->store(caseId: $caseId, fileName: $file->getName(), content: $file->getContent(), provenance: $provenance);
	}//end addFile()

	/**
	 * Fetch an integriq hit and store it in the case folder.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param string               $handle     integriq's fetch handle.
	 * @param array<string, mixed> $provenance Where it was found.
	 * @param IUser                $user       The caller.
	 *
	 * @return string The document record uuid.
	 *
	 * @throws WooPickRefused When integriq cannot hand it over.
	 */
	private function addFetched(string $caseId, string $handle, array $provenance, IUser $user): string {
		$fetched = $this->sources->fetchMicrosoft365(handle: $handle, userId: $user->getUID());
		if ($fetched === null) {
			throw new WooPickRefused('not-fetched');
		}

		return $this->store(caseId: $caseId, fileName: $fetched['fileName'], content: $fetched['content'], provenance: $provenance);
	}//end addFetched()

	/**
	 * Link a document of another case the caller may read.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param string               $documentId The document record uuid.
	 * @param array<string, mixed> $provenance Where it was found, kept on this case's join.
	 * @param IUser                $user       The caller.
	 *
	 * @return string The document record uuid.
	 *
	 * @throws WooPickRefused When the document is unknown, unreadable or already on the case.
	 */
	private function linkDocument(string $caseId, string $documentId, array $provenance, IUser $user): string {
		if ($documentId === '' || $this->store->findRecord(recordId: $documentId) === null) {
			throw new WooPickRefused('not-found');
		}

		$readable = false;
		foreach ($this->store->joinsFor(recordId: $documentId) as $join) {
			$otherCase = (string)($join['case'] ?? '');
			if ($otherCase === $caseId) {
				throw new WooPickRefused('already-on-case');
			}

			if ($otherCase !== '' && $this->accessGuard->hasCaseReadAccess(caseId: $otherCase, user: $user) === true) {
				$readable = true;
			}
		}

		if ($readable === false) {
			throw new WooPickRefused('not-readable');
		}

		$this->store->ensureJoin(caseId: $caseId, recordId: $documentId, extra: ['provenance' => $provenance]);

		return $documentId;
	}//end linkDocument()

	/**
	 * Store bytes in the case folder and write the provenance on the record the file gets.
	 *
	 * The node listener normally projects the record while the file is written;
	 * when it has not (the write ran as another user, or no listener ran), the
	 * file in the case folder is projected here, through the same service.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param string               $fileName   The file name.
	 * @param string               $content    The bytes.
	 * @param array<string, mixed> $provenance Where it was found.
	 *
	 * @return string The document record uuid.
	 *
	 * @throws WooPickRefused   `write-failed` when the file cannot be read back or no record results.
	 * @throws WooPickRefused   `duplicate` when the case holds the same bytes; recorded as an exclusion.
	 */
	private function store(string $caseId, string $fileName, string $content, array $provenance): string {
		$sha256 = hash('sha256', $content);
		$duplicateOf = $this->collection->duplicateOf(caseId: $caseId, sha256: $sha256);
		if ($duplicateOf !== '') {
			$this->collection->record(
				caseId: $caseId,
				fields: [
					'source' => $provenance['source'],
					'location' => $provenance['location'],
					'fileName' => $fileName,
					'sha256' => $sha256,
					'duplicateOf' => $duplicateOf,
					'custodian' => $provenance['custodian'],
					'reason' => 'duplicate',
					'excludedBy' => $provenance['searchedBy'],
				]
			);
			throw new WooPickRefused('duplicate');
		}

		$fileId = $this->store->storeFileOnObject(objectId: $caseId, fileName: $fileName, content: $content);
		if ($fileId <= 0) {
			throw new WooPickRefused('write-failed');
		}

		$record = $this->store->findRecord(fileId: $fileId);
		if ($record === null) {
			$record = $this->projectStoredFile(caseId: $caseId, fileId: $fileId);
		}

		if ($record === null) {
			throw new WooPickRefused('write-failed');
		}

		$record['provenance'] = $provenance;
		$recordId = $this->store->saveRecord(record: $record);
		$this->store->ensureJoin(caseId: $caseId, recordId: $recordId);

		return $recordId;
	}//end store()

	/**
	 * Project the file just stored in the case folder.
	 *
	 * @param string $caseId The case uuid.
	 * @param int    $fileId The file id.
	 *
	 * @return array<string, mixed>|null The record, null when the file is not found there.
	 */
	private function projectStoredFile(string $caseId, int $fileId): ?array {
		$folder = $this->projection->folderOf(objectId: $caseId);
		if ($folder === null) {
			return null;
		}

		foreach ($folder->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $this->projection->projectNode(node: $node);
			}
		}

		return null;
	}//end projectStoredFile()

	/**
	 * Record a pick that could not be read as an exclusion, so what arrived reconciles.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param string               $reason     The pick's refusal.
	 * @param array<string, mixed> $provenance Where it was found.
	 * @param IUser                $user       The caller.
	 *
	 * @return void
	 */
	private function recordUnreadable(string $caseId, string $reason, array $provenance, IUser $user): void {
		if (in_array($reason, ['not-readable', 'not-fetched'], true) === false) {
			return;
		}

		$this->collection->record(
			caseId: $caseId,
			fields: [
				'source' => $provenance['source'],
				'location' => $provenance['location'],
				'custodian' => $provenance['custodian'],
				'reason' => 'unreadable',
				'excludedBy' => $user->getUID(),
			]
		);
	}//end recordUnreadable()

	/**
	 * One pick's answer.
	 *
	 * @param string $key        The pick's key.
	 * @param string $source     The pick's source.
	 * @param string $documentId The document uuid, '' when refused.
	 * @param string $reason     The refusal code, '' when added.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function answer(string $key, string $source, string $documentId, string $reason): array {
		$status = 'refused';
		if ($reason === '') {
			$status = 'added';
		}

		if ($reason === 'duplicate') {
			$status = 'excluded';
		}

		return [
			'key' => $key,
			'source' => $source,
			'status' => $status,
			'documentId' => $documentId,
			'reason' => $reason,
			'message' => $this->sentence(reason: $reason),
		];
	}//end answer()

	/**
	 * The sentence a refusal shows.
	 *
	 * @param string $reason The refusal code.
	 *
	 * @return string The sentence, '' when there is no refusal.
	 */
	private function sentence(string $reason): string {
		return match ($reason) {
			'' => '',
			'not-readable' => $this->l10n->t('You cannot read this document, so it cannot be added.'),
			'not-found' => $this->l10n->t('This document no longer exists.'),
			'already-on-case' => $this->l10n->t('This document is already on the case.'),
			'not-fetched' => $this->l10n->t('The source did not hand over this document. Try again later.'),
			'unknown-source' => $this->l10n->t('This source is not known.'),
			'custodian-required' => $this->l10n->t('Pick whose files this document comes from, as the search plan names them.'),
			'duplicate' => $this->l10n->t('The case already holds this document. It is listed as a duplicate.'),
			default => $this->l10n->t('This document could not be added to the case.'),
		};
	}//end sentence()
}//end class
