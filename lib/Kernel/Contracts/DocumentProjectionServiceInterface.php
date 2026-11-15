<?php

/**
 * Contract of DocumentProjectionService
 *
 * The public surface of DocumentProjectionService that other components call, so they depend
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

use OCP\Files\File;
use OCP\Files\Folder;
use RuntimeException;

/**
 * Contract of DocumentProjectionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentProjectionServiceInterface {

	/**
	 * Run a projection as the system, for a file written with nobody signed in.
	 *
	 * Without OpenRegister the operation runs as it is.
	 *
	 * @param callable $operation The projection.
	 *
	 * @return mixed What the projection returns.
	 *
	 * @spec openspec/changes/a-file-written-without-a-user-is-projected-as-the-system/specs/document-projection/spec.md#requirement-a-file-written-without-a-signed-in-user-is-projected-as-the-system-req-dpr-006
	 */
	public function asSystem(callable $operation): mixed;

	/**
	 * An object's folder in the register tree, created when missing.
	 *
	 * @param string $objectId The object uuid, a case or a record.
	 *
	 * @return Folder|null The folder, null when OpenRegister is unavailable or answers none.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function folderOf(string $objectId): ?Folder;

	/**
	 * Move an API-first document's file into the case's folder on its first join.
	 *
	 * A record whose file still sits in its own folder is moved; one whose
	 * file is already under a case folder is left where it is, so a second
	 * join never moves anything. The file id is re-read after the move and
	 * written back only when the storage changed it.
	 *
	 * @param string $recordId The informatieobject uuid.
	 * @param string $caseId The case uuid the join names.
	 *
	 * @return bool True when the file moved.
	 *
	 * @throws RuntimeException When the case has no folder.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function homeDocument(string $recordId, string $caseId): bool;

	/**
	 * Whether a node path lies under the register tree at all.
	 *
	 * The cheap gate every listener call passes first, so the vast majority
	 * of Nextcloud writes cost one string search and no database read.
	 *
	 * @param string $path The node's path as Nextcloud reports it.
	 *
	 * @return bool True under `Open Registers/`.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function isUnderRegisterTree(string $path): bool;

	/**
	 * Make the record for a file in a case folder, or refresh the one it has.
	 *
	 * @param File $node The file that was created or written.
	 *
	 * @return array<string, mixed>|null The record as stored, or null when the file is not a case's document.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function projectNode(File $node): ?array;

	/**
	 * A renamed or moved file: follow it.
	 *
	 * Same folder: the record's file name follows, and its title too when the
	 * title was still the one derived from the old name. Another case's
	 * folder: the join moves with the file. Out of every case folder: the
	 * source case loses the document, and a record with no case left is
	 * retired as a delete would retire it.
	 *
	 * @param File $node The file at its new path.
	 * @param string $sourcePath The path it had before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function rehomeNode(File $node, string $sourcePath): void;

	/**
	 * The file is gone: retire its record and every join to it.
	 *
	 * A draft record is deleted with its file; a final one keeps answering on
	 * the DRC API as archived. An archived one is left alone.
	 *
	 * @param File $node The deleted file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function retireNode(File $node): void;
}//end interface
