<?php

/**
 * The approval of one imported mandate decision
 *
 * Where the decision and its mandates are stored, which decision is approved,
 * the day of the approval and the decision as it now stands: the state
 * MandaatImportService::approveImport() builds and its steps read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one approval; it never leaves the service that built it.
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */
final class MandateApproval {

	/**
	 * The approval context: objectService, register, bSchema and mSchema.
	 *
	 * @var array<string, mixed>
	 */
	public array $context = [];

	/**
	 * The id of the decision being approved.
	 *
	 * @var string
	 */
	public string $decisionId = '';

	/**
	 * The day of the approval (Y-m-d).
	 *
	 * @var string
	 */
	public string $now = '';

	/**
	 * The decision as it now stands.
	 *
	 * @var array<string, mixed>
	 */
	public array $decision = [];
}//end class
