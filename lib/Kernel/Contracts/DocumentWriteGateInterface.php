<?php

/**
 * Contract of DocumentWriteGate
 *
 * The public surface of DocumentWriteGate that other components call, so they depend
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
 * Contract of DocumentWriteGate.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentWriteGateInterface {

	/**
	 * The refusal of a bulk metadata change, or null when every case of every document allows it.
	 *
	 * @param IUser        $user The caller.
	 * @param array<mixed> $ids  The document ids.
	 *
	 * @return JSONResponse|null 403 naming the first refused document and its case, or null.
	 *
	 * @spec openspec/specs/document-writes-need-every-case/spec.md#requirement-a-bulk-document-write-is-refused-whole
	 */
	public function refusedBulkMetadata(IUser $user, array $ids): ?JSONResponse;

	/**
	 * The refusal of a bulk status transition, or null when it may run.
	 *
	 * The case write check comes first; then every selected document must be
	 * readable, checked before anything is written.
	 *
	 * @param IUser        $user The caller.
	 * @param array<mixed> $ids  The document ids.
	 *
	 * @return JSONResponse|null 403, or null.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function refusedBulkTransition(IUser $user, array $ids): ?JSONResponse;

	/**
	 * The refusal of a write to one document, or null when every case allows it.
	 *
	 * @param IUser  $user       The caller.
	 * @param string $documentId The document (informatieobject) id.
	 *
	 * @return JSONResponse|null 403 naming the document and the refused case, or null.
	 *
	 * @spec openspec/specs/document-writes-need-every-case/spec.md#requirement-a-document-write-needs-the-change-right-on-every-case-of-the-document
	 */
	public function refusedWrite(IUser $user, string $documentId): ?JSONResponse;

	/**
	 * Apply one metadata change to every listed document, reporting per id.
	 *
	 * @param IUser                $user     The caller.
	 * @param array<mixed>         $ids      The document ids.
	 * @param array<string, mixed> $metadata The metadata to apply.
	 *
	 * @return array<int, array<string, mixed>> One result entry per id, in order.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function updateAll(IUser $user, array $ids, array $metadata): array;

	/**
	 * Each listing row with `canChangeAccess`, which the Who can see this card offers Change exception by.
	 *
	 * @param IUser                            $user      The caller.
	 * @param array<int, array<string, mixed>> $documents The rows.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
	 */
	public function withChangeRight(IUser $user, array $documents): array;
}//end interface
