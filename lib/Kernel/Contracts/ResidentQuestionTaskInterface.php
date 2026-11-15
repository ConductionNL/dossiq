<?php

/**
 * Contract of ResidentQuestionTask
 *
 * The public surface of ResidentQuestionTask that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of ResidentQuestionTask.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ResidentQuestionTaskInterface {

	/**
	 * Raise the resident's task for a question.
	 *
	 * @param array<string, mixed> $question The question: `id`, `case`, `portalSubject`, `title`,
	 *                                       `items` (list of strings), `due` (a date) and `source`.
	 * @param string               $actor    Who asked.
	 *
	 * @return string|null The task's uuid, or null when none was raised.
	 *
	 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
	 */
	public function raise(array $question, string $actor): ?string;

	/**
	 * Close the task of a question that is settled.
	 *
	 * @param string $taskUuid The task.
	 * @param string $reason   Why, kept in the task's audit.
	 * @param string $source   Who closes it.
	 *
	 * @return bool Whether a task was closed.
	 *
	 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
	 */
	public function close(string $taskUuid, string $reason, string $source): bool;
}//end interface
