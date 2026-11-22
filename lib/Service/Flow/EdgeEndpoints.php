<?php

/**
 * The endpoints of a flow edge, in the shape they were stored in.
 *
 * Edge endpoints come in two shapes in stored flows: a single node id
 * (`"to": "end"`) and a list (`"to": ["end"]`). This reads the ids an
 * endpoint names, replaces one id in it, and gives an edge new targets,
 * each time keeping the shape the edge had.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use OCA\Dossiq\Service\External\RegisterValues;

/**
 * Reads and writes a flow edge's endpoints in their stored shape.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class EdgeEndpoints {

	/**
	 * The node ids an edge endpoint names, whichever shape it was stored in.
	 *
	 * @param mixed $value A node id, a list of node ids, or nothing.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function idsOf(mixed $value): array {
		// The same reading as any stored string list: a non-blank id is one, a list keeps its non-blank strings.
		return (new RegisterValues())->toStringArray(value: $value);
	}//end idsOf()

	/**
	 * Replace one node id in an edge endpoint, keeping the endpoint's shape.
	 *
	 * @param mixed                          $value   The endpoint: an id or a list of ids.
	 * @param array{from: string, to: string} $replace The id to replace, and its replacement.
	 *
	 * @return mixed The endpoint.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function swapped(mixed $value, array $replace): mixed {
		if (is_array($value) === false) {
			return $replace['to'];
		}

		$swapped = [];
		foreach ($value as $id) {
			if ($id === $replace['from']) {
				$id = $replace['to'];
			}

			$swapped[] = $id;
		}

		return $swapped;
	}//end swapped()

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
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function withTargets(array $edge, array $targets): array {
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
	}//end withTargets()
}//end class
