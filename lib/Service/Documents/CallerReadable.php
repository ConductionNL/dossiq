<?php

/**
 * Dossiq caller readable.
 *
 * What the caller may read, asked as the caller: a Nextcloud file by its id,
 * through their own user folder, so a file they cannot read is never handed
 * over; and whether they may read a given case. Split out of
 * {@see \OCA\Dossiq\Woo\WooGatherAdd}, which keeps what a pick becomes on the
 * case and the answer per pick.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Documents
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Documents;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;

/**
 * A file or a case, as far as the caller may read it.
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CallerReadable {

	/**
	 * Constructor.
	 *
	 * @param IRootFolder              $rootFolder  The caller's own files.
	 * @param CaseAccessGuardInterface $accessGuard Whether the caller may read a case.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly CaseAccessGuardInterface $accessGuard,
	) {
	}//end __construct()

	/**
	 * The caller's file with this id, or null when they have none they may read.
	 *
	 * @param IUser $user   The caller.
	 * @param int   $fileId The file id.
	 *
	 * @return File|null The readable file.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function file(IUser $user, int $fileId): ?File {
		$file = $this->userFile(user: $user, fileId: $fileId);
		if ($file === null || $file->isReadable() === false) {
			return null;
		}

		return $file;
	}//end file()

	/**
	 * Whether the caller may read this case; never for an empty id.
	 *
	 * @param IUser  $user   The caller.
	 * @param string $caseId The case.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function mayReadCase(IUser $user, string $caseId): bool {
		return $caseId !== '' && $this->accessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === true;
	}//end mayReadCase()

	/**
	 * The first file node with this id in the caller's own folder.
	 *
	 * @param IUser $user   The caller.
	 * @param int   $fileId The file id.
	 *
	 * @return File|null
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function userFile(IUser $user, int $fileId): ?File {
		foreach ($this->rootFolder->getUserFolder($user->getUID())->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}//end userFile()
}//end class
