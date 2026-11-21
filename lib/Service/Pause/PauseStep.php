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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pause;

use DateTimeImmutable;

/**
 * The state of one pause step; it never leaves the service that built it.
 *
 * It also says what it reads as on the term and the timeline, and the legal basis it is recorded
 * under: both follow from the reason and the reminder number it carries.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
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

	/**
	 * What this reminder reads as, on the term and on the timeline.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function chasedSentence(): string {
		$name = trim((string)$this->reason['name']);
		if ($name === '') {
			$name = (string)$this->reason['key'];
		}

		return 'Reminder ' . $this->number . ' of ' . (int)$this->reason['chaseBudget'] . ' sent: ' . $name;
	}//end chasedSentence()

	/**
	 * What the escalation reads as.
	 *
	 * @param int $sent How many reminders went out.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function escalatedSentence(int $sent): string {
		$sentence = 'No reply after ' . $sent . ' reminders.';
		$target = trim((string)$this->reason['escalateTo']);
		if ($target !== '') {
			$sentence .= ' Escalated to ' . $target . '.';
		}

		return $sentence;
	}//end escalatedSentence()

	/**
	 * The legal basis this step's event is recorded under.
	 *
	 * @return string The basis, Awb 4:5 when the reason declares none.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function basis(): string {
		$basis = trim((string)$this->reason['legalBasis']);
		if ($basis === '') {
			return 'Awb 4:5';
		}

		return $basis;
	}//end basis()
}//end class
