<?php

/**
 * Dossiq Resident Question Task
 *
 * A question the organisation puts to a resident becomes a task in that
 * resident's portal, so it counts under "Taken" on their overview, and the
 * task is closed when the question is settled. Generic capability (decision
 * 182: procedures are configuration, code is generic): the caller names the
 * question, its title, what it asks for, when it is due and who raised it.
 * The aanvullingsverzoek is one caller (decision 169).
 *
 * The task is an OpenRegister external task: performer type `external`, the
 * assignee the resident's party reference (`party:` + the case's portal
 * subject). That is the one shape portaliq's task block lists for a resident
 * (openregister flow-portal-task), so nothing new is needed on either side.
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

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises and closes the portal task of one question to a resident.
 *
 * OpenRegister's task service is reached by name through the container: it
 * is an optional dependency, and a type hint would make dossiq fail to load
 * without it. Neither verb ever throws: the question already stands when a
 * task is raised, so a task that cannot be written is logged, never turned
 * into a refusal of the question.
 *
 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
 */
class ResidentQuestionTask {

	/**
	 * OpenRegister's task service, by name.
	 */
	public const TASK_SERVICE = 'OCA\OpenRegister\Service\Task\TaskService';

	/**
	 * OpenRegister's prefix for a party that acts through the portal.
	 */
	public const PARTY_PREFIX = 'party:';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container OpenRegister's task service is resolved from.
	 * @param CaseDateNormaliser $dates     Reads a due date as a day in the administrator's time zone.
	 * @param LoggerInterface    $logger    Structured logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function raise(array $question, string $actor): ?string {
		$subject = trim((string)($question['portalSubject'] ?? ''));
		$case = trim((string)($question['case'] ?? ''));
		$id = trim((string)($question['id'] ?? ''));
		$title = trim((string)($question['title'] ?? ''));
		if ($subject === '' || $case === '' || $id === '' || $title === '') {
			return null;
		}

		try {
			$task = $this->container->get(self::TASK_SERVICE)->import(
				data: $this->taskData(question: $question, subject: $subject, case: $case, id: $id, title: $title),
				actor: $actor
			);
			$uuid = trim((string)$task->getUuid());
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the question to the resident stands, but its portal task could not be raised',
				['question' => $id, 'case' => $case, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end raise()

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
	public function close(string $taskUuid, string $reason, string $source): bool {
		$taskUuid = trim($taskUuid);
		if ($taskUuid === '') {
			return false;
		}

		try {
			$this->container->get(self::TASK_SERVICE)->terminateAsMoot(uuid: $taskUuid, reason: $reason, source: $source);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the question is settled, but its portal task could not be closed',
				['task' => $taskUuid, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end close()

	/**
	 * The external task OpenRegister writes for a question.
	 *
	 * @param array<string, mixed> $question The question.
	 * @param string               $subject  The case's portal subject.
	 * @param string               $case     The case uuid.
	 * @param string               $id       The question's id.
	 * @param string               $title    The task title.
	 *
	 * @return array<string, mixed> The task fields.
	 */
	private function taskData(array $question, string $subject, string $case, string $id, string $title): array {
		$party = self::PARTY_PREFIX . $subject;

		return [
			'title' => $title,
			'description' => $this->description(question: $question),
			'state' => 'active',
			'performerType' => 'external',
			'priority' => 'normal',
			'assignee' => $party,
			'dueAt' => $this->dueAt(date: (string)($question['due'] ?? '')),
			'objectUuid' => $case,
			'metadata' => [
				'source' => (string)($question['source'] ?? ''),
				'question' => $id,
				'case' => $case,
				'partyReference' => $party,
			],
		];
	}//end taskData()

	/**
	 * What the resident reads on the task: what is asked and by when.
	 *
	 * @param array<string, mixed> $question The question.
	 *
	 * @return string The description.
	 */
	private function description(array $question): string {
		$lines = ['Wij hebben nog iets van u nodig om uw zaak te behandelen:'];
		foreach ((array)($question['items'] ?? []) as $item) {
			$item = trim((string)$item);
			if ($item !== '') {
				$lines[] = '- ' . $item;
			}
		}

		$due = $this->readableDate(date: (string)($question['due'] ?? ''));
		$lines[] = 'Open de zaak om te zien hoe u het aanlevert.';
		if ($due !== '') {
			$lines[count($lines) - 1] = sprintf('Stuur het ons uiterlijk %s. Open de zaak om te zien hoe.', $due);
		}

		return implode("\n", $lines);
	}//end description()

	/**
	 * The end of the due day, or null when the date is unreadable.
	 *
	 * @param string $date The due date.
	 *
	 * @return string|null An ISO 8601 instant.
	 */
	private function dueAt(string $date): ?string {
		$day = $this->day(date: $date);
		if ($day === null) {
			return null;
		}

		return $day->setTime(23, 59, 59)->format('c');
	}//end dueAt()

	/**
	 * The due date as a resident reads it (`24-10-2026`), or ''.
	 *
	 * @param string $date The due date.
	 *
	 * @return string The date.
	 */
	private function readableDate(string $date): string {
		$day = $this->day(date: $date);
		if ($day === null) {
			return '';
		}

		return $day->format('d-m-Y');
	}//end readableDate()

	/**
	 * A date string as a day in the administrator's time zone, or null.
	 *
	 * @param string $date The date (`Y-m-d`, or an ISO instant).
	 *
	 * @return DateTimeImmutable|null
	 */
	private function day(string $date): ?DateTimeImmutable {
		$date = trim($date);
		if ($date === '') {
			return null;
		}

		return $this->dates->tryParse(value: substr($date, 0, 10));
	}//end day()
}//end class
