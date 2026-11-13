<?php

/**
 * Contract of InformatieobjectReader
 *
 * The public surface of InformatieobjectReader that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;

/**
 * Contract of InformatieobjectReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InformatieobjectReaderInterface {

	/**
	 * Fetch a document's binary content.
	 *
	 * @param string $uuid The informatieobject UUID.
	 * @param string $fileName The stored file name.
	 *
	 * @return string|null The content, or null when the file is missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function contentFor(string $uuid, string $fileName): ?string;

	/**
	 * Filter a dossier's informatieobjecten down to what the user may see.
	 *
	 * @param IUser $user The requesting user.
	 * @param array<int,mixed> $informatieobjecten The unfiltered documents.
	 *
	 * @return array<int, array<string, mixed>> The documents the user may read.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function filterForUser(IUser $user, array $informatieobjecten): array;

	/**
	 * Per-object clearance guard returning a 403/404 JSONResponse on denial.
	 *
	 * @param IUser $user The requesting user.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse|null Null when readable, otherwise the error response.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function guardReadable(IUser $user, string $infoObjectId): ?JSONResponse;

	/**
	 * Load an informatieobject, returning a 404/403 JSONResponse on failure.
	 *
	 * @param IUser $user The requesting user.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed>|JSONResponse The document, or an error response.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function loadReadable(IUser $user, string $infoObjectId): array|JSONResponse;

	/**
	 * Put the display names of each row's access exception on it. Call it on rows already narrowed to what the
	 * caller may see.
	 *
	 * @param array<int, array<string, mixed>> $documents The rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
	 */
	public function withAccessExceptionNames(array $documents): array;

	/**
	 * Put each document's review verdict and the codes of the grounds it cites
	 * on its row, so the Files tab draws the verdict pill, its grounds and the
	 * Assess button from the listing. A document the verdicts do not name (a
	 * case that reviews nothing) carries neither.
	 *
	 * @param string                           $caseId    The case UUID.
	 * @param array<int, array<string, mixed>> $documents The rows the caller may see.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/files-review-row-verdict/spec.md#requirement-each-document-row-carries-its-verdict
	 */
	public function withReviewVerdicts(string $caseId, array $documents): array;

	/**
	 * Put what the virus scanner recorded on every document row that has a
	 * file, so the Files tab's Scan column reads the listing instead of
	 * asking once per row. Call it on rows already narrowed to what the
	 * caller may see.
	 *
	 * @param array<int, array<string, mixed>> $documents The rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md
	 */
	public function withScanVerdicts(array $documents): array;
}//end interface
