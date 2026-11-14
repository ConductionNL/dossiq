<?php

/**
 * Contract of CaseAccessPurposeService
 *
 * The public surface of CaseAccessPurposeService that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of CaseAccessPurposeService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseAccessPurposeServiceInterface {

	/**
	 * Whether the reader must state a purpose to open this case.
	 *
	 * A case whose case type names no special categories asks none. A reader
	 * who holds one of the configured generic roles on the case (by default
	 * the handler) does not either: the configured purpose is recorded for
	 * them, marked automatic, and the case opens without the dialog.
	 *
	 * @param string $caseId   The case uuid.
	 * @param string $readerId The reader's user id.
	 *
	 * @return array{required: bool, recorded: bool} Whether a purpose is asked, and whether one was recorded now.
	 *
	 * @throws RefusedException When the case cannot be read, or the automatic purpose cannot be written.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-the-cases-own-handler-opens-it-without-the-dialog
	 */
	public function check(string $caseId, string $readerId): array;

	/**
	 * Record the purpose, or say why it is refused.
	 *
	 * The case is read as the reader, so a case the reader may not open is
	 * refused before anything is written. A case type that names no special
	 * categories asks no purpose and nothing is written. A purpose that could
	 * not be written is refused, so the page stays closed rather than opening
	 * unrecorded.
	 *
	 * @param string $caseId   The case uuid.
	 * @param string $readerId The reader's user id.
	 * @param string $purpose  One of {@see self::PURPOSES}.
	 * @param string $note     The reader's own words, for `other`.
	 *
	 * @return array{recorded: bool, required: bool, roleAdded?: bool} Whether a purpose was required and recorded,
	 *         and whether it bound the reader to a role.
	 *
	 * @throws RefusedException When the purpose is invalid, the case cannot be read, or the entry cannot be written.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
	 */
	public function record(string $caseId, string $readerId, string $purpose, string $note): array;
}//end interface
