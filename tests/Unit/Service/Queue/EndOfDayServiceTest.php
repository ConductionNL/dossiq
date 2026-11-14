<?php

/**
 * The "Close out your day" lists, worked out on the server: opened today is
 * the register's read state, recorded today is the reader's own notes of
 * today, and the rest feeds "First thing tomorrow" (end-of-day-manifest).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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
 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Dossiq\Service\Queue\EndOfDayRowMeta;
use OCA\Dossiq\Service\Queue\EndOfDayService;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see EndOfDayService}.
 */
class EndOfDayServiceTest extends TestCase {

	/**
	 * The service over a queue, read states and notes.
	 *
	 * @param list<array<string, mixed>>        $items       The queue items.
	 * @param array<string, string>             $lastSeen    Last seen moment per case id, or per task engine uuid ('engine-<id>').
	 * @param array<string, list<array<string, mixed>>> $notes Notes per subject id.
	 * @param list<array<string, mixed>>        $unavailable Sources that could not be read.
	 *
	 * @return EndOfDayService The service.
	 */
	private function service(array $items, array $lastSeen, array $notes, array $unavailable=[]): EndOfDayService {
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->method('forPerson')->willReturn(['items' => $items, 'unavailable' => $unavailable]);

		$objectService = new class {
			/**
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object The object, its own `updated` always today.
			 */
			public function find(string $id, string $register, string $schema): object {
				if ($schema === 'task') {
					throw new RuntimeException('not an object');
				}

				return new class($id) {
					/**
					 * @param string $uuid The uuid.
					 */
					public function __construct(public string $uuid) {
					}

					/**
					 * @return array<string, mixed> The case, numbered after its uuid.
					 */
					public function jsonSerialize(): array {
						return ['uuid' => $this->uuid, 'identifier' => 'N-' . $this->uuid, 'title' => 'Case ' . $this->uuid];
					}
				};
			}
		};
		$readState = new class($lastSeen) {
			/**
			 * @param array<string, string> $lastSeen Last seen per uuid.
			 */
			public function __construct(private array $lastSeen) {
			}

			/**
			 * @param object      $object The object.
			 * @param string|null $userId The reader.
			 *
			 * @return object|null The state.
			 */
			public function readStateFor(object $object, ?string $userId=null): ?object {
				$seen = ($this->lastSeen[$object->uuid] ?? null);
				if ($seen === null) {
					return null;
				}

				return new class($seen) {
					/**
					 * @param string $seen The moment.
					 */
					public function __construct(private string $seen) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['lastSeenAt' => $this->seen];
					}
				};
			}
		};
		$noteService = new class($notes) {
			/**
			 * @param array<string, list<array<string, mixed>>> $notes Notes per uuid.
			 */
			public function __construct(private array $notes) {
			}

			/**
			 * @param string $objectUuid The uuid.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function getNotesForObject(string $objectUuid): array {
				return ($this->notes[$objectUuid] ?? []);
			}
		};

		// The read states by uuid, as OpenRegister's ObjectReadStateMapper answers them.
		$readStates = new class($lastSeen) {
			/**
			 * @param array<string, string> $lastSeen Last seen per uuid.
			 */
			public function __construct(private array $lastSeen) {
			}

			/**
			 * @param string $userId     The reader.
			 * @param string $objectUuid The uuid.
			 *
			 * @return object|null The state.
			 */
			public function findOne(string $userId, string $objectUuid): ?object {
				$seen = ($this->lastSeen[$objectUuid] ?? null);
				if ($seen === null) {
					return null;
				}

				return new class($seen) {
					/**
					 * @param string $seen The moment.
					 */
					public function __construct(private string $seen) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['lastSeenAt' => $this->seen];
					}
				};
			}
		};
		// The task engine knows a task by its id and answers its uuid.
		$tasks = $this->createMock(EngineTaskGateway::class);
		$tasks->method('find')->willReturnCallback(
			static fn (string $taskId): array => ['id' => 'engine-' . $taskId, 'title' => 'Task ' . $taskId]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static fn (string $class): object => match (true) {
				str_ends_with($class, 'ReadStateService') => $readState,
				str_ends_with($class, 'ObjectReadStateMapper') => $readStates,
				default => $noteService,
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => $default
		);
		// Nextcloud's IL10N::t() fills `%s` / `%1$s` with vsprintf and leaves
		// `{name}` as written, so the fake does the same.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => vsprintf($text, array_values($parameters))
		);

		$rowMeta = new EndOfDayRowMeta(settings: $settings, tasks: $tasks, l10n: $l10n, logger: new NullLogger());

		return new EndOfDayService(queue: $queue, settings: $settings, l10n: $l10n, logger: new NullLogger(), tasks: $tasks, rowMeta: $rowMeta);
	}//end service()

	/**
	 * A queue item.
	 *
	 * @param string      $type  case or task.
	 * @param string      $id    The subject id.
	 * @param string|null $dueAt The term.
	 *
	 * @return array<string, mixed>
	 */
	private function item(string $type, string $id, ?string $dueAt=null): array {
		$route = 'CaseDetail';
		if ($type === 'task') {
			$route = 'TaskDetail';
		}

		return [
			'id' => $type . 's:' . $type . ':' . $id,
			'subjectType' => $type,
			'subjectId' => $id,
			'title' => 'Item ' . $id,
			'dueAt' => $dueAt,
			'route' => ['name' => $route, 'params' => ['id' => $id]],
		];
	}//end item()

	/**
	 * Wednesday 20 October 2026, 17:00 in Amsterdam.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-20 17:00:00', new DateTimeZone('Europe/Amsterdam'));
	}//end now()

	/**
	 * Opened today without a note is open; with the reader's note of today it
	 * is recorded, with the note and its time; a colleague's note does not count.
	 *
	 * @return void
	 */
	public function testSortsTheDayIntoOpenAndRecorded(): void {
		$day = $this->service(
			items: [$this->item('case', 'c1'), $this->item('case', 'c2'), $this->item('case', 'c3'), ['id' => 'm', 'subjectType' => 'mention', 'subjectId' => 'x']],
			lastSeen: ['c1' => '2026-10-20T08:15:00+00:00', 'c2' => '2026-10-20T09:00:00+00:00', 'c3' => '2026-10-20T10:00:00+00:00'],
			notes: [
				'c2' => [['actorId' => 'pieter', 'message' => 'Gebeld met de indiener', 'createdAt' => '2026-10-20T13:40:00+00:00']],
				'c3' => [['actorId' => 'anouk', 'message' => 'Van een collega', 'createdAt' => '2026-10-20T11:00:00+00:00']],
			]
		)->dayFor(userId: 'pieter', now: $this->now());

		$this->assertSame(['cases:case:c1', 'cases:case:c3'], array_column($day['open'], 'id'));
		$this->assertSame(['cases:case:c2'], array_column($day['recorded'], 'id'));
		$this->assertSame('Gebeld met de indiener', $day['recorded'][0]['note']);
		$this->assertSame('15:40', $day['recorded'][0]['recordedTime']);
		$this->assertSame(3, $day['opened']);
		$this->assertSame('/apps/openregister/api/objects/dossiq/case/c1/notes', $day['open'][0]['notesUrl']);
		$this->assertSame(['CaseDetail', 'c1'], [$day['open'][0]['routeName'], $day['open'][0]['routeId']]);
		$this->assertSame(expected: 'N-c1', actual: $day['open'][0]['meta']);
		$this->assertSame(expected: 'N-c2 · 15:40', actual: $day['recorded'][0]['recordedMeta']);
	}//end testSortsTheDayIntoOpenAndRecorded()

	/**
	 * "Opened today" is the reader's read state, not the object's own update:
	 * an object changed today that the reader last saw yesterday is not listed.
	 *
	 * @return void
	 */
	public function testOpenedTodayIsTheReadStateNotTheObjectsUpdate(): void {
		$day = $this->service(
			items: [$this->item('case', 'c1')],
			lastSeen: ['c1' => '2026-10-19T15:00:00+00:00'],
			notes: []
		)->dayFor(userId: 'pieter', now: $this->now());

		$this->assertSame([], $day['open']);
		$this->assertSame(0, $day['opened']);
	}//end testOpenedTodayIsTheReadStateNotTheObjectsUpdate()

	/**
	 * A task lives in the task engine, not in the register: its read state is
	 * the one of its engine uuid, so a task the reader opened today is listed
	 * and one opened yesterday is not.
	 *
	 * @return void
	 */
	public function testATaskOpenedTodayIsReadThroughTheTaskEngine(): void {
		$day = $this->service(
			items: [$this->item('task', 't1'), $this->item('task', 't2')],
			lastSeen: ['engine-t1' => '2026-10-20T09:30:00+00:00', 'engine-t2' => '2026-10-19T09:30:00+00:00'],
			notes: []
		)->dayFor(userId: 'pieter', now: $this->now());

		$this->assertSame(['tasks:task:t1'], array_column($day['open'], 'id'));
		$this->assertSame('/apps/openregister/api/flow-tasks/t1/notes', $day['open'][0]['notesUrl']);
		$this->assertSame('0 cases, 1 tasks', $day['figures'][0]['note']);
	}//end testATaskOpenedTodayIsReadThroughTheTaskEngine()

	/**
	 * A note recorded today is listed again on the next read, so the list
	 * survives a reload; a task's note goes to the task's own endpoint.
	 *
	 * @return void
	 */
	public function testRecordedTodaySurvivesAReloadAndATaskRecordsOnTheTask(): void {
		$service = $this->service(
			items: [$this->item('task', 't1')],
			lastSeen: [],
			notes: ['t1' => [['actorId' => 'pieter', 'message' => 'Advies gelezen', 'createdAt' => '2026-10-20T07:30:00+00:00']]]
		);

		$first = $service->dayFor(userId: 'pieter', now: $this->now());
		$again = $service->dayFor(userId: 'pieter', now: $this->now());

		$this->assertSame($first['recorded'], $again['recorded']);
		$this->assertSame('/apps/openregister/api/flow-tasks/t1/notes', $first['recorded'][0]['notesUrl']);
		$this->assertSame('Task', $first['recorded'][0]['kindLabel']);
	}//end testRecordedTodaySurvivesAReloadAndATaskRecordsOnTheTask()

	/**
	 * First thing tomorrow: what the reader did not open today whose term ends
	 * by tomorrow, soonest first, two at most.
	 *
	 * @return void
	 */
	public function testFirstThingTomorrowIsWhatIsDueAndNotOpened(): void {
		$day = $this->service(
			items: [
				$this->item('case', 'late', '2026-10-17'),
				$this->item('case', 'tomorrow', '2026-10-21'),
				$this->item('case', 'today', '2026-10-20'),
				$this->item('case', 'later', '2026-10-30'),
				$this->item('case', 'opened', '2026-10-19'),
			],
			lastSeen: ['opened' => '2026-10-20T08:00:00+00:00'],
			notes: []
		)->dayFor(userId: 'pieter', now: $this->now());

		$this->assertSame(['cases:case:late', 'cases:case:today'], array_column($day['tomorrow'], 'id'));
	}//end testFirstThingTomorrowIsWhatIsDueAndNotOpened()

	/**
	 * The figures and the sentence for a queue source that could not be read.
	 *
	 * @return void
	 */
	public function testFiguresAndWhatCouldNotBeRead(): void {
		$day = $this->service(
			items: [$this->item('case', 'c1'), $this->item('task', 't1')],
			lastSeen: ['c1' => '2026-10-20T08:00:00+00:00'],
			notes: ['t1' => [['actorId' => 'pieter', 'message' => 'x', 'createdAt' => '2026-10-20T08:00:00+00:00']]],
			unavailable: [['source' => 'tasks', 'label' => 'Tasks']]
		)->dayFor(userId: 'pieter', now: $this->now());

		$this->assertSame([2, 1], array_column($day['figures'], 'value'));
		$this->assertSame('1 cases, 1 tasks', $day['figures'][0]['note']);
		$this->assertSame('of the 2', $day['figures'][1]['note']);
		$this->assertSame('Tasks could not be read, so this list is incomplete.', $day['unavailableText']);
	}//end testFiguresAndWhatCouldNotBeRead()
}//end class
