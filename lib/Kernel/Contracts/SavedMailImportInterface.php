<?php

/**
 * Contract of SavedMailImport
 *
 * The public surface of SavedMailImport that other components call, so they depend
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
 * Contract of SavedMailImport.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SavedMailImportInterface {

	/**
	 * The file is still there and nobody could read it.
	 */
	public const OUTCOME_KEPT = 'kept';

	/**
	 * Read one saved mail file as a message on a case.
	 *
	 * @param string $caseId   The case the file is on.
	 * @param string $fileName The file's name, for the parser's hint and the reason.
	 * @param string $raw      The file's bytes.
	 *
	 * @return array{outcome: string, reason: string, subject: string, from: string, receivedAt: string}
	 *         What happened, and what was read.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function import(string $caseId, string $fileName, string $raw): array;
}//end interface
