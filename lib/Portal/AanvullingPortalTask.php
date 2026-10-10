<?php

/**
 * Dossiq Aanvulling Portal Task (an-aanvullingsverzoek-is-a-portal-task)
 *
 * Every aanvullingsverzoek also becomes a task in the resident's portal, so
 * it counts under "Taken" on their overview and not only under "Vragen aan
 * u" on the case (Ruben, 10 Oct, decision 169). Dossiq writes the task when it
 * asks and closes it when the request leaves `open`: answered, expired or
 * withdrawn.
 *
 * The task is an OpenRegister external task: performer type `external`, the
 * assignee the resident's party reference (`party:` + the case's portal
 * subject). That is the one shape portaliq's task block lists for a
 * resident (openregister flow-portal-task), so nothing new is needed on
 * either side.
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
use DateTimeZone;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises and closes the portal task of one aanvullingsverzoek.
 *
 * OpenRegister's task service is reached by name through the container: it
 * is an optional dependency, and a type hint would make dossiq fail to load
 * without it. Neither verb ever throws. The letter has gone out and the term
 * is suspended before a task is raised, so a task that cannot be written is
 * logged, never turned into a refused ask.
 *
 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
 */
class AanvullingPortalTask {

	/**
	 * OpenRegister's task service, by name.
	 */
	public const TASK_SERVICE = 'OCA\OpenRegister\Service\Task\TaskService';

	/**
	 * Who raised the task, on its metadata and on the close in its audit.
	 */
	public const SOURCE = 'dossiq.aanvullingsverzoek';

	/**
	 * OpenRegister's prefix for a party that acts through the portal.
	 */
	public const PARTY_PREFIX = 'party:';

	/**
	 * The time zone a hersteltermijn date is a day in.
	 */
	private const ZONE = 'Europe/Amsterdam';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container OpenRegister's task service is resolved from.
	 * @param LoggerInterface    $logger    Structured logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Raise the resident's task for a request that was just written.
	 *
	 * @param array<string, mixed> $request The stored request, with its id.
	 * @param string               $actor   The handler who asked.
	 *
	 * @return string|null The task's uuid, or null when none was raised.
	 *
	 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
	 */
	public function raise(array $request, string $actor): ?string {
		$subject = trim((string)($request['portalSubject'] ?? ''));
		$case = trim((string)($request['case'] ?? ''));
		$id = trim((string)($request['id'] ?? ($request['uuid'] ?? '')));
		if ($subject === '' || $case === '' || $id === '') {
			return null;
		}

		try {
			$task = $this->container->get(self::TASK_SERVICE)->import(
				data: $this->taskData(request: $request, subject: $subject, case: $case, id: $id),
				actor: $actor
			);
			$uuid = trim((string)$task->getUuid());
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the aanvullingsverzoek was sent, but its portal task could not be raised',
				['request' => $id, 'case' => $case, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end raise()

	/**
	 * Close the task of a request that is no longer open.
	 *
	 * @param array<string, mixed> $request The stored request after the change.
	 *
	 * @return bool Whether a task was closed.
	 *
	 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
	 */
	public function close(array $request): bool {
		$uuid = trim((string)($request['portalTask'] ?? ''));
		$state = trim((string)($request['state'] ?? ''));
		if ($uuid === '' || $state === '' || $state === 'open') {
			return false;
		}

		try {
			$this->container->get(self::TASK_SERVICE)->terminateAsMoot(
				uuid: $uuid,
				reason: sprintf('The aanvullingsverzoek is %s in dossiq.', $state),
				source: self::SOURCE
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the aanvullingsverzoek left open, but its portal task could not be closed',
				['task' => $uuid, 'state' => $state, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end close()

	/**
	 * The external task OpenRegister writes for a request.
	 *
	 * @param array<string, mixed> $request The stored request.
	 * @param string               $subject The case's portal subject.
	 * @param string               $case    The case uuid.
	 * @param string               $id      The request's id.
	 *
	 * @return array<string, mixed> The task fields.
	 */
	private function taskData(array $request, string $subject, string $case, string $id): array {
		$party = self::PARTY_PREFIX . $subject;

		return [
			'title' => 'Vul uw aanvraag aan',
			'description' => $this->description(request: $request),
			'state' => 'active',
			'performerType' => 'external',
			'priority' => 'normal',
			'assignee' => $party,
			'dueAt' => $this->dueAt(date: (string)($request['hersteltermijn'] ?? '')),
			'objectUuid' => $case,
			'metadata' => [
				'source' => self::SOURCE,
				'aanvullingsverzoek' => $id,
				'case' => $case,
				'partyReference' => $party,
			],
		];
	}//end taskData()

	/**
	 * What the resident reads on the task: what is missing and by when.
	 *
	 * @param array<string, mixed> $request The stored request.
	 *
	 * @return string The description.
	 */
	private function description(array $request): string {
		$lines = ['De gemeente heeft meer informatie nodig om uw aanvraag te behandelen. Dit ontbreekt nog:'];
		foreach ((array)($request['missingItems'] ?? []) as $row) {
			$item = '';
			if (is_array($row) === true) {
				$item = trim((string)($row['item'] ?? ''));
			} else if (is_string($row) === true) {
				$item = trim($row);
			}

			if ($item !== '') {
				$lines[] = '- ' . $item;
			}
		}

		$due = $this->readableDate(date: (string)($request['hersteltermijn'] ?? ''));
		if ($due !== '') {
			$lines[] = sprintf('Stuur het ons uiterlijk %s. Open de zaak om te zien hoe.', $due);
		} else {
			$lines[] = 'Open de zaak om te zien hoe u het aanlevert.';
		}

		return implode("\n", $lines);
	}//end description()

	/**
	 * The end of the hersteltermijn day, or null when the date is unreadable.
	 *
	 * @param string $date The hersteltermijn date.
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
	 * The hersteltermijn as a resident reads it (`24-10-2026`), or ''.
	 *
	 * @param string $date The hersteltermijn date.
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
	 * A date string as a day in the Netherlands, or null.
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

		try {
			return new DateTimeImmutable(substr($date, 0, 10), new DateTimeZone(self::ZONE));
		} catch (Throwable) {
			return null;
		}
	}//end day()
}//end class
