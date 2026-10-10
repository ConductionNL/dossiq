<?php

/**
 * OpenRegister's task engine, in memory: import, inbox and completion.
 *
 * Mirrors the three seams dossiq resolves by name, with their real parameter
 * order: `TaskService::import($data, $actor)`, `TaskInboxService::inbox(
 * $criteria, $limit, $offset)` and `TaskFormCompletion::complete($uuid,
 * $outcome, $resultText, $comment, $data, $actor)`. Import keeps the fields
 * `TaskBuilder::fromData()` reads (`key` becomes `taskKey`), the inbox
 * filters on the criteria's objectUuid and kind, and completion refuses a
 * terminal task the way the engine does.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/tenant-onboarding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use RuntimeException;

class InMemoryTaskEngine {
	/**
	 * The tasks, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $tasks = [];

	/**
	 * Every import, with its actor.
	 *
	 * @var array<int, array{data: array<string, mixed>, actor: ?string}>
	 */
	public array $imports = [];

	/**
	 * When true, every import is refused.
	 *
	 * @var bool
	 */
	public bool $refuseImports = false;

	/**
	 * Import a task, as TaskService::import() does.
	 *
	 * @param array<string, mixed> $data  The task fields.
	 * @param string|null          $actor The creating identity.
	 *
	 * @return array<string, mixed> The stored task.
	 */
	public function import(array $data, ?string $actor): array {
		if ($this->refuseImports === true) {
			throw new RuntimeException('the engine refused the task');
		}

		$this->imports[] = ['data' => $data, 'actor' => $actor];
		$uuid = 'task-'.(count($this->tasks) + 1);
		$state = (string) ($data['state'] ?? 'available');
		$this->tasks[$uuid] = [
			'uuid' => $uuid,
			'taskKey' => $data['key'] ?? null,
			'title' => $data['title'] ?? null,
			'kind' => $data['kind'] ?? null,
			'appId' => $data['appId'] ?? null,
			'organisation' => $data['organisation'] ?? null,
			'objectUuid' => $data['objectUuid'] ?? null,
			'state' => $state,
			'isTerminal' => in_array($state, ['completed', 'terminated', 'disabled'], true),
			'outcome' => $data['outcome'] ?? null,
			'metadata' => $data['metadata'] ?? null,
			'completedBy' => null,
			'completedAt' => null,
		];

		return $this->tasks[$uuid];
	}//end import()

	/**
	 * One page of the inbox, as TaskInboxService::inbox() answers.
	 *
	 * @param object $criteria The criteria.
	 * @param int    $limit    The page size.
	 * @param int    $offset   The offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int} The envelope.
	 */
	public function inbox(object $criteria, int $limit, int $offset): array {
		$rows = array_values(
			array_filter(
				$this->tasks,
				static fn (array $task): bool => ($criteria->objectUuid === null || $task['objectUuid'] === $criteria->objectUuid)
					&& ($criteria->kind === null || $task['kind'] === $criteria->kind)
			)
		);

		return ['results' => array_slice($rows, $offset, $limit), 'total' => count($rows)];
	}//end inbox()

	/**
	 * Complete a task, as TaskFormCompletion::complete() does.
	 *
	 * @param string               $uuid       The task.
	 * @param string               $outcome    The outcome.
	 * @param string|null          $resultText Unused.
	 * @param string|null          $comment    Unused.
	 * @param array<string, mixed> $data       Unused.
	 * @param string|null          $actor      Who completes it.
	 *
	 * @return array<string, mixed> The completed task.
	 */
	public function complete(string $uuid, string $outcome, ?string $resultText, ?string $comment, array $data, ?string $actor): array {
		if (array_key_exists($uuid, $this->tasks) === false) {
			throw new RuntimeException('no such task');
		}

		if ($this->tasks[$uuid]['isTerminal'] === true) {
			throw new RuntimeException('task is already terminal');
		}

		$this->tasks[$uuid]['state'] = 'completed';
		$this->tasks[$uuid]['isTerminal'] = true;
		$this->tasks[$uuid]['outcome'] = $outcome;
		$this->tasks[$uuid]['completedBy'] = $actor;
		$this->tasks[$uuid]['completedAt'] = '2026-10-10T12:00:00+00:00';

		return $this->tasks[$uuid];
	}//end complete()
}//end class
