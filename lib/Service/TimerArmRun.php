<?php

/**
 * Dossiq timer arm run.
 *
 * The term a beslistermijn timer is armed for: its TermijnInstance row, the
 * definition it was resolved from, the instance id and the term's start.
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * One beslistermijn being mapped onto an engine timer.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
final class TimerArmRun {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $instance   The TermijnInstance row.
	 * @param array<string, mixed> $definitie  The resolved TermijnDefinitie (may be empty).
	 * @param string               $instanceId The instance id.
	 * @param DateTimeImmutable    $start      The term's start.
	 */
	public function __construct(
		public readonly array $instance,
		public readonly array $definitie,
		public readonly string $instanceId,
		public readonly DateTimeImmutable $start,
	) {
	}//end __construct()
}//end class
