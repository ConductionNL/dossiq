<?php

/**
 * Dossiq FindsFilesById trait
 *
 * A case's files live in OpenRegister's managed tree, in the openregister
 * account's home. The signed-in handler has no mount on that home, so
 * `IRootFolder::getById()` under their session answers nothing for a file
 * on their own case, and a reader that only asked the root folder read no
 * bytes (rehearsal R3: the Woo publication went out with no documents).
 * OpenRegister's `FileService::getFileById()` looks in the managed tree
 * first, for any caller; the root folder stays the fallback for a file
 * outside it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCP\Files\File;
use OCP\Files\IRootFolder;

/**
 * One file by its Nextcloud id, wherever OpenRegister keeps it.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
trait FindsFilesById {

	/**
	 * The file with this id: from OpenRegister's managed tree first, then from the root folder.
	 *
	 * @param object|null $fileService OpenRegister's FileService, or null when it is unavailable.
	 * @param IRootFolder $rootFolder  The root folder, for a file outside the managed tree.
	 * @param int         $fileId      The Nextcloud file id.
	 *
	 * @return File|null The file, or null when neither knows it.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	protected function fileById(?object $fileService, IRootFolder $rootFolder, int $fileId): ?File {
		if ($fileId <= 0) {
			return null;
		}

		if ($fileService !== null && method_exists($fileService, 'getFileById') === true) {
			$managed = $fileService->getFileById($fileId);
			if ($managed instanceof File) {
				return $managed;
			}
		}

		foreach ($rootFolder->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}//end fileById()
}//end trait
