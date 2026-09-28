<?php

/**
 * The flow document one migrated automatic action becomes.
 *
 * Pure: it takes the stored action, its provenance marker and the steps the
 * action translated into, and returns the document OpenRegister's FlowService
 * saves. Split out of {@see AutomaticActionFlowMigrator}, which decides
 * whether to write; this decides what.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use OCA\Dossiq\AppInfo\Application;

/**
 * Builds a migrated action's flow document.
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class AutomaticActionFlowDocument {

	/**
	 * The flow document one action becomes.
	 *
	 * A manual trigger, the action's steps in order, and an end: a flow
	 * OpenRegister will run needs an entry and an exit. `enabled` is true;
	 * the stored configuration said what it wanted and had never been honoured.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 * @param string $marker The provenance marker.
	 * @param array<int, array{type: string, config: array<string, mixed>}> $steps The action's steps.
	 *
	 * @return array<string, mixed> The flow document.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function build(array $action, string $marker, array $steps): array {
		$nodes = [['id' => 'trigger', 'type' => 'openregister.trigger-manual']];
		$edges = [];
		$previous = 'trigger';
		foreach ($steps as $index => $step) {
			$id = 'action';
			if ($index > 0) {
				$id = 'action--' . ($index + 1);
			}

			$nodes[] = ['id' => $id, 'type' => $step['type'], 'config' => $step['config']];
			$edges[] = ['id' => $previous . '-' . $id, 'from' => [$previous], 'to' => [$id]];
			$previous = $id;
		}

		$nodes[] = ['id' => 'end', 'type' => 'openregister.end'];
		$edges[] = ['id' => $previous . '-end', 'from' => [$previous], 'to' => ['end']];

		return [
			'name' => (string)($action['title'] ?? $action['slug']),
			'description' => $this->description(action: $action),
			'app' => Application::APP_ID,
			'enabled' => true,
			'trigger' => 'manual',
			'notes' => $marker,
			'nodes' => $nodes,
			'edges' => $edges,
		];
	}//end build()

	/**
	 * The flow's description, carrying the provenance a reader needs.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 *
	 * @return string The description.
	 */
	private function description(array $action): string {
		$own = (string)($action['description'] ?? '');
		$provenance = 'Migrated from the Dossiq automatic action "' . (string)($action['slug'] ?? '') . '".';
		if ($own === '') {
			return $provenance;
		}

		return $own . ' — ' . $provenance;
	}//end description()
}//end class
