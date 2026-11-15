<?php

/**
 * EndOfDayService when OpenRegister is absent, refuses, or answers nonsense.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An item the register cannot answer for is not opened and not recorded:
 * it stays untouched, so a term ending tomorrow still reaches "First thing tomorrow".
 *
 * @covers \OCA\Dossiq\Service\Queue\EndOfDayService
 * @uses   \OCA\Dossiq\Service\Queue\EndOfDayRowMeta
 */
class EndOfDayWithoutTheRegisterTest extends TestCase {

	/**
	 * The settings, answering the register's classes.
	 *
	 * @var SettingsService&MockObject
	 */
	private $settings;

	/**
	 * The task engine.
	 *
	 * @var EngineTaskGateway&MockObject
	 */
	private $tasks;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Fresh boundaries; config answers its defaults.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default=''): string => $default);
		$this->tasks = $this->createMock(EngineTaskGateway::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The day for pieter on Tuesday 20 October 2026, 17:00 Amsterdam, over a case and a task due tomorrow.
	 *
	 * @return array<string, mixed>
	 */
	private function day(): array {
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->method('forPerson')->willReturn(
			[
				'items' => [
					['id' => 'case:c1', 'subjectType' => 'case', 'subjectId' => 'c1', 'title' => 'Bezwaar', 'dueAt' => '2026-10-21'],
					['id' => 'task:t1', 'subjectType' => 'task', 'subjectId' => 't1', 'title' => 'Advies', 'dueAt' => '2026-10-21T12:00:00+02:00'],
				],
				'unavailable' => [],
			]
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf($text, array_values($parameters)));
		$rowMeta = new EndOfDayRowMeta(settings: $this->settings, tasks: $this->tasks, l10n: $l10n, logger: $this->logger);

		$service = new EndOfDayService(queue: $queue, settings: $this->settings, l10n: $l10n, logger: $this->logger, tasks: $this->tasks, rowMeta: $rowMeta);

		return $service->dayFor(userId: 'pieter', now: new DateTimeImmutable('2026-10-20 17:00:00', new DateTimeZone('Europe/Amsterdam')));
	}//end day()

	/**
	 * Both items are untouched and both reach tomorrow; nothing was opened.
	 *
	 * @param array<string, mixed> $day The day.
	 *
	 * @return void
	 */
	private function assertAllUntouched(array $day): void {
		$this->assertSame([], $day['open']);
		$this->assertSame([], $day['recorded']);
		$this->assertSame(0, $day['opened']);
		$this->assertSame(['case:c1', 'task:t1'], array_column($day['tomorrow'], 'id'));
	}//end assertAllUntouched()

	/**
	 * Without OpenRegister nothing is opened or recorded, and nothing is asked of the task engine.
	 *
	 * @return void
	 */
	public function testWithoutTheRegisterEverythingIsUntouched(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->method('getOpenRegisterClass')->willReturn(null);
		$this->tasks->method('find')->willReturn(['id' => 'engine-t1']);
		$this->logger->expects($this->never())->method('warning');

		$this->assertAllUntouched($this->day());
	}//end testWithoutTheRegisterEverythingIsUntouched()

	/**
	 * A task the engine no longer knows has no read state, even where the register has read states.
	 *
	 * @return void
	 */
	public function testATaskTheEngineDoesNotKnowIsNotOpened(): void {
		$states = new class {
			/**
			 * Would say "seen today" for anything.
			 *
			 * @param string $userId     The reader.
			 * @param string $objectUuid The uuid.
			 *
			 * @return object
			 */
			public function findOne(string $userId, string $objectUuid): object {
				return new class {
					/**
					 * @return array<string, string>
					 */
					public function jsonSerialize(): array {
						return ['lastSeenAt' => '2026-10-20T10:00:00+00:00'];
					}
				};
			}
		};
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->method('getOpenRegisterClass')->willReturnCallback(
			static fn (string $class): ?object => (str_ends_with($class, 'ObjectReadStateMapper') ? $states : null)
		);
		$this->tasks->expects($this->once())->method('find')->with('t1')->willReturn(null);

		$this->assertAllUntouched($this->day());
	}//end testATaskTheEngineDoesNotKnowIsNotOpened()

	/**
	 * A register that throws on notes and read state leaves the items untouched and says so in the log.
	 *
	 * @return void
	 */
	public function testARefusingRegisterIsLoggedAndLeavesItemsUntouched(): void {
		$objects = new class {
			/**
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object
			 */
			public function find(string $id, string $register, string $schema): object {
				return (object)['uuid' => $id];
			}
		};
		$throwing = new class {
			/**
			 * @param string $objectUuid The uuid.
			 *
			 * @return array<int, mixed>
			 */
			public function getNotesForObject(string $objectUuid): array {
				throw new RuntimeException('notes offline');
			}

			/**
			 * @param object $object The object.
			 * @param string $userId The reader.
			 *
			 * @return object|null
			 */
			public function readStateFor(object $object, string $userId): ?object {
				throw new RuntimeException('read state offline');
			}

			/**
			 * @param string $userId     The reader.
			 * @param string $objectUuid The uuid.
			 *
			 * @return object|null
			 */
			public function findOne(string $userId, string $objectUuid): ?object {
				throw new RuntimeException('read state offline');
			}
		};
		$this->settings->method('getObjectService')->willReturn($objects);
		$this->settings->method('getOpenRegisterClass')->willReturn($throwing);
		$this->tasks->method('find')->willReturn(['id' => 'engine-t1']);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});
		$this->logger->expects($this->exactly(2))->method('debug')
			->with('Dossiq: no read state for an end-of-day candidate: read state offline');

		$this->assertAllUntouched($this->day());
		$this->assertSame(
			[
				'Dossiq: the notes of end-of-day candidate case:c1 could not be read: notes offline',
				'Dossiq: the notes of end-of-day candidate task:t1 could not be read: notes offline',
			],
			$warnings
		);
	}//end testARefusingRegisterIsLoggedAndLeavesItemsUntouched()

	/**
	 * A last-seen moment that is not a date does not count as today.
	 *
	 * @return void
	 */
	public function testAnUnreadableLastSeenIsNotToday(): void {
		$objects = new class {
			/**
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object
			 */
			public function find(string $id, string $register, string $schema): object {
				return (object)['uuid' => $id];
			}
		};
		$nonsense = new class {
			/**
			 * @param string $objectUuid The uuid.
			 *
			 * @return array<int, mixed>
			 */
			public function getNotesForObject(string $objectUuid): array {
				return [];
			}

			/**
			 * @return object
			 */
			private function state(): object {
				return new class {
					/**
					 * @return array<string, string>
					 */
					public function jsonSerialize(): array {
						return ['lastSeenAt' => 'gisteren rond vier'];
					}
				};
			}

			/**
			 * @param object $object The object.
			 * @param string $userId The reader.
			 *
			 * @return object
			 */
			public function readStateFor(object $object, string $userId): object {
				return $this->state();
			}

			/**
			 * @param string $userId     The reader.
			 * @param string $objectUuid The uuid.
			 *
			 * @return object
			 */
			public function findOne(string $userId, string $objectUuid): object {
				return $this->state();
			}
		};
		$this->settings->method('getObjectService')->willReturn($objects);
		$this->settings->method('getOpenRegisterClass')->willReturn($nonsense);
		$this->tasks->method('find')->willReturn(['id' => 'engine-t1']);

		$this->assertAllUntouched($this->day());
	}//end testAnUnreadableLastSeenIsNotToday()
}//end class
