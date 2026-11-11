<?php

/**
 * One pass of the automaticAction → flow migration.
 *
 * Built by `AutomaticActionFlowMigrator::migrateAll()` and never handed out:
 * the FlowService, the flows already projected (by marker) and whether to write.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

/**
 * What one migration pass writes with.
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */
final class ActionMigrationRun {
	/**
	 * Report what would happen and write nothing.
	 *
	 * @var boolean
	 */
	public bool $dryRun = false;

	/**
	 * Constructor.
	 *
	 * @param object                $flowService OpenRegister's FlowService.
	 * @param array<string, string> $existing    Marker → uuid of the flows already projected.
	 */
	public function __construct(
		public readonly object $flowService,
		public readonly array $existing,
	) {
	}//end __construct()
}//end class
