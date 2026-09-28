<?php

/**
 * Rewrites one flow graph against the retired-node table.
 *
 * Pure: it takes nodes and edges and returns nodes, edges and a list of what
 * changed. Reading and saving the flow is the repair step's job.
 *
 * A REMOVED STEP IS BRIDGED, NOT CUT OUT. Every edge that led into the step is
 * pointed at the step's successors, so a flow `trigger -> reminder -> end`
 * becomes `trigger -> end` rather than a trigger that leads nowhere. A step
 * with no successor leaves its predecessors without that edge, and the change
 * row says so, because OpenRegister refuses to publish a graph with a dead end.
 *
 * Edge endpoints come in two shapes in stored flows: a single node id
 * (`"to": "end"`) and a list (`"to": ["end"]`). Both are read, and each edge
 * keeps the shape it had.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * Applies {@see RetiredNodeMap} to a flow's nodes and edges.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
class RetiredNodeRewriter {

	/**
	 * Constructor.
	 *
	 * @param RetiredNodeMap $map The retired-node table.
	 */
	public function __construct(
		private readonly RetiredNodeMap $map,
	) {
	}//end __construct()

	/**
	 * Rewrite a graph.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 * @param array<int, mixed> $edges The flow's edges.
	 *
	 * @return array{
	 *     nodes: array<int, mixed>,
	 *     edges: array<int, mixed>,
	 *     changes: array<int, array{step: string, type: string, outcome: string, replacement: string|null, reason: string, orphaned: bool}>
	 * } The rewritten graph and one row per changed step. No rows means nothing changed.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function rewrite(array $nodes, array $edges): array {
		$changes = [];
		$kept = [];

		foreach ($nodes as $node) {
			$type = '';
			if (is_array($node) === true) {
				$type = (string)($node['type'] ?? '');
			}

			$row = $this->map->rowFor(type: $type);
			if ($row === null) {
				$kept[] = $node;
				continue;
			}

			$stepId = (string)($node['id'] ?? '');

			if ($row['replacement'] !== null) {
				$node['type'] = $row['replacement'];
				$kept[] = $node;
				$changes[] = [
					'step' => $stepId,
					'type' => $type,
					'outcome' => 'replaced',
					'replacement' => $row['replacement'],
					'reason' => $row['reason'],
					'orphaned' => false,
				];
				continue;
			}

			$bridged = $this->bridge(edges: $edges, stepId: $stepId);
			$edges = $bridged['edges'];
			$changes[] = [
				'step' => $stepId,
				'type' => $type,
				'outcome' => 'removed',
				'replacement' => null,
				'reason' => $row['reason'],
				'orphaned' => $bridged['orphaned'],
			];
		}//end foreach

		return ['nodes' => $kept, 'edges' => $edges, 'changes' => $changes];
	}//end rewrite()

	/**
	 * Remove one step from the edge list, pointing its inbound edges at its successors.
	 *
	 * @param array<int, mixed> $edges  The current edges.
	 * @param string            $stepId The step being removed.
	 *
	 * @return array{edges: array<int, mixed>, orphaned: bool} The edges, and whether
	 *         inbound edges were dropped because the step had no successor.
	 */
	private function bridge(array $edges, string $stepId): array {
		$successors = [];
		foreach ($edges as $edge) {
			if (is_array($edge) === true && in_array($stepId, $this->endpoints(value: ($edge['from'] ?? null)), true) === true) {
				foreach ($this->endpoints(value: ($edge['to'] ?? null)) as $target) {
					if ($target !== $stepId) {
						$successors[$target] = true;
					}
				}
			}
		}

		$successors = array_keys($successors);
		$orphaned = false;
		$out = [];

		foreach ($edges as $edge) {
			if (is_array($edge) === false) {
				$out[] = $edge;
				continue;
			}

			$from = $this->endpoints(value: ($edge['from'] ?? null));
			if (in_array($stepId, $from, true) === true) {
				$rest = array_values(array_diff($from, [$stepId]));
				if ($rest === []) {
					continue;
				}

				$edge['from'] = $rest;
			}

			$targets = $this->endpoints(value: ($edge['to'] ?? null));
			if (in_array($stepId, $targets, true) === false) {
				$out[] = $edge;
				continue;
			}

			$retargeted = array_values(array_unique(array_merge(array_diff($targets, [$stepId]), $successors)));
			if ($retargeted === []) {
				$orphaned = true;
				continue;
			}

			foreach ($this->reshape(edge: $edge, targets: $retargeted) as $reshaped) {
				$out[] = $reshaped;
			}
		}//end foreach

		return ['edges' => $out, 'orphaned' => $orphaned];
	}//end bridge()

	/**
	 * Give an edge its new targets in the shape it was stored in.
	 *
	 * A list stays a list. A single id stays a single id, which means one edge
	 * per target when the removed step fanned out to several.
	 *
	 * @param array<string, mixed> $edge    The edge.
	 * @param array<int, string>   $targets Its new targets, at least one.
	 *
	 * @return array<int, array<string, mixed>> The edge or edges.
	 */
	private function reshape(array $edge, array $targets): array {
		if (is_array($edge['to'] ?? null) === true) {
			$edge['to'] = $targets;
			return [$edge];
		}

		if (count($targets) === 1) {
			$edge['to'] = $targets[0];
			return [$edge];
		}

		$out = [];
		foreach ($targets as $index => $target) {
			$copy = $edge;
			$copy['to'] = $target;
			$copy['id'] = ((string)($edge['id'] ?? 'edge') . '-' . ($index + 1));
			$out[] = $copy;
		}

		return $out;
	}//end reshape()

	/**
	 * The node ids an edge endpoint names, whichever shape it was stored in.
	 *
	 * @param mixed $value A node id, a list of node ids, or nothing.
	 *
	 * @return array<int, string> The ids.
	 */
	private function endpoints(mixed $value): array {
		if (is_string($value) === true && $value !== '') {
			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$ids = [];
		foreach ($value as $id) {
			if (is_string($id) === true && $id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end endpoints()
}//end class
