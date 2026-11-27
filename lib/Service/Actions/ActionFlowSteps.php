<?php

/**
 * Dossiq action flow steps.
 *
 * What a stored automatic action becomes as flow steps: its configuration
 * read, its type looked up in the retired-node table and translated, and a
 * refusal when it cannot become a step or names a node nothing implements.
 * Split out of {@see \OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator},
 * which keeps reading the actions, the run, the dry run and the flow write.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The flow steps of one automatic action, or the reason it has none.
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ActionFlowSteps {

	/**
	 * The flow-node id space Dossiq's configured-action catalogue registered under.
	 *
	 * Every node in it has since gone to the app that owns what it did, so an
	 * action type is looked up in the retired-node table as this prefix plus
	 * the type, and becomes whatever that table says replaces it.
	 */
	private const NODE_PREFIX = 'dossiq.action.';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container  Service container, for the flow node registry.
	 * @param LoggerInterface       $logger     Says which node no registry answers to.
	 * @param RetiredNodeMap        $map        What each retired action type became.
	 * @param RetiredNodeTranslator $translator Turns an action's config into its replacement's.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly RetiredNodeMap $map,
		private readonly RetiredNodeTranslator $translator,
	) {
	}//end __construct()

	/**
	 * Resolve the steps an action becomes, or the row that skips it.
	 *
	 * Skipped when the action has no faithful equivalent, or when a step names
	 * a node nothing implements: a flow around it would never run.
	 *
	 * @param ProjectedAction $projected The identified action; receives its steps.
	 *
	 * @return array{outcome: string, marker: string, detail: string}|null The skip row, or null to go on.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function resolveSteps(ProjectedAction $projected): ?array {
		$type = $projected->type();
		try {
			$projected->steps = $this->steps(type: $type, config: $this->config(action: $projected->action));
		} catch (UnmappableStep $e) {
			return $this->skippedRow(
				marker: $projected->marker,
				detail: '"' . $type . '" cannot become a flow step: ' . $e->getMessage(),
			);
		}

		return $this->missingNodeRefusal(projected: $projected);
	}//end resolveSteps()

	/**
	 * The row that skips an action one of whose steps no node implements.
	 *
	 * @param ProjectedAction $projected The action with its resolved steps.
	 *
	 * @return array{outcome: string, marker: string, detail: string}|null The skip row, or null when every node exists.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function missingNodeRefusal(ProjectedAction $projected): ?array {
		$missing = $this->firstMissingNode(steps: $projected->steps);
		if ($missing === null) {
			return null;
		}

		return $this->skippedRow(
			marker: $projected->marker,
			detail: 'no node implements "' . $missing . '" (for "' . $projected->type() . '"); a flow around it would never run',
		);
	}//end missingNodeRefusal()

	/**
	 * The first step whose node no registry entry answers to.
	 *
	 * @param array<int, array{type: string, config: array<string, mixed>}> $steps The steps.
	 *
	 * @return string|null The missing node id, or null when every node exists.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function firstMissingNode(array $steps): ?string {
		foreach ($steps as $step) {
			if ($this->nodeExists(nodeType: $step['type']) === false) {
				return $step['type'];
			}
		}

		return null;
	}//end firstMissingNode()

	/**
	 * A row reporting an action that was left alone.
	 *
	 * @param string $marker The action's provenance marker.
	 * @param string $detail Why it was skipped.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function skippedRow(string $marker, string $detail): array {
		return [
			'outcome' => 'skipped',
			'marker' => $marker,
			'detail' => $detail,
		];
	}//end skippedRow()

	/**
	 * Whether OpenRegister's node registry knows this node id.
	 *
	 * Checked BEFORE writing, because a flow whose action node does not resolve
	 * is the exact failure this programme already paid for once: `spawnCase` sat
	 * in shipped data naming a handler nothing implemented, and every transition
	 * that ran it reported success while doing nothing.
	 *
	 * @param string $nodeType The fully-qualified node id.
	 *
	 * @return bool True when a node answers to it.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function nodeExists(string $nodeType): bool {
		try {
			$registry = $this->container->get('OCA\OpenRegister\Service\Flow\FlowNodeRegistry');
			$registry->get($nodeType);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: no flow node answers to this id',
				['app' => AppIdentity::APP_ID, 'node' => $nodeType, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end nodeExists()

	/**
	 * The steps one action becomes.
	 *
	 * @param string               $type   The action type.
	 * @param array<string, mixed> $config The action's decoded config.
	 *
	 * @return array<int, array{type: string, config: array<string, mixed>}> The steps.
	 *
	 * @throws UnmappableStep When the action has no faithful equivalent.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function steps(string $type, array $config): array {
		$nodeType = self::NODE_PREFIX . $type;
		$row = $this->map->rowFor(type: $nodeType);
		if ($row === null) {
			return [['type' => $nodeType, 'config' => $config]];
		}

		return $this->translator->stepsFor(row: $row, config: $config);
	}//end steps()

	/**
	 * Decode the action's handler config.
	 *
	 * Stored as a JSON string by the schema. A value that does not decode to an
	 * array yields an empty config rather than a fatal: the flow is still worth
	 * creating so an admin can see and repair it in the flow editor.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 *
	 * @return array<string, mixed> The handler config.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function config(array $action): array {
		$raw = ($action['config'] ?? '');
		if (is_array($raw) === true) {
			return $raw;
		}

		$decoded = json_decode((string)$raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end config()
}//end class
