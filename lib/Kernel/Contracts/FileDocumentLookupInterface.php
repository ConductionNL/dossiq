<?php

/**
 * Contract of FileDocumentLookup
 *
 * The public surface of FileDocumentLookup that other components call, so they depend
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

/**
 * Contract of FileDocumentLookup.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FileDocumentLookupInterface {

	/**
	 * The id of the document record that names a file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return string The record uuid, '' when no record names the file or OpenRegister is not configured.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
	 */
	public function recordIdOfFile(int $fileId): string;
}//end interface
