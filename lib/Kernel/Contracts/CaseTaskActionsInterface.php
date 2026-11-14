<?php

/**
 * Contract of CaseTaskActions
 *
 * The public surface of CaseTaskActions that other components call, so they depend
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
 * Contract of CaseTaskActions.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTaskActionsInterface {

	/**
	 * Cancel one task through the engine.
	 *
	 * The engine decides whether the task may be cancelled now; its reason is
	 * kept, because it is the only part a handler can act on.
	 *
	 * @param string $taskId The task.
	 * @param string $reason Why, recorded on the task.
	 * @param string $actor  Who is cancelling it.
	 *
	 * @return array{cancelled: bool, task: string} What happened.
	 *
	 * @throws RuntimeException `cancel_refused:<the engine's reason>`.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function cancel(string $taskId, string $reason, string $actor): array;

	/**
	 * Let one candidate take an unclaimed task.
	 *
	 * The engine rules on whether this caller is in the task's candidate pool
	 * and refuses with its own reason, which is kept: a generic failure would
	 * throw away the only part a handler can act on.
	 *
	 * @param string $taskId The task.
	 * @param string $actor  Who is taking it.
	 *
	 * @return array{claimed: bool, task: string} What happened.
	 *
	 * @throws RuntimeException With the engine's own refusal.
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function claim(string $taskId, string $actor): array;

	/**
	 * Complete one task with the answers its form asked for.
	 *
	 * @param string               $taskId  The task.
	 * @param array<string, mixed> $data    The form answers, keyed by field.
	 * @param string               $outcome The outcome word recorded on the task.
	 * @param string               $actor   Who is completing it.
	 *
	 * @return array{completed: bool, task: string, case: string, state: string, isTerminal: bool, notice: array<string, string>} What happened.
	 *
	 * @throws RuntimeException Named refusals: `task_not_found`,
	 *                          `required_field:<field>`,
	 *                          `unresolvable_effect:<type>`, `complete_failed`.
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function complete(string $taskId, array $data, string $outcome, string $actor): array;

	/**
	 * Whether the task engine on this instance answers a claim act.
	 *
	 * Asked of the engine rather than assumed, and answered to the surface
	 * rather than only acted on: a claim button that does nothing would be
	 * worse than none, and a case type promising a candidate group the engine
	 * ignores would be worse still.
	 *
	 * @return boolean True when a task can be claimed.
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function engineAnswersClaim(): bool;

	/**
	 * Read one task, or null when the engine has none by that id.
	 *
	 * @param string $taskId The task.
	 *
	 * @return array<string, mixed>|null The task.
	 *
	 * @spec openspec/specs/task-management/spec.md
	 */
	public function find(string $taskId): ?array;

	/**
	 * The title a handler knows a task by, else its id.
	 *
	 * @param array<string, mixed> $task The task.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function titleOf(array $task): string;
}//end interface
