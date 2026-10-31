<?php

/**
 * Dossiq document access exception: who may read a document that names its readers
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
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-readable-only-by-its-listed-people-the-cases-handler-and-administrators
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\IGroupManager;
use OCP\IUser;
use Throwable;

/**
 * The exception rule: a document with an exception is read by its listed users and groups, and by whoever may
 * change a case it is on (the case's handler and administrators). A document without one is not narrowed here.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-readable-only-by-its-listed-people-the-cases-handler-and-administrators
 */
class DocumentAccessExceptionRule {

	/**
	 * The property on the informatieobject.
	 */
	public const PROPERTY = AccessExceptionValue::PROPERTY;

	/**
	 * The property holding every set, change and removal.
	 */
	public const HISTORY = AccessExceptionValue::HISTORY;

	/**
	 * Reads the exception off a record.
	 *
	 * @var AccessExceptionValue
	 */
	private readonly AccessExceptionValue $value;

	/**
	 * Verdicts already given in this request, keyed by "uid:documentId".
	 *
	 * @var array<string, bool>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @param DocumentCaseIds     $caseIds      The cases a document is on, read regardless of the caller.
	 * @param CaseAccessGuard     $cases        Who may change a case: its handler and administrators.
	 * @param IGroupManager       $groupManager The caller's groups.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentCaseIds $caseIds,
		private readonly CaseAccessGuard $cases,
		private readonly IGroupManager $groupManager,
	) {
		$this->value = new AccessExceptionValue();
	}//end __construct()

	/**
	 * The exception a document carries, or null when it carries none.
	 *
	 * @param array<string, mixed> $record The informatieobject.
	 *
	 * @return array{users: list<string>, groups: list<string>, reason: string, setBy: string, setAt: string}|null The exception.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function exceptionOf(array $record): ?array {
		return $this->value->exceptionOf(record: $record);
	}//end exceptionOf()

	/**
	 * Whether a user may read a document as far as its exception goes.
	 *
	 * @param IUser                $user   The caller.
	 * @param array<string, mixed> $record The informatieobject, with its `id`.
	 *
	 * @return bool True when the document has no exception, or the user is listed, in a listed group, or may change a
	 *              case the document is on.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-readable-only-by-its-listed-people-the-cases-handler-and-administrators
	 */
	public function admits(IUser $user, array $record): bool {
		$exception = $this->exceptionOf(record: $record);
		if ($exception === null) {
			return true;
		}

		$uid = $user->getUID();
		if ($uid === '') {
			return false;
		}

		if (in_array($uid, $exception['users'], true) === true) {
			return true;
		}

		try {
			$groups = $this->groupManager->getUserGroupIds($user);
		} catch (Throwable $e) {
			$groups = [];
		}

		if (array_intersect(array_map('strval', $groups), $exception['groups']) !== []) {
			return true;
		}

		$documentId = $this->idOf(record: $record);
		if ($documentId === '') {
			return false;
		}

		return $this->handlesACaseOf(user: $user, documentId: $documentId);
	}//end admits()

	/**
	 * Whether a user may change one of the cases a document is on.
	 *
	 * The cases are read regardless of the caller (only their ids leave the store), so a handler is recognised even
	 * when the document itself is hidden from the read that asks.
	 *
	 * @param IUser  $user       The caller.
	 * @param string $documentId The informatieobject id.
	 *
	 * @return bool True for the handler of one of its cases and for administrators.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-readable-only-by-its-listed-people-the-cases-handler-and-administrators
	 */
	public function handlesACaseOf(IUser $user, string $documentId): bool {
		$key = $user->getUID() . ':' . $documentId;
		if (array_key_exists($key, $this->memo) === true) {
			return $this->memo[$key];
		}

		$verdict = false;
		try {
			foreach ($this->caseIds->caseIdsOf(recordId: $documentId) as $caseId) {
				if ($this->cases->hasCaseMutationAccess(caseId: $caseId, user: $user) === true) {
					$verdict = true;
					break;
				}
			}
		} catch (Throwable $e) {
			$verdict = false;
		}

		$this->memo[$key] = $verdict;

		return $verdict;
	}//end handlesACaseOf()

	/**
	 * A list of principal ids, trimmed, without empties and duplicates.
	 *
	 * @param array<mixed> $values The ids.
	 *
	 * @return list<string> The clean list.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function cleanList(array $values): array {
		return $this->value->cleanList(values: $values);
	}//end cleanList()

	/**
	 * The record's id.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string The id, '' when it has none.
	 */
	private function idOf(array $record): string {
		$id = ($record['id'] ?? $record['@self']['id'] ?? $record['uuid'] ?? '');

		if (is_scalar($id) === false) {
			return '';
		}

		return (string)$id;
	}//end idOf()
}//end class
