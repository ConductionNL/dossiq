<?php

/**
 * Contract of EngineTaskGateway
 *
 * The public surface of EngineTaskGateway that other components call, so they depend
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
 * Contract of EngineTaskGateway.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EngineTaskGatewayInterface {

	/**
	 * Read one engine task, as a plain array, or null when it is gone.
	 *
	 * The array shape is deliberate: `AskPersonTaskStore` hands what it
	 * reads to `DossiqAskPersonNode`, which asks about `status` and
	 * `flowRun` in the register's vocabulary. Translating here keeps that
	 * vocabulary in ONE place rather than spreading the engine's names
	 * through the node and its two heartbeat-recovery tests.
	 *
	 * @param string $taskId The engine task uuid.
	 *
	 * @return array<string, mixed>|null The task, or null.
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function find(string $taskId): ?array;

	/**
	 * Mirror a task through the engine's TRUSTED import path.
	 *
	 * 🔴 A BACKFILL IS THE EXCEPTION THE ENGINE ALREADY ACCOUNTS FOR. Most of
	 * dossiq's existing tasks are `completed`, and `create()` refuses them:
	 * measured, 33 of 33 failed with "A task cannot be created in terminal
	 * state 'completed'". `import()` is the engine's own documented path for
	 * "a completed approval carried over from a legacy shape".
	 *
	 * 🔴 IT IS THE ONLY WRITE PATH NOW. There was a `mirrorCreate()` beside it
	 * that asked the engine to VALIDATE, for the dual-run when a task was
	 * written to both the register object and the engine. Nothing in lib/
	 * called it once `CreateTaskHandler` moved to the trusted path, so it was
	 * a second way in that only the tests used, and remove-casetask 4.3
	 * retires the dual-run rather than leaving one half of it standing.
	 *
	 * @param array<string, mixed> $task   The task fields, in the shape the register task carried.
	 * @param string               $caseId The case this task is on.
	 * @param string|null          $actor  The acting user, or null.
	 *
	 * @return string The engine task uuid, or '' when not written.
	 *
	 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
	 */
	public function mirrorImport(array $task, string $caseId, ?string $actor): string;
}//end interface
