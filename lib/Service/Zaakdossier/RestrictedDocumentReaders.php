<?php

/**
 * Dossiq restricted document readers: the derived fields OpenRegister's own read rule tests
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
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use Throwable;

/**
 * Keeps `restricted`, `accessUsers`, `accessGroups` and `accessHandlers` in step with a document's exception, and writes them, with
 * the exception itself, as the background service account: no staff group may write those fields.
 *
 * `accessUsers` holds the exception's people plus the handler (`assignee`) of every case the document is on, so the
 * handler OpenRegister admits is the same one dossiq's own rule admits; a handler change refreshes it.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
 */
class RestrictedDocumentReaders {

	/**
	 * Constructor.
	 *
	 * @param DocumentCaseIds          $caseIds        The cases of a document and the documents of a case.
	 * @param DocumentRecordStore      $store          Reads cases and records, saves records.
	 * @param BackgroundServiceAccount $serviceAccount The only account that writes the protected fields.
	 * @param FileRestrictionMirror    $files          Restricts a copy's file in OpenRegister.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentCaseIds $caseIds,
		private readonly DocumentRecordStore $store,
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly FileRestrictionMirror $files,
	) {
	}//end __construct()

	/**
	 * The record with its derived reader fields for the exception it carries.
	 *
	 * @param array<string, mixed> $record The record, with `id` and maybe `accessException`.
	 *
	 * @return array<string, mixed> The record with `restricted`, `accessUsers`, `accessGroups` and `accessHandlers`.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
	 */
	public function withReaders(array $record): array {
		$exception = (new AccessExceptionValue())->exceptionOf(record: $record);
		if ($exception === null) {
			return [...$record, 'restricted' => false, 'accessUsers' => [], 'accessGroups' => [], 'accessHandlers' => []];
		}

		$handlers = $this->handlersOf(documentId: (string)($record['id'] ?? ''));
		$users = $exception['users'];
		foreach ($handlers as $handler) {
			if (in_array($handler, $users, true) === false) {
				$users[] = $handler;
			}
		}

		return [
			...$record,
			'restricted' => true,
			'accessUsers' => $users,
			'accessGroups' => $exception['groups'],
			'accessHandlers' => $handlers,
		];
	}//end withReaders()

	/**
	 * Save a record as the background service account, with its reader fields derived.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string The record id.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
	 */
	public function save(array $record): string {
		$record = $this->withReaders(record: $record);

		return (string)$this->serviceAccount->runAs(operation: fn (): string => $this->store->saveRecord(record: $record));
	}//end save()

	/**
	 * Refresh the readers of every restricted document of a case, after its handler changed.
	 *
	 * @param string $caseId The case.
	 *
	 * @return int How many documents changed.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
	 */
	public function refreshCase(string $caseId): int {
		return (int)$this->serviceAccount->runAs(
			operation: function () use ($caseId): int {
				$changed = 0;
				foreach ($this->caseIds->documentIdsOf(caseId: $caseId) as $documentId) {
					$record = $this->store->findRecord(recordId: $documentId);
					if ($record === null || ($record['restricted'] ?? false) !== true) {
						continue;
					}

					$refreshed = $this->withReaders(record: $record);
					if (($refreshed['accessUsers'] ?? []) === ($record['accessUsers'] ?? [])
						&& ($refreshed['accessHandlers'] ?? []) === ($record['accessHandlers'] ?? [])
					) {
						continue;
					}

					$this->store->saveRecord(record: $refreshed);
					$changed++;
				}

				return $changed;
			}
		);
	}//end refreshCase()

	/**
	 * Give a copy of a document (a redacted copy, a signed version) the exception of its original.
	 *
	 * The copy's file is restricted in OpenRegister to the same people and groups, and the copy's record, when it
	 * has one, carries the same exception with a history entry naming the original.
	 *
	 * @param array<string, mixed> $original The original's record.
	 * @param int                  $fileId   The copy's file.
	 * @param string               $madeBy   Who made the copy.
	 *
	 * @return bool True when the original carried an exception and the copy got it.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-copy-of-a-document-carries-its-exception
	 */
	public function inherit(array $original, int $fileId, string $madeBy = ''): bool {
		$exception = (new AccessExceptionValue())->exceptionOf(record: $original);
		if ($exception === null || $fileId <= 0) {
			return false;
		}

		$this->files->restrict(fileId: $fileId, users: $exception['users'], groups: $exception['groups'], reason: $exception['reason']);

		$copy = $this->serviceAccount->runAs(operation: fn (): ?array => $this->store->findRecord(fileId: $fileId));
		if (is_array($copy) === false || (string)($copy['id'] ?? '') === (string)($original['id'] ?? '')) {
			return true;
		}

		$before = (new AccessExceptionValue())->exceptionOf(record: $copy);
		$action = 'change';
		if ($before === null) {
			$action = 'set';
		}

		$history = (array)($copy[AccessExceptionValue::HISTORY] ?? []);
		$history[] = [
			'action' => $action,
			'by' => $madeBy,
			'at' => gmdate(DATE_ATOM),
			'old' => $before,
			'new' => $exception,
			'reason' => 'Copied from ' . (string)($original['id'] ?? '') . ': ' . $exception['reason'],
		];
		$copy[AccessExceptionValue::PROPERTY] = $exception;
		$copy[AccessExceptionValue::HISTORY] = array_values($history);
		$this->save(record: $copy);

		return true;
	}//end inherit()

	/**
	 * Give a copy the exception of its original, named by id.
	 *
	 * @param string $originalId The original's record id.
	 * @param int    $fileId     The copy's file.
	 * @param string $madeBy     Who made the copy.
	 *
	 * @return bool True when the original carried an exception and the copy got it.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-copy-of-a-document-carries-its-exception
	 */
	public function inheritFromId(string $originalId, int $fileId, string $madeBy = ''): bool {
		$original = $this->serviceAccount->runAs(operation: fn (): ?array => $this->store->findRecord(recordId: $originalId));
		if (is_array($original) === false) {
			return false;
		}

		return $this->inherit(original: $original, fileId: $fileId, madeBy: $madeBy);
	}//end inheritFromId()

	/**
	 * The handlers of the cases a document is on.
	 *
	 * @param string $documentId The document.
	 *
	 * @return list<string> Their uids.
	 */
	private function handlersOf(string $documentId): array {
		if ($documentId === '') {
			return [];
		}

		// A handler that cannot be read is left out: a reader too few is visible, a stale one is a leak.
		$handlers = [];
		try {
			foreach ($this->caseIds->caseIdsOf(recordId: $documentId) as $caseId) {
				$assignee = trim((string)($this->store->findCase(caseId: $caseId)['assignee'] ?? ''));
				if ($assignee !== '' && in_array($assignee, $handlers, true) === false) {
					$handlers[] = $assignee;
				}
			}
		} catch (Throwable $e) {
			$handlers = [];
		}

		return $handlers;
	}//end handlersOf()
}//end class
