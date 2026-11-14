<?php

/**
 * Contract of DossierUploadHandler
 *
 * The public surface of DossierUploadHandler that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of DossierUploadHandler.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DossierUploadHandlerInterface {

	/**
	 * Decode the shared metadata JSON body into an array.
	 *
	 * @param mixed $raw The raw metadata param (JSON string or array).
	 *
	 * @return array<string, mixed> The decoded metadata.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function decodeMetadata(mixed $raw): array;

	/**
	 * Whether the given user may add documents to the given case.
	 *
	 * The case-side authorization for uploads lives here rather than in
	 * `ZaakdossierController` because this class is the collaborator that
	 * performs the write, and because the controller's document-side guard
	 * (`InformatieobjectReader::guardReadable()`) has no document to check on
	 * an upload — the missing half was always the CASE.
	 *
	 * Called once per request, not once per file: the answer cannot differ
	 * between files of the same upload.
	 *
	 * @param IUser $user The authenticated user.
	 * @param string $caseId The case (zaak) UUID.
	 *
	 * @return bool True when the user handles the case.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function hasCaseUploadAccess(IUser $user, string $caseId): bool;

	/**
	 * Detect executable uploads via extension and magic bytes.
	 *
	 * @param string $name The original filename.
	 * @param string $tmpName The temp path of the uploaded content.
	 *
	 * @return bool True when the file appears to be an executable.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 * @spec openspec/specs/document-redaction/spec.md#requirement-an-officer-can-upload-a-redacted-version-instead-req-drd-004
	 */
	public function isExecutable(string $name, string $tmpName): bool;

	/**
	 * Normalise the PHP uploaded-file structure into a flat list of files.
	 *
	 * @param mixed $uploaded The value returned by IRequest::getUploadedFile().
	 *
	 * @return array<int, array<string, mixed>> A list of single-file arrays.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function normaliseUploadedFiles(mixed $uploaded): array;

	/**
	 * Upload a single multipart file into a case dossier.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param array<string, mixed> $file One normalised uploaded-file entry.
	 * @param array<string, mixed> $metadata The shared document metadata.
	 *
	 * @return array<string, mixed> The per-file result entry.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function uploadOne(string $caseId, array $file, array $metadata): array;
}//end interface
