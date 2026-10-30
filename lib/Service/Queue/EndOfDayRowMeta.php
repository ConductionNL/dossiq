<?php

/**
 * Dossiq end of day: the meta line of a row
 *
 * Names the case a row is about: a case's number and status, the number and
 * title of the case a task is on, and on a recorded row the time of the note.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md#requirement-each-row-names-its-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads what an end-of-day row's meta line names and writes the line.
 *
 * A case or status that no longer exists is left out of the line: the row
 * itself stays. A store that fails to answer is an error, not an empty line.
 *
 * @spec openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md#requirement-each-row-names-its-case
 */
class EndOfDayRowMeta {
	use SearchesObjects;

	private const SEPARATOR = ' · ';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The app's OpenRegister configuration.
	 * @param EngineTaskGateway $tasks  The task engine, which knows a task's case.
	 * @param IL10N           $l10n     The labels on screen.
	 * @param LoggerInterface $logger   The log.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly EngineTaskGateway $tasks,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What the meta line of a queue item names.
	 *
	 * @param array<string, mixed> $item The queue item (`subjectType`, `subjectId`).
	 *
	 * @return array{task: bool, number: string, status: string, caseTitle: string}
	 *
	 * @spec openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md#requirement-each-row-names-its-case
	 */
	public function factsOf(array $item): array {
		$task = (($item['subjectType'] ?? '') === 'task');
		$caseId = (string)($item['subjectId'] ?? '');
		if ($task === true) {
			$caseId = $this->caseOfTask(taskId: $caseId);
		}

		$case = $this->objectData(id: $caseId, schema: $this->config(key: 'case_schema', default: 'case'));
		$status = '';
		if ($task === false && is_string($case['status'] ?? null) === true && $case['status'] !== '') {
			$statusType = $this->objectData(id: $case['status'], schema: $this->config(key: 'status_type_schema', default: 'statusType'));
			$status = trim((string)($statusType['name'] ?? ''));
		}

		return [
			'task' => $task,
			'number' => trim((string)($case['identifier'] ?? '')),
			'status' => $status,
			'caseTitle' => trim((string)($case['title'] ?? '')),
		];
	}//end factsOf()

	/**
	 * The line under an open row: "2026-4836 · In behandeling", or for a task
	 * "bij 2026-4839 Kapvergunning essen Parkweg".
	 *
	 * @param array{task: bool, number: string, status: string, caseTitle: string} $facts The row's facts.
	 *
	 * @return string The line, '' when nothing could be read.
	 *
	 * @spec openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md#requirement-each-row-names-its-case
	 */
	public function openLine(array $facts): string {
		if ($facts['number'] === '') {
			return '';
		}

		if ($facts['task'] === true) {
			return trim($this->l10n->t('at %1$s %2$s', [$facts['number'], $facts['caseTitle']]));
		}

		return implode(self::SEPARATOR, array_filter([$facts['number'], $facts['status']], static fn (string $part): bool => $part !== ''));
	}//end openLine()

	/**
	 * The line under a recorded row: "2026-0110 · 15:40", or for a task
	 * "bij 2026-4836 · 11:15".
	 *
	 * @param array{task: bool, number: string, status: string, caseTitle: string} $facts The row's facts.
	 * @param string                                                                $time  The time of the note.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md#requirement-each-row-names-its-case
	 */
	public function recordedLine(array $facts, string $time): string {
		$case = $facts['number'];
		if ($case !== '' && $facts['task'] === true) {
			$case = $this->l10n->t('at %s', [$case]);
		}

		return implode(self::SEPARATOR, array_filter([$case, $time], static fn (string $part): bool => $part !== ''));
	}//end recordedLine()

	/**
	 * The uuid of the case a task is on, '' when the engine does not say.
	 *
	 * @param string $taskId The task.
	 *
	 * @return string
	 */
	private function caseOfTask(string $taskId): string {
		try {
			$task = $this->tasks->find(taskId: $taskId);
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: the case of end-of-day task ' . $taskId . ' could not be read: ' . $e->getMessage());
			return '';
		}

		return trim((string)($task['objectUuid'] ?? ''));
	}//end caseOfTask()

	/**
	 * A register object's data, [] when it does not exist.
	 *
	 * @param string $id     The uuid.
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed>
	 */
	private function objectData(string $id, string $schema): array {
		$objectService = $this->settings->getObjectService();
		if ($id === '' || $objectService === null) {
			return [];
		}

		return ($this->findObjectAsArray(
			objectService: $objectService,
			register: $this->config(key: 'register', default: 'dossiq'),
			schema: $schema,
			id: $id
		) ?? []);
	}//end objectData()

	/**
	 * A configured schema or register name.
	 *
	 * @param string $key     The config key.
	 * @param string $default The name when none is configured.
	 *
	 * @return string
	 */
	private function config(string $key, string $default): string {
		return trim((string)$this->settings->getConfigValue($key, $default));
	}//end config()
}//end class
