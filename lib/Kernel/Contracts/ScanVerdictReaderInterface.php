<?php

/**
 * Contract of ScanVerdictReader
 *
 * The public surface of ScanVerdictReader that other components call, so they depend
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
 * Contract of ScanVerdictReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ScanVerdictReaderInterface {

	/**
	 * The verdict recorded for one file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return array{state: string, scannedAt: string|null, scannerPresent: bool}
	 *         The state, the moment of the check when there was one, and
	 *         whether a scanner is installed, so the reader of this answer can
	 *         tell "no scanner here" from "not reached yet".
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md
	 */
	public function verdictFor(int $fileId): array;
}//end interface
