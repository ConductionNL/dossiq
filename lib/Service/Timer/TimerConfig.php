<?php

/**
 * The configuration a dossiq timer arms on the engine.
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
 * A due timer with no legal effect, counted in calendar days from its anchor.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
final class TimerConfig {

	/**
	 * Build the configuration.
	 *
	 * @param string                           $subjectUuid The timer's subject uuid.
	 * @param string                           $title       The timer's title.
	 * @param TimerWindow                      $window      Its anchor and length.
	 * @param array<int, EscalationRung>       $rungs       Its escalation rungs, in order.
	 * @param string                           $anchorEvent The event the anchor marks.
	 * @param array<string, string>            $metadata    What the fired listener recognises it by.
	 */
	public function __construct(
		public readonly string $subjectUuid,
		public readonly string $title,
		public readonly TimerWindow $window,
		public readonly array $rungs,
		public readonly string $anchorEvent,
		public readonly array $metadata,
	) {
	}//end __construct()

	/**
	 * The configuration array the engine's arm() takes.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public function toArray(): array {
		return [
			'subjectType'     => 'object',
			'subjectUuid'     => $this->subjectUuid,
			'appId'           => 'dossiq',
			'title'           => $this->title,
			'purpose'         => 'due',
			'legalEffect'     => 'none',
			'sla'             => ['value' => $this->window->slaDays, 'unit' => 'calendarDays'],
			'escalationRules' => array_map(static fn (EscalationRung $rung): array => $rung->toArray(), $this->rungs),
			'anchorEvent'     => $this->anchorEvent,
			'anchorEventAt'   => $this->window->anchor,
			'metadata'        => $this->metadata,
		];
	}//end toArray()
}//end class
