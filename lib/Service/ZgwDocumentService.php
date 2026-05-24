<?php

/**
 * Procest ZGW Document Service
 *
 * Handles binary file storage for ZGW Documenten API (DRC) documents.
 * Stores files in Nextcloud's file system and manages locking.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Service for managing binary document storage in the DRC.
 *
 * Stores document files under the admin user's Nextcloud files at:
 * /admin/files/procest/documenten/{uuid}/{filename}
 */
class ZgwDocumentService
{
    /**
     * Base folder path for document storage.
     */
    private const STORAGE_BASE = 'procest/documenten';

    /**
     * Constructor.
     *
     * @param IRootFolder     $rootFolder The Nextcloud root folder
     * @param LoggerInterface $logger     The logger
     *
     * @return void
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Store a document file from base64 content.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     * @param string $content  The base64-encoded file content
     *
     * @return int The file size in bytes
     */
    public function storeBase64(string $uuid, string $fileName, string $content): int
    {
        $decoded = base64_decode(string: $content, strict: true);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('Invalid base64 content');
        }

        $folder = $this->getDocumentFolder(uuid: $uuid);
        $file   = $folder->newFile(path: $fileName);
        $file->putContent(data: $decoded);

        return strlen(string: $decoded);
    }//end storeBase64()

    /**
     * Store a document file from raw binary content.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     * @param string $content  The raw binary content
     *
     * @return int The file size in bytes
     */
    public function storeRaw(string $uuid, string $fileName, string $content): int
    {
        $folder = $this->getDocumentFolder(uuid: $uuid);
        $file   = $folder->newFile(path: $fileName);
        $file->putContent(data: $content);

        return strlen(string: $content);
    }//end storeRaw()

    /**
     * Get the binary content of a stored document.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     *
     * @return string The file content
     *
     * @throws NotFoundException If the file does not exist.
     */
    public function getContent(string $uuid, string $fileName): string
    {
        $folder = $this->getDocumentFolder(uuid: $uuid);
        $node   = $folder->get(path: $fileName);
        if ($node instanceof File === false) {
            throw new NotFoundException('Expected a file, got a folder');
        }

        return $node->getContent();
    }//end getContent()

    /**
     * Check whether a document file exists.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     *
     * @return bool True if the file exists
     */
    public function fileExists(string $uuid, string $fileName): bool
    {
        try {
            $folder = $this->getDocumentFolder(uuid: $uuid);
            $folder->get(path: $fileName);
            return true;
        } catch (NotFoundException $e) {
            return false;
        }
    }//end fileExists()

    /**
     * Delete all files for a document.
     *
     * @param string $uuid The document UUID
     *
     * @return void
     */
    public function deleteFiles(string $uuid): void
    {
        try {
            $userFolder = $this->getUserFolder();
            $path       = self::STORAGE_BASE.'/'.$uuid;
            if ($userFolder->nodeExists(path: $path) === true) {
                $userFolder->get(path: $path)->delete();
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to delete document files for '.$uuid,
                ['exception' => $e->getMessage()]
            );
        }
    }//end deleteFiles()

    /**
     * Get the MIME type of a stored file.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     *
     * @return string The MIME type
     *
     * @throws NotFoundException If the file does not exist.
     */
    public function getMimeType(string $uuid, string $fileName): string
    {
        $folder = $this->getDocumentFolder(uuid: $uuid);
        $file   = $folder->get(path: $fileName);

        return $file->getMimeType();
    }//end getMimeType()

    /**
     * Store a chunk (bestandsdeel) for a document.
     *
     * Chunks are stored as temporary files named `_part_{volgnummer}`
     * in the document folder until all parts are uploaded and merged.
     *
     * @param string $uuid       The document UUID
     * @param int    $volgnummer The chunk sequence number (1-based)
     * @param string $content    The raw binary chunk content
     *
     * @return int The chunk size in bytes
     */
    public function storeChunk(string $uuid, int $volgnummer, string $content): int
    {
        $folder   = $this->getDocumentFolder(uuid: $uuid);
        $partName = '_part_'.$volgnummer;
        $file     = $folder->newFile(path: $partName);
        $file->putContent(data: $content);

        return strlen(string: $content);
    }//end storeChunk()

    /**
     * Check which chunk parts exist for a document.
     *
     * @param string $uuid       The document UUID
     * @param int    $totalParts The expected total number of parts
     *
     * @return array<int> List of volgnummers that have been uploaded
     */
    public function getUploadedChunks(string $uuid, int $totalParts): array
    {
        $folder   = $this->getDocumentFolder(uuid: $uuid);
        $uploaded = [];

        for ($i = 1; $i <= $totalParts; $i++) {
            try {
                $folder->get(path: '_part_'.$i);
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
     * @param string $uuid       The document UUID
     * @param string $fileName   The target file name
     * @param int    $totalParts The total number of parts
     *
     * @return int The merged file size in bytes
     *
     * @throws InvalidArgumentException If not all chunks are present.
     */
    public function mergeChunks(string $uuid, string $fileName, int $totalParts): int
    {
        $folder  = $this->getDocumentFolder(uuid: $uuid);
        $content = '';

        for ($i = 1; $i <= $totalParts; $i++) {
            $partName = '_part_'.$i;
            try {
                $part = $folder->get(path: $partName);
                if ($part instanceof File === false) {
                    throw new InvalidArgumentException('Chunk '.$i.' is not a file');
                }

                $content .= $part->getContent();
            } catch (NotFoundException $e) {
                throw new InvalidArgumentException(
                    'Missing chunk '.$i.' of '.$totalParts.' for document '.$uuid
                );
            }
        }

        // Write the merged file.
        $file = $folder->newFile(path: $fileName);
        $file->putContent(data: $content);

        // Clean up chunk files.
        for ($i = 1; $i <= $totalParts; $i++) {
            try {
                $folder->get(path: '_part_'.$i)->delete();
            } catch (NotFoundException $e) {
                // Already gone.
            }
        }

        return strlen(string: $content);
    }//end mergeChunks()

    /**
     * Get or create the document storage folder for a UUID.
     *
     * @param string $uuid The document UUID
     *
     * @return Folder The document folder
     */
    private function getDocumentFolder(string $uuid): Folder
    {
        $userFolder = $this->getUserFolder();
        $path       = self::STORAGE_BASE.'/'.$uuid;

        if ($userFolder->nodeExists(path: $path) === false) {
            $userFolder->newFolder(path: $path);
        }

        $node = $userFolder->get(path: $path);
        if ($node instanceof Folder === false) {
            throw new NotFoundException('Expected a folder at '.$path);
        }

        return $node;
    }//end getDocumentFolder()

    /**
     * Get the admin user's root folder.
     *
     * @return Folder The user folder
     */
    private function getUserFolder(): Folder
    {
        return $this->rootFolder->getUserFolder(userId: 'admin');
    }//end getUserFolder()
}//end class
