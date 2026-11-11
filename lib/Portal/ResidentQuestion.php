<?php

/**
 * Dossiq Resident Question
 *
 * One question to a resident as ResidentQuestionTask reads it: the caller's
 * fields, with the portal subject, case, id and title trimmed.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

/**
 * A question to a resident; it never leaves ResidentQuestionTask.
 *
 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
 */
final class ResidentQuestion {
	/**
	 * The case's portal subject.
	 *
	 * @var string
	 */
	public readonly string $subject;

	/**
	 * The case uuid.
	 *
	 * @var string
	 */
	public readonly string $case;

	/**
	 * The question's id.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * The task title.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $question The question: `id`, `case`, `portalSubject`, `title`,
	 *                                       `items`, `due` and `source`.
	 */
	public function __construct(public readonly array $question) {
		$this->subject = trim((string)($question['portalSubject'] ?? ''));
		$this->case = trim((string)($question['case'] ?? ''));
		$this->id = trim((string)($question['id'] ?? ''));
		$this->title = trim((string)($question['title'] ?? ''));
	}//end __construct()

	/**
	 * Whether there is a resident, a case, an id and a title to raise a task with.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
	 */
	public function isComplete(): bool {
		return ($this->subject !== '' && $this->case !== '' && $this->id !== '' && $this->title !== '');
	}//end isComplete()
}//end class
