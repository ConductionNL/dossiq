<?php

/**
 * Contract of CasePriorityService
 *
 * The public surface of CasePriorityService that other components call, so they depend
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
 * Contract of CasePriorityService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CasePriorityServiceInterface {

	/**
	 * The whole priority block a case should carry, given what it holds now.
	 *
	 * One function rather than three, because the three answers have to agree:
	 * `priority` is what everything reads, `priorityOrder` is what a list
	 * sorts on, and the two disagreeing is a queue that sorts differently from
	 * how it reads.
	 *
	 * @param array<string, mixed> $case The case payload as it is being saved.
	 *
	 * @return array<string, mixed> The fields to write back onto the case.
	 *
	 * @spec openspec/specs/case-priority/spec.md
	 */
	public function resolve(array $case): array;
}//end interface
