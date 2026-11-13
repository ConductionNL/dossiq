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
 * its location, the search terms, when and by whom. It is written once, here,
 * through the generic CaseFileCollector, which also keeps a collected e-mail's
 * header fields (`provenance.mail`).
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
use OCA\Dossiq\Service\Zaakdossier\CaseFileCollector;
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
	 * @param CaseFileCollector         $collector   Writes copied bytes as a document on the case, with provenance.
	 * @param WooSources                $sources     Fetches an integriq hit.
	 * @param CaseAccessGuard           $accessGuard Whether the caller may read the case a linked document is on.
	 * @param IL10N                     $l10n        The refusal sentences.
	 * @param LoggerInterface           $logger      Logs a pick that failed for a reason the caller cannot fix.
	 * @param WooCollection             $collection  Records a duplicate or unreadable pick as an exclusion.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly DocumentRecordStore $store,
		private readonly CaseFileCollector $collector,
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

			$run = new WooPickRun(caseId: $caseId, terms: $terms, user: $user, custodians: $custodians, pick: $pick);
			$results[] = $this->addOne(run: $run);
		}

		return $results;
	}//end addPicks()

	/**
	 * Add one pick, once its custodian is one the plan names.
	 *
	 * @param WooPickRun $run The pick.
	 *
	 * @return array<string, mixed> The answer for this pick.
	 */
	private function addOne(WooPickRun $run): array {
		if (in_array($run->custodian(), $run->custodians, true) === false) {
			return $this->answer(run: $run, reason: 'custodian-required');
		}

		$run->provenance = $this->provenance(run: $run);

		return $this->tryAdd(run: $run);
	}//end addOne()

	/**
	 * Where the pick was found, by whom and when.
	 *
	 * @param WooPickRun $run The pick.
	 *
	 * @return array<string, mixed> The provenance the document keeps.
	 */
	private function provenance(WooPickRun $run): array {
		return [
			'source' => $run->source(),
			'sourceSystem' => $run->source(),
			'custodian' => $run->custodian(),
			'location' => $run->location(),
			'terms' => $run->terms,
			'searchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'searchedBy' => $run->user->getUID(),
		];
	}//end provenance()

	/**
	 * Add the pick from its source, answering a refusal or a failure in its place.
	 *
	 * @param WooPickRun $run The pick, its provenance set.
	 *
	 * @return array<string, mixed> The answer for this pick.
	 */
	private function tryAdd(WooPickRun $run): array {
		try {
			$documentId = $this->addFromSource(run: $run);
		} catch (WooPickRefused $refused) {
			$this->recordUnreadable(run: $run, reason: $refused->getMessage());
			return $this->answer(run: $run, reason: $refused->getMessage());
		} catch (Throwable $e) {
			$this->logger->warning(
				'WooGatherAdd: a pick could not be added',
				['case' => $run->caseId, 'source' => $run->source(), 'error' => $e->getMessage()]
			);
			return $this->answer(run: $run, reason: 'write-failed');
		}

		return $this->added(run: $run, documentId: $documentId);
	}//end tryAdd()

	/**
	 * Add the pick the way its source asks.
	 *
	 * @param WooPickRun $run The pick, its provenance set.
	 *
	 * @return string The document record uuid.
	 *
	 * @throws WooPickRefused When the source is unknown or the pick cannot be added.
	 */
	private function addFromSource(WooPickRun $run): string {
		return match ($run->source()) {
			WooSources::SOURCE_FILES => $this->addFile(run: $run),
			WooSources::SOURCE_CASES => $this->linkDocument(run: $run),
			WooSources::SOURCE_MICROSOFT365 => $this->addFetched(run: $run),
			default => throw new WooPickRefused('unknown-source'),
		};
	}//end addFromSource()

	/**
	 * Copy a Nextcloud file the caller can read into the case folder.
	 *
	 * The key is the file id, as the unified search answered it.
	 *
	 * @param WooPickRun $run The pick, its provenance set.
	 *
	 * @return string The document record uuid.
	 *
	 * @throws WooPickRefused When the caller cannot read the file.
	 */
	private function addFile(WooPickRun $run): string {
		$fileId = (int)$run->key();
		if ($fileId <= 0) {
			throw new WooPickRefused('not-readable');
		}

		$file = $this->userFile(user: $run->user, fileId: $fileId);
		if ($file === null || $file->isReadable() === false) {
			throw new WooPickRefused('not-readable');
		}

		return $this->store(run: $run, file: new WooPickedFile(fileName: $file->getName(), content: $file->getContent()));
	}//end addFile()

	/**
	 * The first file under this id in the caller's own folder.
	 *
	 * @param IUser $user   The caller.
	 * @param int   $fileId The file id.
	 *
	 * @return File|null The file, or null when the caller's folder holds none.
	 */
	private function userFile(IUser $user, int $fileId): ?File {
		foreach ($this->rootFolder->getUserFolder($user->getUID())->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}//end userFile()

	/**
	 * Fetch an integriq hit and store it in the case folder.
	 *
	 * The key is integriq's fetch handle.
	 *
	 * @param WooPickRun $run The pick, its provenance set.
	 *
	 * @return string The document record uuid.
	 *
	 * @throws WooPickRefused When integriq cannot hand it over.
	 */
	private function addFetched(WooPickRun $run): string {
		$fetched = $this->sources->fetchMicrosoft365(handle: $run->key(), userId: $run->user->getUID());
		if ($fetched === null) {
			throw new WooPickRefused('not-fetched');
		}

		return $this->store(
			run: $run,
			file: new WooPickedFile(fileName: $fetched['fileName'], content: $fetched['content'], mimeType: $fetched['mimeType'])
		);
	}//end addFetched()

	/**
	 * Link a document of another case the caller may read.
	 *
	 * The key is the document record uuid; the provenance is kept on this
	 * case's join.
	 *
	 * @param WooPickRun $run The pick, its provenance set.
	 *
	 * @return string The document record uuid.
	 *
	 * @throws WooPickRefused When the document is unknown, unreadable or already on the case.
	 */
	private function linkDocument(WooPickRun $run): string {
		$documentId = $run->key();
		if ($documentId === '' || $this->store->findRecord(recordId: $documentId) === null) {
			throw new WooPickRefused('not-found');
		}

		$this->assertLinkable(run: $run, documentId: $documentId);
		$this->store->ensureJoin(caseId: $run->caseId, recordId: $documentId, extra: ['provenance' => $run->provenance]);

		return $documentId;
	}//end linkDocument()

	/**
	 * Refuse a document already on this case, or on no case the caller may read.
	 *
	 * Every join is read: a join to this case refuses the link even after a
	 * readable case was found.
	 *
	 * @param WooPickRun $run        The pick.
	 * @param string     $documentId The document record uuid.
	 *
	 * @return void
	 *
	 * @throws WooPickRefused `already-on-case` or `not-readable`.
	 */
	private function assertLinkable(WooPickRun $run, string $documentId): void {
		$readable = false;
		foreach ($this->store->joinsFor(recordId: $documentId) as $join) {
			$otherCase = (string)($join['case'] ?? '');
			if ($otherCase === $run->caseId) {
				throw new WooPickRefused('already-on-case');
			}

			if ($this->mayReadCase(user: $run->user, otherCase: $otherCase) === true) {
				$readable = true;
			}
		}

		if ($readable === false) {
			throw new WooPickRefused('not-readable');
		}
	}//end assertLinkable()

	/**
	 * Whether the caller may read the other case a document is on.
	 *
	 * @param IUser  $user      The caller.
	 * @param string $otherCase The other case uuid, '' when the join names none.
	 *
	 * @return bool
	 */
	private function mayReadCase(IUser $user, string $otherCase): bool {
		return $otherCase !== '' && $this->accessGuard->hasCaseReadAccess(caseId: $otherCase, user: $user) === true;
	}//end mayReadCase()

	/**
	 * Store bytes in the case folder as a document with its provenance, unless the case holds them already.
	 *
	 * @param WooPickRun    $run  The pick, its provenance set.
	 * @param WooPickedFile $file The bytes.
	 *
	 * @return string The document record uuid.
	 *
	 * @throws WooPickRefused   `write-failed` when the file cannot be read back or no record results.
	 * @throws WooPickRefused   `duplicate` when the case holds the same bytes; recorded as an exclusion.
	 */
	private function store(WooPickRun $run, WooPickedFile $file): string {
		$run->duplicateOf = $this->collection->duplicateOf(caseId: $run->caseId, sha256: $file->sha256());
		if ($run->duplicateOf !== '') {
			$this->recordDuplicate(run: $run, file: $file);
			throw new WooPickRefused('duplicate');
		}

		$recordId = $this->collect(run: $run, file: $file);
		if ($recordId === '') {
			throw new WooPickRefused('write-failed');
		}

		return $recordId;
	}//end store()

	/**
	 * Write the bytes as a document on the case, with the pick's provenance.
	 *
	 * @param WooPickRun    $run  The pick, its provenance set.
	 * @param WooPickedFile $file The bytes.
	 *
	 * @return string The document record uuid, '' when no record results.
	 */
	private function collect(WooPickRun $run, WooPickedFile $file): string {
		return $this->collector->collect(
			caseId: $run->caseId,
			fileName: $file->fileName,
			content: $file->content,
			provenance: $run->provenance,
			mimeType: $file->mimeType
		);
	}//end collect()

	/**
	 * Record bytes the case already holds as a duplicate exclusion.
	 *
	 * @param WooPickRun    $run  The pick, the duplicate it is of set.
	 * @param WooPickedFile $file The bytes.
	 *
	 * @return void
	 */
	private function recordDuplicate(WooPickRun $run, WooPickedFile $file): void {
		$this->collection->record(
			caseId: $run->caseId,
			fields: [
				'source' => $run->provenance['source'],
				'location' => $run->provenance['location'],
				'fileName' => $file->fileName,
				'sha256' => $file->sha256(),
				'duplicateOf' => $run->duplicateOf,
				'custodian' => $run->provenance['custodian'],
				'reason' => 'duplicate',
				'excludedBy' => $run->provenance['searchedBy'],
			]
		);
	}//end recordDuplicate()

	/**
	 * Record a pick that could not be read as an exclusion, so what arrived reconciles.
	 *
	 * @param WooPickRun $run    The pick, its provenance set.
	 * @param string     $reason The pick's refusal.
	 *
	 * @return void
	 */
	private function recordUnreadable(WooPickRun $run, string $reason): void {
		if (in_array($reason, ['not-readable', 'not-fetched'], true) === false) {
			return;
		}

		$this->collection->record(
			caseId: $run->caseId,
			fields: [
				'source' => $run->provenance['source'],
				'location' => $run->provenance['location'],
				'custodian' => $run->provenance['custodian'],
				'reason' => 'unreadable',
				'excludedBy' => $run->user->getUID(),
			]
		);
	}//end recordUnreadable()

	/**
	 * The answer of a pick that was added.
	 *
	 * @param WooPickRun $run        The pick.
	 * @param string     $documentId The document uuid.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function added(WooPickRun $run, string $documentId): array {
		return array_replace($this->answer(run: $run, reason: ''), ['documentId' => $documentId]);
	}//end added()

	/**
	 * One pick's answer, without a document unless added() sets it.
	 *
	 * @param WooPickRun $run    The pick.
	 * @param string     $reason The refusal code, '' when added.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function answer(WooPickRun $run, string $reason): array {
		return [
			'key' => $run->key(),
			'source' => $run->source(),
			'status' => $this->status(reason: $reason),
			'documentId' => '',
			'reason' => $reason,
			'message' => $this->sentence(reason: $reason),
		];
	}//end answer()

	/**
	 * The status a pick ends in: added, excluded as a duplicate, or refused.
	 *
	 * @param string $reason The refusal code, '' when added.
	 *
	 * @return string
	 */
	private function status(string $reason): string {
		return match ($reason) {
			'' => 'added',
			'duplicate' => 'excluded',
			default => 'refused',
		};
	}//end status()

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
