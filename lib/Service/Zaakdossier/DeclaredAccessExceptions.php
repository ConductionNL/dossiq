<?php

/**
 * Dossiq declared access exceptions: an exception written straight onto a document, applied as the writer applies it
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
 * @spec openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use DateTimeImmutable;
use RuntimeException;

/**
 * Brings a document that carries an exception in step with it: a history entry, the derived readers and the file.
 *
 * Only the background service account and system writes (an example set, an import) can store `accessException`
 * (property authorization), so an exception found on a record was written by one of them. Applying it only ever
 * narrows who reads the document and its file; an exception is never lifted here.
 *
 * @spec openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
 */
class DeclaredAccessExceptions {

	/**
	 * Constructor.
	 *
	 * @param RestrictedDocumentReaders $readers Derives the readers and saves the record as the background service account.
	 * @param FileRestrictionMirror     $files   Restricts the file in OpenRegister.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RestrictedDocumentReaders $readers,
		private readonly FileRestrictionMirror $files,
	) {
	}//end __construct()

	/**
	 * Apply the exception a stored document carries, when its history, readers or file are not in step with it.
	 *
	 * @param array<string, mixed>      $record The document as stored, with `id`.
	 * @param array<string, mixed>|null $before The document before this write, null on a create.
	 *
	 * @return bool True when something was applied and the record saved.
	 *
	 * @throws RuntimeException When the file cannot be restricted; nothing is saved then, so the next write tries again.
	 *
	 * @spec openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
	 */
	public function apply(array $record, ?array $before): bool {
		$value = new AccessExceptionValue();
		$exception = $value->exceptionOf(record: $record);
		if ($exception === null || (string)($record['id'] ?? '') === '') {
			return false;
		}

		$history = $this->historyOf(record: $record);
		$last = end($history);
		$recorded = (is_array($last) === true && $this->sameException(left: ($last['new'] ?? null), right: $exception) === true);

		$fileId = (int)($record['fileId'] ?? 0);
		$fileNamed = ($fileId > 0 && ($recorded === false || $fileId !== (int)($before['fileId'] ?? 0)));

		$withReaders = $this->readers->withReaders(record: $record);
		$readersInStep = true;
		foreach (['restricted', 'accessUsers', 'accessGroups', 'accessHandlers'] as $field) {
			if (($withReaders[$field] ?? null) !== ($record[$field] ?? null)) {
				$readersInStep = false;
			}
		}

		if ($recorded === true && $fileNamed === false && $readersInStep === true) {
			return false;
		}

		if ($fileNamed === true) {
			$this->files->restrict(fileId: $fileId, users: $exception['users'], groups: $exception['groups'], reason: $exception['reason']);
		}

		if ($recorded === false) {
			$history[] = $this->entry(exception: $exception, last: $last);
			$record[AccessExceptionValue::HISTORY] = $history;
		}

		$this->readers->save(record: $record);

		return true;
	}//end apply()

	/**
	 * The history entry for an exception the history does not hold yet.
	 *
	 * @param array{users: list<string>, groups: list<string>, reason: string, setBy: string, setAt: string} $exception The exception.
	 * @param mixed                                                                                         $last      The newest entry so far, false when none.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(array $exception, mixed $last): array {
		$action = 'set';
		$old = null;
		if (is_array($last) === true && is_array($last['new'] ?? null) === true) {
			$action = 'change';
			$old = $last['new'];
		}

		$at = $exception['setAt'];
		if ($at === '') {
			$at = (new DateTimeImmutable())->format(DATE_ATOM);
		}

		return [
			'action' => $action,
			'by' => $exception['setBy'],
			'at' => $at,
			'old' => $old,
			'new' => $exception,
			'reason' => $exception['reason'],
		];
	}//end entry()

	/**
	 * The record's history as a list of entries.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return list<array<string, mixed>> The history.
	 */
	private function historyOf(array $record): array {
		$history = ($record[AccessExceptionValue::HISTORY] ?? []);
		if (is_array($history) === false) {
			return [];
		}

		return array_values(array_filter($history, 'is_array'));
	}//end historyOf()

	/**
	 * Whether a history entry's exception names the same people, groups and reason.
	 *
	 * @param mixed                $left  The entry's `new`.
	 * @param array<string, mixed> $right The exception.
	 *
	 * @return bool
	 */
	private function sameException(mixed $left, array $right): bool {
		if (is_array($left) === false) {
			return false;
		}

		$value = new AccessExceptionValue();

		return $value->cleanList(values: (array)($left['users'] ?? [])) === $right['users']
			&& $value->cleanList(values: (array)($left['groups'] ?? [])) === $right['groups']
			&& (string)($left['reason'] ?? '') === $right['reason'];
	}//end sameException()
}//end class
