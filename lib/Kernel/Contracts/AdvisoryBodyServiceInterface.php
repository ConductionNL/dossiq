<?php

/**
 * Contract of AdvisoryBodyService
 *
 * The public surface of AdvisoryBodyService that other components call, so they depend
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
 * Contract of AdvisoryBodyService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdvisoryBodyServiceInterface {

	/**
	 * List all advisory bodies, optionally filtered.
	 *
	 * @param array<string, mixed> $filters Optional filter params
	 *
	 * @return array<int, array<string, mixed>> List of advisory bodies
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-03
	 */
	public function findAll(array $filters = []): array;

	/**
	 * Search advisory bodies by specialization tag (case-insensitive substring).
	 *
	 * Returns results ranked so that bodies with a matching specialization tag
	 * appear first, followed by all remaining active bodies. Bodies with
	 * active=false are excluded from results.
	 *
	 * @param string $query Search query for specialization tags
	 *
	 * @return array<int, array<string, mixed>> Ranked list of advisory bodies
	 *
	 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-03
	 */
	public function searchBySpecialization(string $query): array;
}//end interface
