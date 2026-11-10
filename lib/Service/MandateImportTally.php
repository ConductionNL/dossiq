<?php

/**
 * The tally of one mandate CSV import
 *
 * The new decision, the prior decision's mandates it is compared with, and the
 * diff and counts so far: the state MandaatImportService::importFromCsv()
 * builds and its row steps fill in.
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
 * The progress of one import; it never leaves the service that built it.
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */
final class MandateImportTally {

	/**
	 * The id of the new (concept) decision.
	 *
	 * @var string
	 */
	public string $decisionId = '';

	/**
	 * The mandates of the prior decision with the same number.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $prior = [];

	/**
	 * Mandates new in this import.
	 *
	 * @var integer
	 */
	public int $newCount = 0;

	/**
	 * Mandates whose fields changed.
	 *
	 * @var integer
	 */
	public int $changedCount = 0;

	/**
	 * Mandates that stayed the same.
	 *
	 * @var integer
	 */
	public int $unchangedCount = 0;

	/**
	 * One entry per mandate, in row order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $diff = [];
}//end class
