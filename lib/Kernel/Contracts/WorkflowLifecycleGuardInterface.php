<?php

/**
 * Contract of WorkflowLifecycleGuard
 *
 * The public surface of WorkflowLifecycleGuard that other components call, so they depend
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
 * Contract of WorkflowLifecycleGuard.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WorkflowLifecycleGuardInterface {

	/**
	 * The refusals of the most recent publish check, newest call only.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The refusals.
	 *
	 * @spec openspec/specs/process-step-configuration/spec.md
	 */
	public function lastRefusals(): array;

	/**
	 * The step config errors of the most recent publish check, newest call only.
	 *
	 * @return array<int, array{path: string, code: string, message: string}> The errors.
	 *
	 * @spec openspec/specs/process-step-configuration/spec.md
	 */
	public function lastStepConfigErrors(): array;

	/**
	 * Resolve the authoritative lifecycle status of a row.
	 *
	 * Prefers the new lifecycleStatus field; falls back to the legacy isDraft
	 * + isActive booleans for objects created before the schema bump.
	 *
	 * @param array<string, mixed> $row Definition row.
	 *
	 * @return string One of draft|published|deprecated.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function statusOf(array $row): string;
}//end interface
