<?php

/**
 * One pass of the termijn-definitie seed.
 *
 * Built by `DeadlineMonitoringSeedDataService::seed()` and never handed out:
 * the ObjectService, the register and schema it writes to, the definitions
 * already stored (by id) and the per-kind counts so far.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * What one seed pass writes with, and what it has counted.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
 */
final class DefinitionSeedRun {
	/**
	 * The TermijnDefinitie schema.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * Already-stored definitions, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $existing = [];

	/**
	 * Rows written, skipped, backfilled and failed so far.
	 *
	 * @var array<string, int>
	 */
	public array $counts = ['definities' => 0, 'skipped' => 0, 'backfilled' => 0, 'failed' => 0];

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register      Register id.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
	) {
	}//end __construct()
}//end class
