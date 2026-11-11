<?php

/**
 * One escalation rung of a dossiq engine timer.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Dossiq\Service\Timer;

/**
 * A rung that tells, with no roles to notify or escalate to and no incident.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
final class EscalationRung {

	/**
	 * Build the rung.
	 *
	 * @param string $trigger  `slaBreached` or `preBreach`.
	 * @param int    $offset   How far from the breach.
	 * @param string $unit     The offset's unit.
	 * @param string $message  The message key the fired listener reads.
	 * @param string $priority The rung's priority.
	 */
	public function __construct(
		public readonly string $trigger,
		public readonly int $offset,
		public readonly string $unit,
		public readonly string $message,
		public readonly string $priority = 'normal',
	) {
	}//end __construct()

	/**
	 * The rung as the engine's escalation rule array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public function toArray(): array {
		return [
			'trigger'        => $this->trigger,
			'offset'         => $this->offset,
			'offsetUnit'     => $this->unit,
			'notifyRole'     => [],
			'escalateToRole' => [],
			'priority'       => $this->priority,
			'message'        => $this->message,
			'openIncident'   => false,
		];
	}//end toArray()
}//end class
