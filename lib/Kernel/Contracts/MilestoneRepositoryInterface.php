<?php

/**
 * Contract of MilestoneRepository
 *
 * The public surface of MilestoneRepository that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of MilestoneRepository.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MilestoneRepositoryInterface {

	/**
	 * The case type a case belongs to.
	 *
	 * Exists so a caller can ask for a case's progress WITHOUT already knowing
	 * its type. Making the client supply it created a race: the manifest tile
	 * built its URL from the loaded record, and on the first render the record
	 * is not there yet, so the type resolved to an empty path segment and the
	 * request 404'd before the corrected one followed.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return string|null The case type UUID, or null when it cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function findCaseTypeId(string $caseId): ?string;

	/**
	 * Get the milestone definitions declared for a case type.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return array<int, array<string, mixed>> Milestone definitions.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function findDefinitions(string $caseTypeId): array;
}//end interface
