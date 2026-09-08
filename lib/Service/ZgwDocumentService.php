<?php

/**
 * Dossiq ZGW Document Service
 *
 * Handles binary file storage for ZGW Documenten API (DRC) documents.
 * Stores files in Nextcloud's file system and manages locking.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use RuntimeException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Service for managing binary document storage in the DRC.
 *
 * A case document belongs to the case, not to whoever uploaded it. It is
 * stored in the informatieobject's own OpenRegister object folder, under
 * `Open Registers/<register>/<informatieobject-uuid>/<filename>`.
 *
 * WHY THAT OWNER, since three plausible ones were on the table.
 *
 * - Not `admin`, which is what this service did until now. Every document in
 *   the instance sat in one person's home, so "Open in Files" 404'd for
 *   everybody else, and `VersionHistoryPanel` PROPFINDs the CURRENT user's
 *   versions endpoint for a file id that is not in their storage, which
 *   returns nothing and renders as "No previous versions" rather than an
 *   error. One hardcoded uid, four symptoms.
 * - Not the current user either, which is the obvious substitution and is a
 *   different wrong answer: the handler who uploads a document would then own
 *   the dossier, and would take it with them when they leave.
 * - The OpenRegister object folder, which is owned by the `openregister`
 *   account and shared with the people who may see the object. No person owns
 *   it, and OpenRegister decides who can read it.
 *
 * The folder is keyed by the informatieobject UUID, not the case, because a
 * ZGW enkelvoudiginformatieobject may legitimately exist before it is attached
 * to any case: `InformatieobjectReader::contentFor()` and the DRC download
 * route are handed a document UUID and nothing else. Filing the bytes under
 * the CASE folder would make those two unable to find them. Referencing the
 * document from the case folder is the follow-up, and it is OpenRegister's to
 * offer: see the DQ1 PR body.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
 */
class ZgwDocumentService {
	/**
	 * The account documents were stored under before this service moved to the
	 * OpenRegister object folder. Read-only, and nothing writes here any more.
	 */
	private const LEGACY_OWNER = 'admin';

	/**
	 * Base folder path of the pre-migration document store.
	 */
	private const LEGACY_STORAGE_BASE = 'dossiq/documenten';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder The Nextcloud root folder
	 * @param SettingsService $settingsService Resolves OpenRegister's FileService
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Store a document file from base64 content.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The file name
	 * @param string $content The base64-encoded file content
	 *
	 * @return int The file size in bytes
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function storeBase64(string $uuid, string $fileName, string $content): int {
		$decoded = base64_decode(string: $content, strict: true);
		if ($decoded === false || $decoded === '') {
			throw new InvalidArgumentException('Invalid base64 content');
		}

		$folder = $this->getDocumentFolder(uuid: $uuid);
		$file = $folder->newFile(path: $fileName);
		$file->putContent(data: $decoded);

		return strlen(string: $decoded);
	}//end storeBase64()

	/**
	 * Store a document file from raw binary content.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The file name
	 * @param string $content The raw binary content
	 *
	 * @return int The file size in bytes
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function storeRaw(string $uuid, string $fileName, string $content): int {
		$folder = $this->getDocumentFolder(uuid: $uuid);
		$file = $folder->newFile(path: $fileName);
		$file->putContent(data: $content);

		return strlen(string: $content);
	}//end storeRaw()

	/**
	 * Get the binary content of a stored document.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The file name
	 *
	 * @return string The file content
	 *
	 * @throws NotFoundException If the file does not exist.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getContent(string $uuid, string $fileName): string {
		return $this->locateFile(uuid: $uuid, fileName: $fileName)->getContent();
	}//end getContent()

	/**
	 * Get the Nextcloud file id of a stored document.
	 *
	 * Additive read accessor alongside {@see storeRaw()}/{@see getContent()} — callers that
	 * need the raw Nextcloud file id (e.g. to persist it on a domain object) resolve it here
	 * instead of duplicating this service's storage-path convention.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The file name
	 *
	 * @return int The Nextcloud file id
	 *
	 * @throws NotFoundException If the file does not exist.
	 *
	 * @spec openspec/specs/libresign-besluit-signing/spec.md
	 */
	public function getFileId(string $uuid, string $fileName): int {
		return $this->locateFile(uuid: $uuid, fileName: $fileName)->getId();
	}//end getFileId()

	/**
	 * Check whether a document file exists.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The file name
	 *
	 * @return bool True if the file exists
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function fileExists(string $uuid, string $fileName): bool {
		try {
			$this->locateFile(uuid: $uuid, fileName: $fileName);
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}//end fileExists()

	/**
	 * Delete all files for a document.
	 *
	 * @param string $uuid The document UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function deleteFiles(string $uuid): void {
		try {
			// BOTH locations. A document uploaded before the move still has its
			// bytes in the legacy tree, and deleting only the current folder
			// would leave those behind as orphans no surface can reach.
			$this->getDocumentFolder(uuid: $uuid)->delete();
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to delete the document folder for ' . $uuid,
				['exception' => $e->getMessage()]
			);
		}

		try {
			$this->getLegacyFolder(uuid: $uuid)?->delete();
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to delete document files for ' . $uuid,
				['exception' => $e->getMessage()]
			);
		}
	}//end deleteFiles()

	/**
	 * Get the MIME type of a stored file.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The file name
	 *
	 * @return string The MIME type
	 *
	 * @throws NotFoundException If the file does not exist.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getMimeType(string $uuid, string $fileName): string {
		return $this->locateFile(uuid: $uuid, fileName: $fileName)->getMimeType();
	}//end getMimeType()

	/**
	 * Store a chunk (bestandsdeel) for a document.
	 *
	 * Chunks are stored as temporary files named `_part_{volgnummer}`
	 * in the document folder until all parts are uploaded and merged.
	 *
	 * @param string $uuid The document UUID
	 * @param int $sequenceNumber The chunk sequence number (1-based)
	 * @param string $content The raw binary chunk content
	 *
	 * @return int The chunk size in bytes
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function storeChunk(string $uuid, int $sequenceNumber, string $content): int {
		$folder = $this->getDocumentFolder(uuid: $uuid);
		$partName = '_part_' . $sequenceNumber;
		$file = $folder->newFile(path: $partName);
		$file->putContent(data: $content);

		return strlen(string: $content);
	}//end storeChunk()

	/**
	 * Check which chunk parts exist for a document.
	 *
	 * @param string $uuid The document UUID
	 * @param int $totalParts The expected total number of parts
	 *
	 * @return array<int> List of volgnummers that have been uploaded
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getUploadedChunks(string $uuid, int $totalParts): array {
		$folder = $this->getDocumentFolder(uuid: $uuid);
		$uploaded = [];

		for ($i = 1; $i <= $totalParts; $i++) {
			try {
				$folder->get(path: '_part_' . $i);
				$uploaded[] = $i;
			} catch (NotFoundException $e) {
				// Not yet uploaded.
			}
		}

		return $uploaded;
	}//end getUploadedChunks()

	/**
	 * Merge all chunk parts into the final document file.
	 *
	 * Reads each `_part_{n}` file in order, concatenates into the final
	 * file, then deletes the temporary chunk files.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The target file name
	 * @param int $totalParts The total number of parts
	 *
	 * @return int The merged file size in bytes
	 *
	 * @throws InvalidArgumentException If not all chunks are present.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function mergeChunks(string $uuid, string $fileName, int $totalParts): int {
		$folder = $this->getDocumentFolder(uuid: $uuid);
		$content = '';

		for ($i = 1; $i <= $totalParts; $i++) {
			$partName = '_part_' . $i;
			try {
				$part = $folder->get(path: $partName);
				if ($part instanceof File === false) {
					throw new InvalidArgumentException('Chunk ' . $i . ' is not a file');
				}

				$content .= $part->getContent();
			} catch (NotFoundException $e) {
				throw new InvalidArgumentException(
					'Missing chunk ' . $i . ' of ' . $totalParts . ' for document ' . $uuid
				);
			}
		}

		// Write the merged file.
		$file = $folder->newFile(path: $fileName);
		$file->putContent(data: $content);

		// Clean up chunk files.
		for ($i = 1; $i <= $totalParts; $i++) {
			try {
				$folder->get(path: '_part_' . $i)->delete();
			} catch (NotFoundException $e) {
				// Already gone.
			}
		}

		return strlen(string: $content);
	}//end mergeChunks()

	/**
	 * Get or create the storage folder a document is WRITTEN to.
	 *
	 * The informatieobject's own OpenRegister object folder, never a person's
	 * home. See the class docblock for why the owner matters.
	 *
	 * @param string $uuid The document UUID
	 *
	 * @return Folder The document folder
	 *
	 * @throws RuntimeException If OpenRegister cannot supply the folder.
	 */
	private function getDocumentFolder(string $uuid): Folder {
		$fileService = $this->settingsService->getFileService();
		if ($fileService === null) {
			throw new RuntimeException(
				'OpenRegister is unavailable, so document ' . $uuid . ' has no folder to be written to'
			);
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('The dossier register is not configured');
		}

		// `getObjectFolder()` is create-if-missing, transfers ownership to the
		// openregister account and shares the folder with the acting user. A
		// bare UUID resolves to `<register folder>/<uuid>` by NAME, which is
		// the same node OpenRegister's own object-files surface materialises
		// when it is handed the ObjectEntity — so the two paths converge on one
		// folder rather than racing to create two.
		$folder = $fileService->getObjectFolder(objectEntity: $uuid, registerId: $register);
		if ($folder instanceof Folder === false) {
			throw new RuntimeException('OpenRegister returned no folder for document ' . $uuid);
		}

		return $folder;
	}//end getDocumentFolder()

	/**
	 * Locate one stored file, in the current folder first and the legacy one second.
	 *
	 * Documents written before this service moved off `getUserFolder('admin')`
	 * still sit in that tree, and they must stay readable: resolving only the
	 * new location would make every already-uploaded document undownloadable
	 * on upgrade, which no test in this app would have caught.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The file name
	 *
	 * @return File The stored file
	 *
	 * @throws NotFoundException If the file is in neither location.
	 */
	private function locateFile(string $uuid, string $fileName): File {
		try {
			$node = $this->getDocumentFolder(uuid: $uuid)->get(path: $fileName);
			if ($node instanceof File === true) {
				return $node;
			}

			throw new NotFoundException('Expected a file, got a folder');
		} catch (NotFoundException $e) {
			// Fall through to the legacy tree below.
			$notFound = $e;
		}

		$legacy = $this->getLegacyFolder(uuid: $uuid);
		if ($legacy === null) {
			throw $notFound;
		}

		$node = $legacy->get(path: $fileName);
		if ($node instanceof File === false) {
			throw new NotFoundException('Expected a file, got a folder');
		}

		return $node;
	}//end locateFile()

	/**
	 * The pre-migration folder for a document, when it still exists.
	 *
	 * READ-ONLY, and the only remaining reference to a named account in this
	 * service. Nothing writes here any more.
	 *
	 * @param string $uuid The document UUID
	 *
	 * @return Folder|null The legacy folder, or null when there is none
	 */
	private function getLegacyFolder(string $uuid): ?Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder(userId: self::LEGACY_OWNER);
			$path = self::LEGACY_STORAGE_BASE . '/' . $uuid;
			if ($userFolder->nodeExists(path: $path) === false) {
				return null;
			}

			$node = $userFolder->get(path: $path);
			if ($node instanceof Folder === false) {
				return null;
			}

			return $node;
		} catch (\Exception $e) {
			return null;
		}
	}//end getLegacyFolder()
}//end class
