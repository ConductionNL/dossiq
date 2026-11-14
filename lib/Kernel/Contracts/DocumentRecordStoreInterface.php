<?php

/**
 * Contract of DocumentRecordStore
 *
 * The public surface of DocumentRecordStore that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of DocumentRecordStore.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentRecordStoreInterface {

	/**
	 * Delete a record's joins, all of them or those of one case.
	 *
	 * @param string $recordId The record uuid.
	 * @param string $caseId A case uuid, or '' for every case.
	 *
	 * @return int How many joins went.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function deleteJoins(string $recordId, string $caseId = ''): int;

	/**
	 * Delete a record.
	 *
	 * @param string $recordId The uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function deleteRecord(string $recordId): void;

	/**
	 * Join a case and a record, unless they are joined already.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $recordId The record uuid.
	 * @param array<string, mixed> $extra More fields for a NEW join, such as the `provenance` a
	 *        document linked through Gather documents carries; an existing join is left as it is.
	 *
	 * @return bool True when a join was created.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-every-gathered-document-records-where-it-was-found-req-woo-014
	 */
	public function ensureJoin(string $caseId, string $recordId, array $extra = []): bool;

	/**
	 * One document record, by uuid or by the file id it names.
	 *
	 * @param string $recordId The uuid, or '' to look up by file id.
	 * @param int $fileId The Nextcloud file id, when no uuid is given.
	 *
	 * @return array<string, mixed>|null The record row with `id`, null when there is none.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function findRecord(string $recordId = '', int $fileId = 0): ?array;

	/**
	 * The joins of a record, all of them or those of one case.
	 *
	 * @param string $recordId The record uuid.
	 * @param string $caseId A case uuid, or '' for every case.
	 *
	 * @return array<int, array<string, mixed>> The join rows.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function joinsFor(string $recordId, string $caseId = ''): array;

	/**
	 * Store a file in an object's folder, attached to that object.
	 *
	 * @param string $objectId The object uuid, a case.
	 * @param string $fileName The file name.
	 * @param string $content The bytes.
	 *
	 * @return int The Nextcloud file id, 0 when it could not be read back.
	 *
	 * @throws RuntimeException When OpenRegister's file service is unavailable or the write fails.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function storeFileOnObject(string $objectId, string $fileName, string $content): int;
}//end interface
