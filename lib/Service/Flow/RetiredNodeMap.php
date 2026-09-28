<?php

/**
 * The flow node types dossiq no longer offers, and what each one becomes.
 *
 * A node type that leaves the catalogue does not leave the stored flows. A flow
 * saved last year still names it, and OpenRegister's engine fails the step when
 * it reaches a type nobody registers. This table is the one place that says,
 * per retired type, what an upgrade does with such a step.
 *
 * A ROW WITH A REPLACEMENT renames the step's type and keeps its config. A ROW
 * WITHOUT ONE removes the step and bridges its edges, and the repair step logs
 * a warning naming the flow and the step. Nothing is dropped silently.
 *
 * Add a row here when a node is retired. The repair step
 * {@see \OCA\Dossiq\Repair\RewriteRetiredFlowNodes} reads nothing else.
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
 * Lookup over the retired-node table.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
class RetiredNodeMap {

	/**
	 * The prefix of a configured-action node; the rest of the id is the action type.
	 *
	 * @var string
	 */
	public const ACTION_NODE_PREFIX = 'dossiq.action.';

	/**
	 * The retired node types.
	 *
	 * `replacement` is the node type the step becomes, or null when nothing
	 * replaces it and the step is removed. `reason` is written into the log.
	 *
	 * @var array<string, array{replacement: string|null, reason: string}>
	 */
	private const ROWS = [
		'dossiq.action.scheduleReminder' => [
			'replacement' => null,
			'reason' => 'the reminder job it queued never existed, so the step never sent a reminder',
		],
	];

	/**
	 * The rows this map answers from.
	 *
	 * @var array<string, array{replacement: string|null, reason: string}>
	 */
	private readonly array $rows;

	/**
	 * Constructor.
	 *
	 * @param array<string, array{replacement: string|null, reason: string}>|null $rows The table,
	 *        or null for the shipped one. Tests pass their own to cover a replacement row.
	 */
	public function __construct(?array $rows = null) {
		$this->rows = ($rows ?? self::ROWS);
	}//end __construct()

	/**
	 * The row for a node type, or null when the type is not retired.
	 *
	 * @param string $type The node type.
	 *
	 * @return array{replacement: string|null, reason: string}|null The row.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function rowFor(string $type): ?array {
		return ($this->rows[$type] ?? null);
	}//end rowFor()

	/**
	 * The retired configured-action types, as stored on an `automaticAction`.
	 *
	 * @return array<string, string> Action type => reason.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function retiredActionTypes(): array {
		$types = [];
		foreach ($this->rows as $nodeType => $row) {
			if (str_starts_with($nodeType, self::ACTION_NODE_PREFIX) === false) {
				continue;
			}

			$types[substr($nodeType, strlen(self::ACTION_NODE_PREFIX))] = $row['reason'];
		}

		return $types;
	}//end retiredActionTypes()
}//end class
