<?php

/**
 * Dossiq document access exception writer: sets, changes and removes a document's exception
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
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Writes the exception on the record, restricts or frees the file in OpenRegister first, and appends the history.
 *
 * Who may call this is the controller's question (DocumentCaseWriteGuard, asked before anything is read).
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
 */
class DocumentAccessExceptionWriter {

	/**
	 * Constructor.
	 *
	 * @param DocumentAccessExceptionRule $rule         Reads the exception a record carries.
	 * @param FileRestrictionMirror   $files        Restricts the file in OpenRegister.
	 * @param RestrictedDocumentReaders $readers    Derives the readers OpenRegister tests and saves the record as the background service account.
	 * @param IUserManager            $userManager  Checks a named person exists.
	 * @param IGroupManager           $groupManager Checks a named group exists.
	 * @param LoggerInterface         $logger       Where each change is noted.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentAccessExceptionRule $rule,
		private readonly FileRestrictionMirror $files,
		private readonly RestrictedDocumentReaders $readers,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The exception asked for, checked: at least one existing person or group, and a reason.
	 *
	 * @param array<string, mixed> $input `users`, `groups`, `reason`.
	 *
	 * @return array{users: list<string>, groups: list<string>, reason: string} The exception.
	 *
	 * @throws InvalidArgumentException When it names nobody, names an unknown person or group, or has no reason.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function validated(array $input): array {
		$users = $this->rule->cleanList(values: (array)($input['users'] ?? []));
		$groups = $this->rule->cleanList(values: (array)($input['groups'] ?? []));
		$reason = trim((string)($input['reason'] ?? ''));

		if ($users === [] && $groups === []) {
			throw new InvalidArgumentException('Name at least one person or group');
		}

		if ($reason === '') {
			throw new InvalidArgumentException('Give a reason for the exception');
		}

		foreach ($users as $uid) {
			if ($this->userManager->userExists($uid) === false) {
				throw new InvalidArgumentException('Unknown person: ' . $uid);
			}
		}

		foreach ($groups as $gid) {
			if ($this->groupManager->groupExists($gid) === false) {
				throw new InvalidArgumentException('Unknown group: ' . $gid);
			}
		}

		return ['users' => $users, 'groups' => $groups, 'reason' => $reason];
	}//end validated()

	/**
	 * Set or change the exception of a record.
	 *
	 * @param IUser                                                           $user      Who sets it.
	 * @param array<string, mixed>                                            $record    The informatieobject, with `id`.
	 * @param array{users: list<string>, groups: list<string>, reason: string} $exception The validated exception.
	 *
	 * @return array<string, mixed> The exception as stored.
	 *
	 * @throws RuntimeException When the file cannot be restricted, or the record cannot be saved.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function set(IUser $user, array $record, array $exception): array {
		$old = $this->rule->exceptionOf(record: $record);
		$now = (new DateTimeImmutable())->format(DATE_ATOM);
		$new = [
			'users' => $exception['users'],
			'groups' => $exception['groups'],
			'reason' => $exception['reason'],
			'setBy' => $user->getUID(),
			'setAt' => $now,
		];

		$fileId = (int)($record['fileId'] ?? 0);
		if ($fileId > 0) {
			$this->files->restrict(fileId: $fileId, users: $new['users'], groups: $new['groups'], reason: $new['reason']);
		}

		$action = 'change';
		if ($old === null) {
			$action = 'set';
		}

		$record[DocumentAccessExceptionRule::PROPERTY] = $new;
		$record[DocumentAccessExceptionRule::HISTORY] = $this->withEntry(
			record: $record,
			entry: [
				'action' => $action,
				'by' => $user->getUID(),
				'at' => $now,
				'old' => $old,
				'new' => $new,
				'reason' => $new['reason'],
			]
		);

		$this->saveOrRestore(record: $record, fileId: $fileId, old: $old);

		return $new;
	}//end set()

	/**
	 * Remove the exception of a record.
	 *
	 * @param IUser                $user   Who removes it.
	 * @param array<string, mixed> $record The informatieobject, with `id`.
	 * @param string               $reason Why, may be empty.
	 *
	 * @return bool True when there was an exception to remove.
	 *
	 * @throws RuntimeException When the file cannot be freed, or the record cannot be saved.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
	 */
	public function remove(IUser $user, array $record, string $reason = ''): bool {
		$old = $this->rule->exceptionOf(record: $record);
		if ($old === null) {
			return false;
		}

		$fileId = (int)($record['fileId'] ?? 0);
		if ($fileId > 0) {
			$this->files->lift(fileId: $fileId);
		}

		unset($record[DocumentAccessExceptionRule::PROPERTY]);
		$record[DocumentAccessExceptionRule::HISTORY] = $this->withEntry(
			record: $record,
			entry: [
				'action' => 'remove',
				'by' => $user->getUID(),
				'at' => (new DateTimeImmutable())->format(DATE_ATOM),
				'old' => $old,
				'new' => null,
				'reason' => trim($reason),
			]
		);

		$this->saveOrRestore(record: $record, fileId: $fileId, old: $old);

		return true;
	}//end remove()

	/**
	 * Save the record; when that fails, put the file back the way the old exception had it.
	 *
	 * @param array<string, mixed>      $record The record to save.
	 * @param int                       $fileId The file, 0 when none.
	 * @param array<string, mixed>|null $old    The exception before.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the save fails.
	 */
	private function saveOrRestore(array $record, int $fileId, ?array $old): void {
		try {
			$this->readers->save(record: $record);
		} catch (Throwable $e) {
			$this->restoreFile(fileId: $fileId, old: $old);
			throw new RuntimeException('The access exception could not be saved', 0, $e);
		}

		$this->logger->info(
			'Dossiq: access exception of a document changed',
			['app' => Application::APP_ID, 'documentId' => (string)($record['id'] ?? ''), 'fileId' => $fileId]
		);
	}//end saveOrRestore()

	/**
	 * Put the file's restriction back to the old exception.
	 *
	 * @param int                       $fileId The file, 0 when none.
	 * @param array<string, mixed>|null $old    The exception before.
	 *
	 * @return void
	 */
	private function restoreFile(int $fileId, ?array $old): void {
		if ($fileId <= 0) {
			return;
		}

		try {
			if ($old === null) {
				$this->files->lift(fileId: $fileId);
				return;
			}

			$this->files->restrict(
				fileId: $fileId,
				users: (array)$old['users'],
				groups: (array)$old['groups'],
				reason: (string)$old['reason']
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the file restriction could not be put back after a failed save',
				['app' => Application::APP_ID, 'fileId' => $fileId, 'exception' => $e->getMessage()]
			);
		}
	}//end restoreFile()

	/**
	 * The record's history with one more entry.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $entry  The entry.
	 *
	 * @return list<array<string, mixed>> The history.
	 */
	private function withEntry(array $record, array $entry): array {
		$history = ($record[DocumentAccessExceptionRule::HISTORY] ?? []);
		if (is_array($history) === false) {
			$history = [];
		}

		$history = array_values(array_filter($history, 'is_array'));
		$history[] = $entry;

		return $history;
	}//end withEntry()
}//end class
