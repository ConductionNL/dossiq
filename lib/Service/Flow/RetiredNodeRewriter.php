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
 * A TRANSLATED STEP may become several. The first keeps the step's id, so
 * every edge into it still arrives; each further step gets `<id>--<n>` and
 * an edge from the one before it, and the step's outgoing edges leave from
 * the last one. A step the translator refuses is LEFT IN PLACE, untouched,
 * and its change row carries the reason.
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

use OCA\Dossiq\Kernel\Contracts\RetiredNodeRewriterInterface;

/**
 * Applies {@see RetiredNodeMap} to a flow's nodes and edges.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
class RetiredNodeRewriter implements RetiredNodeRewriterInterface {

	/**
	 * Constructor.
	 *
	 * @param RetiredNodeMap             $map        The retired-node table.
	 * @param RetiredNodeTranslator|null $translator Translates a row whose replacement reads a
	 *                                               different config. Without it such a step is
	 *                                               left in place and reported, never renamed
	 *                                               with a config its replacement cannot read.
	 */
	public function __construct(
		private readonly RetiredNodeMap $map,
		private readonly ?RetiredNodeTranslator $translator = null,
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
	 * } The rewritten graph and one row per retired step. `outcome` is `replaced`,
	 *   `removed` or `unmappable`; an unmappable step is still in the graph. No rows
	 *   means nothing was retired.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function rewrite(array $nodes, array $edges): array {
		$run = new NodeRewrite();
		$run->edges = $edges;
		foreach ($nodes as $node) {
			$this->rewriteNode(run: $run, node: $node);
		}

		return ['nodes' => $run->kept, 'edges' => $run->edges, 'changes' => $run->changes];
	}//end rewrite()

	/**
	 * Keep a node of a live type; translate, rename or remove one of a retired type.
	 *
	 * @param NodeRewrite $run  The rewrite so far.
	 * @param mixed       $node One stored node.
	 *
	 * @return void
	 */
	private function rewriteNode(NodeRewrite $run, mixed $node): void {
		$type = '';
		if (is_array($node) === true) {
			$type = (string)($node['type'] ?? '');
		}

		$row = $this->map->rowFor(type: $type);
		if ($row === null) {
			$run->kept[] = $node;
			return;
		}

		$step = new RetiredStep();
		$step->node = $node;
		$step->row = $row;
		$step->type = $type;
		$step->stepId = (string)($node['id'] ?? '');
		$this->retire(run: $run, step: $step);
	}//end rewriteNode()

	/**
	 * Translate, rename or remove one retired step, as its map row says.
	 *
	 * @param NodeRewrite $run  The rewrite so far.
	 * @param RetiredStep $step The retired step.
	 *
	 * @return void
	 */
	private function retire(NodeRewrite $run, RetiredStep $step): void {
		if (isset($step->row['translation']) === true) {
			$translated = $this->translateStep(step: $step, edges: $run->edges);
			array_push($run->kept, ...$translated['nodes']);
			$run->edges = $translated['edges'];
			$run->changes[] = $translated['change'];
			return;
		}

		if ($step->row['replacement'] !== null) {
			$node = $step->node;
			$node['type'] = $step->row['replacement'];
			$run->kept[] = $node;
			$run->changes[] = $this->stepChange(
				step: $step,
				outcome: ['outcome' => 'replaced', 'replacement' => $step->row['replacement'], 'orphaned' => false]
			);
			return;
		}

		$bridged = $this->bridge(edges: $run->edges, stepId: $step->stepId);
		$run->edges = $bridged['edges'];
		$run->changes[] = $this->stepChange(step: $step, outcome: ['outcome' => 'removed', 'replacement' => null, 'orphaned' => $bridged['orphaned']]);
	}//end retire()

	/**
	 * The change recorded for one retired step.
	 *
	 * @param RetiredStep                                                    $step    The retired step.
	 * @param array{outcome: string, replacement: ?string, orphaned: bool} $outcome What became of it.
	 *
	 * @return array<string, mixed>
	 */
	private function stepChange(RetiredStep $step, array $outcome): array {
		return [
			'step' => $step->stepId,
			'type' => $step->type,
			'outcome' => $outcome['outcome'],
			'replacement' => $outcome['replacement'],
			'reason' => $step->row['reason'],
			'orphaned' => $outcome['orphaned'],
		];
	}//end stepChange()

	/**
	 * Translate a retired step into the steps of its translation, or leave it in place
	 * when there is no translator or the step cannot be translated.
	 *
	 * @param RetiredStep       $step  The retired step.
	 * @param array<int, mixed> $edges The edges as they now stand.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, mixed>, change: array<string, mixed>}
	 */
	private function translateStep(RetiredStep $step, array $edges): array {
		$change = $this->stepChange(step: $step, outcome: ['outcome' => 'unmappable', 'replacement' => null, 'orphaned' => false]);

		if ($this->translator === null) {
			$change['reason'] .= '; the step was left in place because no translator was available';
			return ['nodes' => [$step->node], 'edges' => $edges, 'change' => $change];
		}

		try {
			$steps = $this->translator->translate(
				translation: (string)$step->row['translation'],
				config: (array)($step->node['config'] ?? [])
			);
		} catch (UnmappableStep $e) {
			$change['reason'] .= '; the step was left in place because ' . $e->getMessage();
			return ['nodes' => [$step->node], 'edges' => $edges, 'change' => $change];
		}

		$nodes = $this->replacementNodes(step: $step, steps: $steps);
		$change['outcome'] = 'replaced';
		$change['replacement'] = implode(' + ', array_column($steps, 'type'));
		$ids = array_map(static fn (array $node): string => (string)$node['id'], $nodes);

		return ['nodes' => $nodes, 'edges' => $this->chain(edges: $edges, ids: $ids), 'change' => $change];
	}//end translateStep()

	/**
	 * The nodes a translated step becomes: the first keeps the id, the rest are numbered after it.
	 *
	 * @param RetiredStep                      $step  The retired step.
	 * @param array<int, array<string, mixed>> $steps The translation's steps.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function replacementNodes(RetiredStep $step, array $steps): array {
		$nodes = [];
		foreach ($steps as $index => $translated) {
			$replacement = $step->node;
			$replacement['type'] = $translated['type'];
			$replacement['config'] = $translated['config'];
			if ($index > 0) {
				$replacement['id'] = $step->stepId . '--' . ($index + 1);
				unset($replacement['_note']);
			}

			$nodes[] = $replacement;
		}

		return $nodes;
	}//end replacementNodes()

	/**
	 * Wire a step that became several: outgoing edges leave from the last, and each links to the next.
	 *
	 * @param array<int, mixed>  $edges The current edges.
	 * @param array<int, string> $ids   The ids of the replacing steps, the original's first.
	 *
	 * @return array<int, mixed> The edges.
	 */
	private function chain(array $edges, array $ids): array {
		if (count($ids) < 2) {
			return $edges;
		}

		$first = $ids[0];
		$last = $ids[(count($ids) - 1)];
		$out = [];
		foreach ($edges as $edge) {
			if (is_array($edge) === true && in_array($first, $this->endpoints(value: ($edge['from'] ?? null)), true) === true) {
				$edge['from'] = $this->swap(value: $edge['from'], from: $first, to: $last);
			}

			$out[] = $edge;
		}

		$total = count($ids);
		for ($index = 1; $index < $total; $index++) {
			$out[] = [
				'id' => $ids[($index - 1)] . '-' . $ids[$index],
				'from' => $ids[($index - 1)],
				'to' => $ids[$index],
			];
		}

		return $out;
	}//end chain()

	/**
	 * Replace one node id in an edge endpoint, keeping the endpoint's shape.
	 *
	 * @param mixed  $value The endpoint: an id or a list of ids.
	 * @param string $from  The id to replace.
	 * @param string $to    Its replacement.
	 *
	 * @return mixed The endpoint.
	 */
	private function swap(mixed $value, string $from, string $to): mixed {
		if (is_array($value) === false) {
			return $to;
		}

		$swapped = [];
		foreach ($value as $id) {
			if ($id === $from) {
				$id = $to;
			}

			$swapped[] = $id;
		}

		return $swapped;
	}//end swap()

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
		$successors = $this->successorsOf(edges: $edges, stepId: $stepId);
		$orphaned = false;
		$out = [];

		foreach ($edges as $edge) {
			if (is_array($edge) === false) {
				$out[] = $edge;
				continue;
			}

			$edge = $this->withoutSource(edge: $edge, stepId: $stepId);
			if ($edge === null) {
				continue;
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

			array_push($out, ...$this->reshape(edge: $edge, targets: $retargeted));
		}//end foreach

		return ['edges' => $out, 'orphaned' => $orphaned];
	}//end bridge()

	/**
	 * The edge without the removed step among its sources, or null when it left from that step alone.
	 *
	 * An edge out of the removed step goes with it, unless it also leaves
	 * from another step, which keeps it.
	 *
	 * @param array<string, mixed> $edge   One edge.
	 * @param string               $stepId The removed step.
	 *
	 * @return array<string, mixed>|null
	 */
	private function withoutSource(array $edge, string $stepId): ?array {
		$from = $this->endpoints(value: ($edge['from'] ?? null));
		if (in_array($stepId, $from, true) === false) {
			return $edge;
		}

		$rest = array_values(array_diff($from, [$stepId]));
		if ($rest === []) {
			return null;
		}

		$edge['from'] = $rest;

		return $edge;
	}//end withoutSource()

	/**
	 * The steps a step leads to, itself excluded.
	 *
	 * @param array<int, mixed> $edges  The current edges.
	 * @param string            $stepId The step.
	 *
	 * @return array<int, string> The successor ids, each once.
	 */
	private function successorsOf(array $edges, string $stepId): array {
		$successors = [];
		foreach ($edges as $edge) {
			if (is_array($edge) === false || in_array($stepId, $this->endpoints(value: ($edge['from'] ?? null)), true) === false) {
				continue;
			}

			foreach ($this->endpoints(value: ($edge['to'] ?? null)) as $target) {
				$successors[$target] = true;
			}
		}

		unset($successors[$stepId]);

		return array_map('strval', array_keys($successors));
	}//end successorsOf()

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
