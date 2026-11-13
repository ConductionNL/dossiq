<?php

/**
 * One stored flow and what the retired node rewrite made of it: the new
 * nodes and edges and the change rows to report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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

namespace OCA\Dossiq\Repair;

/**
 * A stored flow with its rewritten graph and the changes that made it.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
final class FlowRewrite {

	/**
	 * Constructor.
	 *
	 * @param object                           $flow    The stored flow.
	 * @param array<int, mixed>                $nodes   The rewritten nodes.
	 * @param array<int, mixed>                $edges   The rewritten edges.
	 * @param array<int, array<string, mixed>> $changes The change rows, one per step touched.
	 */
	public function __construct(
		public readonly object $flow,
		public readonly array $nodes,
		public readonly array $edges,
		public readonly array $changes,
	) {
	}//end __construct()

	/**
	 * The flow's name and uuid, as the reports name it.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function label(): string {
		return '"' . (string)$this->flow->getName() . '" (' . (string)$this->flow->getUuid() . ')';
	}//end label()

	/**
	 * Whether every change is a step that could not be carried over, so the graph is what it was.
	 *
	 * @return bool True when there is nothing to save.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function savesNothing(): bool {
		$unmappable = array_filter($this->changes, static fn (array $change): bool => $change['outcome'] === 'unmappable');

		return count($unmappable) === count($this->changes);
	}//end savesNothing()
}//end class
