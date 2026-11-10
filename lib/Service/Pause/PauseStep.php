<?php

/**
 * One step on a suspended term: a reminder or the escalation after the last one
 *
 * The fresh paused row, the reason in force, the moment, and what the step
 * records on the term and the timeline: the state PauseChaseService builds
 * for one reminder or escalation, so its steps take one argument.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Pause
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
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pause;

use DateTimeImmutable;

/**
 * The state of one pause step; it never leaves the service that built it.
 *
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */
final class PauseStep {

	/**
	 * The fresh, still-paused term instance row.
	 *
	 * @var array<string, mixed>
	 */
	public array $instance = [];

	/**
	 * The normalised reason in force.
	 *
	 * @var array<string, mixed>
	 */
	public array $reason = [];

	/**
	 * When this is happening.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $moment;

	/**
	 * The term instance id.
	 *
	 * @var string
	 */
	public string $instanceId = '';

	/**
	 * The case UUID.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * Where a reminder goes, empty when none is recorded.
	 *
	 * @var string
	 */
	public string $recipient = '';

	/**
	 * Which reminder this is.
	 *
	 * @var integer
	 */
	public int $number = 0;

	/**
	 * The event type the step records.
	 *
	 * @var string
	 */
	public string $event = '';

	/**
	 * What the step reads as, on the term and on the timeline.
	 *
	 * @var string
	 */
	public string $sentence = '';
}//end class
