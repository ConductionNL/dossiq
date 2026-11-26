<?php

/**
 * Dossiq Saved Mail Files.
 *
 * The storage side of reading a saved mail file onto a case: the file as the
 * caller can reach it, and its bytes. Storage that will not give the bytes up
 * is logged and answered as absent, so the route can say "Not found" instead
 * of failing.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a saved mail file as the caller and reads its bytes.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class SavedMailFiles {
	/**
	 * Constructor.
	 *
	 * @param IRootFolder     $rootFolder Resolves the node as the caller.
	 * @param LoggerInterface $logger     Logger.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The file, resolved as the caller, or null when they cannot reach it.
	 *
	 * @param string $uid    The caller.
	 * @param int    $fileId The file id.
	 *
	 * @return File|null The file, or null.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function nodeFor(string $uid, int $fileId): ?File {
		if ($fileId <= 0) {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		} catch (Throwable $e) {
			return null;
		}

		if ($node instanceof File) {
			return $node;
		}

		return null;
	}//end nodeFor()

	/**
	 * The file's content, or null when storage will not give it up (logged).
	 *
	 * @param File   $node   The file.
	 * @param string $caseId The case it is read onto, for the log.
	 * @param int    $fileId The file id, for the log.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function contentOf(File $node, string $caseId, int $fileId): ?string {
		try {
			return (string)$node->getContent();
		} catch (Throwable $e) {
			$this->logger->warning(
				'SavedMailController: a saved mail file could not be read from storage',
				['case' => $caseId, 'file' => $fileId, 'error' => $e->getMessage()]
			);

			return null;
		}
	}//end contentOf()
}//end class
