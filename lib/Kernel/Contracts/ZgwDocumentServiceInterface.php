<?php

/**
 * Contract of ZgwDocumentService
 *
 * The public surface of ZgwDocumentService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use InvalidArgumentException;
use OCP\Files\NotFoundException;

/**
 * Contract of ZgwDocumentService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZgwDocumentServiceInterface {

	/**
	 * Store a document file from base64 content.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The file name
	 * @param string $content The base64-encoded file content
	 *
	 * @return int The file size in bytes
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function storeBase64(string $uuid, string $fileName, string $content): int;

	/**
	 * Delete all files for a document.
	 *
	 * @param string $uuid The document UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function deleteFiles(string $uuid): void;

	/**
	 * Check whether a document file exists.
	 *
	 * @param string $uuid The document UUID
	 * @param string $fileName The file name
	 *
	 * @return bool True if the file exists
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function fileExists(string $uuid, string $fileName): bool;

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getContent(string $uuid, string $fileName): string;

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function storeChunk(string $uuid, int $sequenceNumber, string $content): int;

	/**
	 * Check which chunk parts exist for a document.
	 *
	 * @param string $uuid The document UUID
	 * @param int $totalParts The expected total number of parts
	 *
	 * @return array<int> List of volgnummers that have been uploaded
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getUploadedChunks(string $uuid, int $totalParts): array;

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function mergeChunks(string $uuid, string $fileName, int $totalParts): int;

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
	public function getFileId(string $uuid, string $fileName): int;
}//end interface
