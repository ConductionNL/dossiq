<?php

/**
 * One pass of the template-to-flow migration: the flow service, the flows already projected, the status names and whether it only reports.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/workflow-definitions-to-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

/**
 * One pass of the template-to-flow migration: the flow service, the flows already projected, the status names and whether it only reports.
 *
 * @spec openspec/specs/workflow-definitions-to-flow/spec.md
 */
final class FlowMigrationPass {

	/**
	 * Constructor.
	 *
	 * @param object                $flowService OpenRegister's FlowService.
	 * @param array<string, string> $existing    Marker to flow uuid.
	 * @param array<string, string> $statusNames statusType uuid => name.
	 * @param boolean               $dryRun      Report only.
	 *
	 * @spec openspec/specs/workflow-definitions-to-flow/spec.md
	 */
	public function __construct(
		public readonly object $flowService,
		public readonly array $existing,
		public readonly array $statusNames,
		public readonly bool $dryRun,
	) {
	}//end __construct()
}//end class
