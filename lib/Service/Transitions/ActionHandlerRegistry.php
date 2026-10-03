<?php

/**
 * Dossiq Action Handler Registry.
 *
 * Strategy-pattern registry mapping action `type` strings to the corresponding
 * ActionHandlerInterface implementations. Built-in handlers are injected via
 * DI; downstream specs (`bezwaar-lifecycle`) MAY add
 * additional types via `registerHandler()` in their bootstrap so the engine
 * itself does not need to know about them at compile time.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * Registry of action handlers keyed by action type.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T09
 */
class ActionHandlerRegistry {

	/**
	 * Registered handlers keyed by action type.
	 *
	 * @var array<string, ActionHandlerInterface>
	 */
	private array $handlers = [];

	/**
	 * Constructor — wires the built-in handlers.
	 *
	 * Only what a dossiq case can do and no other app owns. The mail,
	 * notification, field, decision and webhook handlers left with change
	 * flow-nodes-to-their-owners: their types now run as their owners' nodes
	 * through {@see \OCA\Dossiq\Service\Flow\RetiredActionRunner}.
	 *
	 * @param CreateTaskHandler $createTask Built-in task handler
	 * @param CreateSubCaseHandler $createSubCase Built-in sub-case handler
	 * @param BesluitvormingPublishHandler $decisionPublish DROP/LVBB publication handler
	 * @param ResumeTermHandler $resumeTerm Lifts a paused term, as a task effect
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function __construct(
		CreateTaskHandler $createTask,
		CreateSubCaseHandler $createSubCase,
		BesluitvormingPublishHandler $decisionPublish,
		ResumeTermHandler $resumeTerm,
	) {
		$this->handlers = [
			'createTask' => $createTask,
			'createSubCase' => $createSubCase,
			'besluitvormingPublish' => $decisionPublish,
			// Declared by a TASK rather than by a transition: finishing the
			// task that processed the aanvulling is what lifts the pause the
			// aanvulling request put on the term.
			'resumeTerm' => $resumeTerm,
		];
	}//end __construct()

	/**
	 * Register an additional handler (DI extension point).
	 *
	 * @param string $type Action type identifier
	 * @param ActionHandlerInterface $handler Handler implementation
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function registerHandler(string $type, ActionHandlerInterface $handler): void {
		$this->handlers[$type] = $handler;
	}//end registerHandler()

	/**
	 * Look up a handler by type.
	 *
	 * @param string $type Action type
	 *
	 * @return ActionHandlerInterface|null
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function getHandler(string $type): ?ActionHandlerInterface {
		return ($this->handlers[$type] ?? null);
	}//end getHandler()

	/**
	 * Get all registered action types.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function getRegisteredTypes(): array {
		return array_keys($this->handlers);
	}//end getRegisteredTypes()
}//end class
