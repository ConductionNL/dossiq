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

use OCA\Dossiq\Service\Flow\RetiredActionRunner;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches automatic actions after a successful transition.
 *
 * @spec openspec/changes/archive/2026-05-11-status-transition-engine/tasks.md#T09
 */
class SideEffectDispatcher {
	/**
	 * Constructor.
	 *
	 * @param ActionHandlerRegistry $registry Local handler registry — the fallback
	 *                                        for an instance without OpenRegister.
	 * @param ContainerInterface $container Resolves OpenRegister's node catalogue lazily.
	 * @param LoggerInterface $logger Logger
	 * @param RetiredActionRunner|null $retired Runs a declared type whose handler left dossiq
	 *                                          as the node that replaced it. Optional so an
	 *                                          existing construction site keeps working.
	 */
	public function __construct(
		private readonly ActionHandlerRegistry $registry,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ?RetiredActionRunner $retired = null,
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
		$transitioned = new TransitionedCase(case: $case, transitionContext: $transitionContext);
		$results = [];
		foreach ($actions as $action) {
			$type = (string)($action['type'] ?? '');
			if ($type === '') {
				continue;
			}

			$results[] = $this->dispatchOne(nodes: $nodes, action: $action, transitioned: $transitioned);
		}//end foreach

		return $results;
	}//end dispatch()

	/**
	 * Run one action: as its replacing node when retired, else as an OpenRegister node, else locally.
	 *
	 * @param FlowNodeRegistry|null $nodes        OpenRegister's node catalogue, or null.
	 * @param array<string, mixed>  $action       Action config, with a non-empty type.
	 * @param TransitionedCase      $transitioned The case and the transition context.
	 *
	 * @return array<string, mixed> The result row.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function dispatchOne(?FlowNodeRegistry $nodes, array $action, TransitionedCase $transitioned): array {
		$type = (string)($action['type'] ?? '');

		// A type dossiq no longer answers itself (mail, notification,
		// field, decision, webhook) runs as the node that replaced it,
		// translated the same way a stored flow step is. A case type
		// declared before the change keeps doing what it declared.
		if ($this->retired !== null && $this->retired->handles(type: $type) === true) {
			return $this->retired->run(type: $type, action: $action, case: $transitioned->case, context: $transitioned->transitionContext);
		}

		// ADR-065: OpenRegister owns the engine, so a transition side effect
		// runs the SAME node a flow would — dossiq contributes those nodes
		// (lib/Flow) and this is the second caller of identical code, not a
		// parallel implementation. The local-handler path is the fallback for
		// an instance without OpenRegister, so a transition never silently
		// skips its side effects because a neighbouring app is absent.
		// Early-return rather than if/else: phpcs rejects the inline
		// ternary this started as, and phpmd's ElseExpression rejects the
		// else that replaced it. Guard-and-return satisfies both without
		// pretending either rule is wrong.
		if ($nodes !== null) {
			return $this->viaNode(nodes: $nodes, action: $action, transitioned: $transitioned);
		}

		return $this->viaHandler(action: $action, transitioned: $transitioned);
	}//end dispatchOne()

	/**
	 * OpenRegister's node catalogue, or null when OpenRegister is absent.
	 *
	 * Resolved lazily and BY NAME: dossiq declares no hard dependency on
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
	 * @param FlowNodeRegistry     $nodes        The node catalogue.
	 * @param array<string, mixed> $action       The action config, with its type.
	 * @param TransitionedCase     $transitioned The case and the transition context.
	 *
	 * @return array<string, mixed> The result row.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
	 */
	private function viaNode(FlowNodeRegistry $nodes, array $action, TransitionedCase $transitioned): array {
		$type = (string)($action['type'] ?? '');
		try {
			$node = $nodes->get(type: 'dossiq.' . $type);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SideEffectDispatcher: unknown action type',
				['type' => $type, 'context' => $transitioned->transitionContext],
			);
			return ['type' => $type, 'ok' => false, 'error' => 'unknown_action_type'];
		}

		try {
			$node->execute(
				call: new FlowNodeCall(items: [['json' => $transitioned->case]], config: $action, context: $transitioned->transitionContext)
			);
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
	 * Run one action through dossiq's own handler registry.
	 *
	 * The fallback for an instance without OpenRegister.
	 *
	 * @param array<string, mixed> $action       The action config, with its type.
	 * @param TransitionedCase     $transitioned The case and the transition context.
	 *
	 * @return array<string, mixed> The result row.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
	 */
	private function viaHandler(array $action, TransitionedCase $transitioned): array {
		$type = (string)($action['type'] ?? '');
		$handler = $this->registry->getHandler(type: $type);
		if ($handler === null) {
			$this->logger->warning(
				'SideEffectDispatcher: unknown action type',
				['type' => $type, 'context' => $transitioned->transitionContext],
			);
			return ['type' => $type, 'ok' => false, 'error' => 'unknown_action_type'];
		}

		$result = $handler->handle(actionConfig: $action, case: $transitioned->case, transitionContext: $transitioned->transitionContext);
		$entry = ['type' => $type, 'ok' => $result->succeeded];
		if ($result->succeeded === false) {
			$entry['error'] = (string)($result->error ?? 'action_failed');
		}

		return $entry;
	}//end viaHandler()
}//end class
