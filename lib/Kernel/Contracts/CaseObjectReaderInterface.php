<?php

/**
 * Contract of CaseObjectReader
 *
 * The public surface of CaseObjectReader that other components call, so they depend
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
 * Contract of CaseObjectReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseObjectReaderInterface {

	/**
	 * Fetch a single case object by UUID and normalise it to an array.
	 *
	 * @param string $caseUuid Case UUID.
	 *
	 * @return array<string, mixed>|null The case, or null when missing.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function fetchCaseById(string $caseUuid): ?array;

	/**
	 * Load a caseType by id or slug.
	 *
	 * @param string $caseTypeId Identifier.
	 *
	 * @return array<string, mixed>|null The caseType, or null when missing.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function loadCaseType(string $caseTypeId): ?array;
}//end interface
