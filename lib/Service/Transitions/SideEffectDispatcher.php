<?php

/**
 * Dossiq Side-Effect Dispatcher.
 *
 * Iterates `automaticActions[]` in declaration order, invokes the registered
 * handler for each action type, and collects the per-action result. Failed
 * handlers SHALL NOT abort the loop and SHALL NOT roll back the status
 * change (REQ-STE-5-002). Unregistered action types are logged and recorded
 * with `error: 'unknown_action_type'`.
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

use Psr\Log\LoggerInterface;

/**
 * Dispatches automatic actions after a successful transition.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T09
 */
class SideEffectDispatcher {
	/**
	 * Constructor.
	 *
	 * @param ActionHandlerRegistry $registry Action handler registry
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ActionHandlerRegistry $registry,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Dispatch all actions sequentially in declaration order.
	 *
	 * @param array<int, array<string, mixed>> $actions Action configs
	 * @param array<string, mixed> $case Case object
	 * @param array<string, mixed> $transitionContext Transition context
	 *
	 * @return array<int, array{type: string, ok: bool, error?: string}>
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function dispatch(array $actions, array $case, array $transitionContext): array {
		$results = [];
		foreach ($actions as $action) {
			$type = (string)($action['type'] ?? '');
			if ($type === '') {
				continue;
			}

			$handler = $this->registry->getHandler(type: $type);
			if ($handler === null) {
				$this->logger->warning(
					'SideEffectDispatcher: unknown action type',
					['type' => $type, 'context' => $transitionContext],
				);
				$results[] = [
					'type' => $type,
					'ok' => false,
					'error' => 'unknown_action_type',
				];
				continue;
			}

			$result = $handler->handle(actionConfig: $action, case: $case, transitionContext: $transitionContext);
			$entry = ['type' => $type, 'ok' => $result->succeeded];
			if ($result->succeeded === false) {
				$entry['error'] = (string)($result->error ?? 'action_failed');
			}

			$results[] = $entry;
		}//end foreach

		return $results;
	}//end dispatch()
}//end class
