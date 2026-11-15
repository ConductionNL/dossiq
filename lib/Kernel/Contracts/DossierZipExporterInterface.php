<?php

/**
 * Contract of DossierZipExporter
 *
 * The public surface of DossierZipExporter that other components call, so they depend
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
 * Contract of DossierZipExporter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DossierZipExporterInterface {

	/**
	 * Build the ZIP archive and return its bytes.
	 *
	 * @param IUser $user The requesting user (clearance filtering).
	 * @param array<int, array<string, mixed>> $documents The documents to include.
	 * @param bool $flatLayout True for a flat archive, false for per-type subfolders.
	 *
	 * @return string The archive bytes.
	 *
	 * @throws \Throwable When the archive cannot be written or read back.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function buildZipData(IUser $user, array $documents, bool $flatLayout): string;

	/**
	 * Collect the case's documents, optionally narrowed to selected ids.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<int,mixed> $selectedIds Optional subset of informatieobject ids.
	 *
	 * @return array<int, array<string, mixed>> The documents to export.
	 *
	 * @throws \RuntimeException When the dossier cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
	 */
	public function collectDocuments(string $caseId, array $selectedIds): array;
}//end interface
