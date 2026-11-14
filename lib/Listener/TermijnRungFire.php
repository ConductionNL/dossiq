<?php

/**
 * One rung of a term timer that fired, for a known term instance.
 *
 * Built by `TermijnTimerFiredListener` once the fire names a known TermijnInstance, and handed through
 * the steps that route and handle the rung: the rung's key, the instance row and its id.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

/**
 * One rung of a term timer that fired, for a known term instance.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
final class TermijnRungFire {

	/**
	 * Constructor.
	 *
	 * @param string               $rungKey    The rung's stable key, `trigger:offset:unit`.
	 * @param array<string, mixed> $instance   The TermijnInstance row.
	 * @param string               $instanceId Its id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $rungKey,
		public readonly array $instance,
		public readonly string $instanceId,
	) {
	}//end __construct()
}//end class
