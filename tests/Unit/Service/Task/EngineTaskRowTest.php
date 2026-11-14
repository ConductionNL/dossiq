<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use DateTimeImmutable;
use OCA\Dossiq\Service\Task\EngineTaskRow;
use PHPUnit\Framework\TestCase;

/**
 * One engine task, read back in the register's vocabulary.
 *
 * @covers \OCA\Dossiq\Service\Task\EngineTaskRow
 */
class EngineTaskRowTest extends TestCase {

	/**
	 * No entity reads as no task.
	 *
	 * @return void
	 */
	public function testNoTaskIsNull(): void {
		$this->assertNull(actual: (new EngineTaskRow())->toArray(task: null));
	}//end testNoTaskIsNull()

	/**
	 * Every column of an engine that has them all, in the register's names.
	 *
	 * @return void
	 */
	public function testReadsEveryColumnInTheRegistersNames(): void {
		$task = new class {
			/** @return string */
			public function getUuid(): string {
				return 'task-1';
			}
			/** @return string */
			public function getTitle(): string {
				return 'Advies';
			}
			/** @return string */
			public function getState(): string {
				return 'active';
			}
			/** @return string */
			public function getRunUuid(): string {
				return 'run-1';
			}
			/** @return string */
			public function getNodeId(): string {
				return 'node-1';
			}
			/** @return string */
			public function getAssignee(): string {
				return 'anne';
			}
			/** @return array<int, mixed> */
			public function getChecklist(): array {
				return [['id' => 'a']];
			}
			/** @return string */
			public function getObjectUuid(): string {
				return 'case-9';
			}
			/** @return DateTimeImmutable */
			public function getDueAt(): DateTimeImmutable {
				return new DateTimeImmutable('2026-11-02T10:00:00+01:00');
			}
			/** @return array<int, string> */
			public function getCandidateGroups(): array {
				return ['team-a'];
			}
			/** @return string Not a list, so it reads as none. */
			public function getCandidateUsers(): string {
				return 'anne';
			}
			/** @return array<string, mixed> */
			public function getMetadata(): array {
				return ['form' => 'x'];
			}
		};

		$this->assertSame(
			expected: [
				'id'              => 'task-1',
				'title'           => 'Advies',
				'status'          => 'active',
				'flowRun'         => 'run-1',
				'flowNode'        => 'node-1',
				'assignee'        => 'anne',
				'checklist'       => [['id' => 'a']],
				'objectUuid'      => 'case-9',
				'dueDate'         => '2026-11-02T10:00:00+01:00',
				'candidateGroups' => ['team-a'],
				'candidateUsers'  => [],
				'metadata'        => ['form' => 'x'],
			],
			actual: (new EngineTaskRow())->toArray(task: $task)
		);
	}//end testReadsEveryColumnInTheRegistersNames()

	/**
	 * An older engine without the optional columns reads them as empty instead of failing; a string due date passes as it is.
	 *
	 * @return void
	 */
	public function testAnOlderEngineReadsTheMissingColumnsAsEmpty(): void {
		$task = new class {
			/** @var string|null A column only as a property, as an Entity declares it. */
			protected ?string $dueAt = '2026-11-02';

			/**
			 * An Entity's magic getter.
			 *
			 * @param string       $name The method.
			 * @param array<mixed> $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				if ($name === 'getDueAt') {
					return $this->dueAt;
				}

				throw new \BadFunctionCallException($name);
			}
			/** @return string */
			public function getUuid(): string {
				return 'task-2';
			}
			/** @return string */
			public function getTitle(): string {
				return 'Oud';
			}
			/** @return string */
			public function getState(): string {
				return 'available';
			}
			/** @return null */
			public function getRunUuid(): mixed {
				return null;
			}
			/** @return null */
			public function getNodeId(): mixed {
				return null;
			}
			/** @return null */
			public function getAssignee(): mixed {
				return null;
			}
			/** @return null */
			public function getChecklist(): mixed {
				return null;
			}
		};

		$this->assertSame(
			expected: [
				'id'              => 'task-2',
				'title'           => 'Oud',
				'status'          => 'available',
				'flowRun'         => '',
				'flowNode'        => '',
				'assignee'        => '',
				'checklist'       => [],
				'objectUuid'      => '',
				'dueDate'         => '2026-11-02',
				'candidateGroups' => [],
				'candidateUsers'  => [],
				'metadata'        => [],
			],
			actual: (new EngineTaskRow())->toArray(task: $task)
		);
	}//end testAnOlderEngineReadsTheMissingColumnsAsEmpty()
}//end class
