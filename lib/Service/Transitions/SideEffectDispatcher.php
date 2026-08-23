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

use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches automatic actions after a successful transition.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T09
 */
class SideEffectDispatcher {
	/**
	 * Constructor.
	 *
	 * @param ActionHandlerRegistry $registry Local handler registry — the fallback
	 *                                        for an instance without OpenRegister.
	 * @param ContainerInterface $container Resolves OpenRegister's node catalogue lazily.
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ActionHandlerRegistry $registry,
		private readonly ContainerInterface $container,
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
		$nodes = $this->nodes();
		$results = [];
		foreach ($actions as $action) {
			$type = (string)($action['type'] ?? '');
			if ($type === '') {
				continue;
			}

			// ADR-065: OpenRegister owns the engine, so a transition side effect
			// runs the SAME node a flow would — procest contributes those nodes
			// (lib/Flow) and this is the second caller of identical code, not a
			// parallel implementation. The local-handler path is the fallback for
			// an instance without OpenRegister, so a transition never silently
			// skips its side effects because a neighbouring app is absent.
			if ($nodes !== null) {
				$results[] = $this->viaNode(
					nodes: $nodes,
					type: $type,
					action: $action,
					case: $case,
					transitionContext: $transitionContext
				);
			} else {
				$results[] = $this->viaHandler(
					type: $type,
					action: $action,
					case: $case,
					transitionContext: $transitionContext
				);
			}//end if
		}//end foreach

		return $results;
	}//end dispatch()

	/**
	 * OpenRegister's node catalogue, or null when OpenRegister is absent.
	 *
	 * Resolved lazily and BY NAME: procest declares no hard dependency on
	 * OpenRegister, and an instance without it must still fire its side effects.
	 *
	 * @return FlowNodeRegistry|null The catalogue, or null.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
	 */
	private function nodes(): ?FlowNodeRegistry {
		if (class_exists('OCA\\OpenRegister\\Service\\Flow\\FlowNodeRegistry') === false) {
			return null;
		}

		try {
			$registry = $this->container->get('OCA\\OpenRegister\\Service\\Flow\\FlowNodeRegistry');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SideEffectDispatcher: OpenRegister node catalogue unavailable; using the local handlers',
				['error' => $e->getMessage()],
			);
			return null;
		}

		if (($registry instanceof FlowNodeRegistry) === false) {
			return null;
		}

		return $registry;
	}//end nodes()

	/**
	 * Run one action through OpenRegister's node catalogue.
	 *
	 * A node signals failure by THROWING — the flow engine's onError policy only
	 * sees what propagates out of execute(). This dispatcher's contract is the
	 * opposite and predates it: a failed action must not abort the remaining
	 * ones or roll back the status change. So the throw is caught here and
	 * turned back into a result row.
	 *
	 * @param FlowNodeRegistry $nodes The node catalogue.
	 * @param string $type The action type.
	 * @param array<string, mixed> $action The action config.
	 * @param array<string, mixed> $case The case.
	 * @param array<string, mixed> $transitionContext The transition context.
	 *
	 * @return array<string, mixed> The result row.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
	 */
	private function viaNode(
		FlowNodeRegistry $nodes,
		string $type,
		array $action,
		array $case,
		array $transitionContext,
	): array {
		try {
			$node = $nodes->get(type: 'procest.' . $type);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SideEffectDispatcher: unknown action type',
				['type' => $type, 'context' => $transitionContext],
			);
			return ['type' => $type, 'ok' => false, 'error' => 'unknown_action_type'];
		}

		try {
			$node->execute(items: [['json' => $case]], config: $action, context: $transitionContext);
		} catch (Throwable $e) {
			// An exception with an empty message still has to report SOMETHING —
			// 'ok' => false with a blank error reads downstream as "failed, cause
			// unknown", which is indistinguishable from a bug in this handler.
			$message = $e->getMessage();
			if ($message === '') {
				$message = 'action_failed';
			}

			return ['type' => $type, 'ok' => false, 'error' => $message];
		}

		return ['type' => $type, 'ok' => true];
	}//end viaNode()

	/**
	 * Run one action through procest's own handler registry.
	 *
	 * The fallback for an instance without OpenRegister.
	 *
	 * @param string $type The action type.
	 * @param array<string, mixed> $action The action config.
	 * @param array<string, mixed> $case The case.
	 * @param array<string, mixed> $transitionContext The transition context.
	 *
	 * @return array<string, mixed> The result row.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
	 */
	private function viaHandler(
		string $type,
		array $action,
		array $case,
		array $transitionContext,
	): array {
		$handler = $this->registry->getHandler(type: $type);
		if ($handler === null) {
			$this->logger->warning(
				'SideEffectDispatcher: unknown action type',
				['type' => $type, 'context' => $transitionContext],
			);
			return ['type' => $type, 'ok' => false, 'error' => 'unknown_action_type'];
		}

		$result = $handler->handle(actionConfig: $action, case: $case, transitionContext: $transitionContext);
		$entry = ['type' => $type, 'ok' => $result->succeeded];
		if ($result->succeeded === false) {
			$entry['error'] = (string)($result->error ?? 'action_failed');
		}

		return $entry;
	}//end viaHandler()
}//end class
