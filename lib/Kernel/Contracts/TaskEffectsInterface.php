<?php

/**
 * Contract of TaskEffects
 *
 * The public surface of TaskEffects that other components call, so they depend
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
 * Contract of TaskEffects.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TaskEffectsInterface {

	/**
	 * The effects one task declares, in declared order.
	 *
	 * @param array<string, mixed> $task The task, as the engine answers it.
	 *
	 * @return array<int, array<string, mixed>> The effects; empty when it declares none.
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function declaredOn(array $task): array;

	/**
	 * Run the effects of a completed task, in declared order.
	 *
	 * Per EFFECT, not per task: one effect that fails does not stop the
	 * others, because a declaration that sends a letter and resumes a term
	 * should still resume the term when the mail server is down. Each outcome
	 * is returned and the failures are logged with the task that declared
	 * them.
	 *
	 * @param array<int, array<string, mixed>> $effects The declared effects.
	 * @param array<string, mixed>             $case    The case the task is on.
	 * @param array<string, mixed>             $context What ran them, for the handlers.
	 *
	 * @return array<int, array{type: string, ran: bool, error: string}> One result per effect.
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function run(array $effects, array $case, array $context): array;
}//end interface
