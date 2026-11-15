<?php

/**
 * Contract of ReusableProcessStepService
 *
 * The public surface of ReusableProcessStepService that other components call, so they depend
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
 * Contract of ReusableProcessStepService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReusableProcessStepServiceInterface {

	/**
	 * Remove a reusable step, unless a case type still uses it.
	 *
	 * @param string $stepId The step's id.
	 *
	 * @return array{ok: bool, reason: string, usedBy: string} What happened, and
	 *         which case type stopped it when it did not.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	public function delete(string $stepId): array;

	/**
	 * The case types that use one reusable step.
	 *
	 * @param string $stepId The step's id.
	 *
	 * @return array<int, array{id: string, title: string}>|null The case types,
	 *                                                           or null when unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	public function usedBy(string $stepId): ?array;
}//end interface
