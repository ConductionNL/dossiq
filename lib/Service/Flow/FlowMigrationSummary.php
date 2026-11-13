<?php

/**
 * The summary a migration onto flows starts from
 *
 * The automatic-action and workflow-template migrations both report the same
 * counts: how many items they saw, created, updated, skipped and failed, the
 * per-item rows, and a note. A run that cannot start answers this summary
 * empty with the note saying why; a run that starts fills it in.
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
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * Builds the empty summary of a migration onto flows.
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */
final class FlowMigrationSummary {
	/**
	 * A summary with every count at 0, no rows, and the given note.
	 *
	 * @param string $note Why nothing happened, or '' for a run about to start.
	 *
	 * @return array<string, mixed> The summary: total, created, updated, skipped, failed, rows, note.
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	public static function empty(string $note): array {
		return [
			'total' => 0,
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed' => 0,
			'rows' => [],
			'note' => $note,
		];
	}//end empty()
}//end class
