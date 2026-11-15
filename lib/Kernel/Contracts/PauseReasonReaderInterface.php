<?php

/**
 * Contract of PauseReasonReader
 *
 * The public surface of PauseReasonReader that other components call, so they depend
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
 * Contract of PauseReasonReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PauseReasonReaderInterface {

	/**
	 * The case type row behind one case.
	 *
	 * Public because the chasing needs the SAME row for two questions: which
	 * reasons this type declares, and whether it sends the reminder at all.
	 * Two reads of one row is two chances for them to disagree.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The row, empty when the case names no type.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function caseTypeForCase(string $caseId): array;
}//end interface
