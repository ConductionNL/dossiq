<?php

/**
 * Dossiq Case Timer Sweep
 *
 * What one case timer arming step sweeps: its name, its filters, its work per case,
 * and where the sweeper found the cases.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

use Closure;

/**
 * One sweep over the cases a timer arming step syncs.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
final class CaseTimerSweep {
	/**
	 * The migration's name in its log and output lines, e.g. "DSO term timer".
	 *
	 * @var string
	 */
	public string $migration = '';

	/**
	 * The case search filters.
	 *
	 * @var array<string, mixed>
	 */
	public array $filters = [];

	/**
	 * The work done for each case, as the system.
	 *
	 * @var Closure(array<string, mixed>): void
	 */
	public Closure $each;

	/**
	 * The object service the sweeper found.
	 *
	 * @var object|null
	 */
	public ?object $objectService = null;

	/**
	 * The register the sweeper found.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The case schema the sweeper found.
	 *
	 * @var string
	 */
	public string $schema = '';
}//end class
