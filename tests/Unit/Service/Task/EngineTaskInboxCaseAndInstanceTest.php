<?php

/**
 * EngineTaskInbox: the case read and the instance-wide count.
 *
 * The person-scoped reads are pinned in EngineTaskInboxTest. These two ask
 * different questions and so build different criteria: a case surface wants
 * EVERY task of the case (any assignee, terminal or not), and the instance
 * count wants every open task whoever holds it. Both are asserted on the
 * criteria the engine receives, because a version that read the person's
 * inbox would return the same rows for a single-user fixture.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Task\EngineTaskInbox
 * @uses \OCA\Dossiq\Service\Task\EngineInboxQuery
 */
class EngineTaskInboxCaseAndInstanceTest extends TestCase {

	/**
	 * An inbox that records the criteria and limit it was read with.
	 *
	 * @param array<string, mixed> $answer The envelope to answer with.
	 *
	 * @return object The double.
	 */
	private function inboxDouble(array $answer): object {
		return new class ($answer) {

			/**
			 * @var object|null
			 */
			public ?object $seenCriteria = null;

			/**
			 * @var integer
			 */
			public int $seenLimit = 0;

			/**
			 * @param array<string, mixed> $answer The envelope.
			 */
			public function __construct(private readonly array $answer) {
			}

			/**
			 * @param object  $criteria The criteria.
			 * @param integer $limit    The page size.
			 * @param integer $offset   The offset.
			 *
			 * @return array<string, mixed> The envelope.
			 */
			public function inbox(object $criteria, int $limit, int $offset): array {
				$this->seenCriteria = $criteria;
				$this->seenLimit    = $limit;

				return $this->answer;
			}
		};
	}//end inboxDouble()

	/**
	 * A settings double reporting OpenRegister as installed or not.
	 *
	 * @param boolean $available Whether OpenRegister is available.
	 *
	 * @return SettingsService The double.
	 */
	private function settings(bool $available = true): SettingsService {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();
		$settings->method('isOpenRegisterAvailable')->willReturn($available);

		return $settings;
	}//end settings()

	/**
	 * The service, reading through a query whose inbox is the given double.
	 *
	 * @param object|null $inbox The inbox.
	 *
	 * @return EngineTaskInbox The service.
	 */
	private function service(?object $inbox): EngineTaskInbox {
		$settings  = $this->settings();
		$container = $this->createMock(ContainerInterface::class);
		$logger    = new NullLogger();

		$query = new class ($settings, $container, $logger, $inbox) extends EngineInboxQuery {

			/**
			 * @param SettingsService    $settings  The settings.
			 * @param ContainerInterface $container The container.
			 * @param NullLogger         $logger    The logger.
			 * @param object|null        $inbox     The inbox.
			 */
			public function __construct(
				SettingsService $settings,
				ContainerInterface $container,
				NullLogger $logger,
				private readonly ?object $inbox,
			) {
				parent::__construct($settings, $container, $logger);
			}

			/**
			 * @return object|null The inbox.
			 */
			protected function resolveInbox(): ?object {
				return $this->inbox;
			}
		};

		return new class ($settings, $container, $logger, $query) extends EngineTaskInbox {

			/**
			 * @param SettingsService    $settings  The settings.
			 * @param ContainerInterface $container The container.
			 * @param NullLogger         $logger    The logger.
			 * @param EngineInboxQuery   $stubQuery The query.
			 */
			public function __construct(
				SettingsService $settings,
				ContainerInterface $container,
				NullLogger $logger,
				private readonly EngineInboxQuery $stubQuery,
			) {
				parent::__construct($settings, $container, $logger);
			}

			/**
			 * @return EngineInboxQuery The query.
			 */
			protected function query(): EngineInboxQuery {
				return $this->stubQuery;
			}
		};
	}//end service()

	/**
	 * A case read asks for every task on the object, terminal ones included.
	 *
	 * @return void
	 */
	public function testACaseReadAsksForEveryTaskOnThatObject(): void {
		$inbox = $this->inboxDouble(
			[
				'results' => [
					['uuid' => 'task-1', 'title' => 'Beoordeel', 'state' => 'completed', 'objectUuid' => 'case-1'],
					['title' => 'zonder id'],
				],
			]
		);

		$tasks = $this->service($inbox)->forCase('case-1', 'behandelaar', 30);

		$criteria = $inbox->seenCriteria;
		self::assertInstanceOf(TaskInboxCriteria::class, $criteria);
		self::assertSame(TaskInboxCriteria::SCOPE_ALL, $criteria->scope);
		self::assertSame('case-1', $criteria->objectUuid);
		self::assertSame('behandelaar', $criteria->uid);
		self::assertTrue($criteria->isAdmin);
		self::assertNull($criteria->isTerminal, 'a case surface sees completed work too');
		self::assertSame(30, $inbox->seenLimit);

		self::assertCount(1, $tasks, 'a row with no id is dropped');
		self::assertSame('task-1', $tasks[0]['id']);
		self::assertSame('completed', $tasks[0]['status']);
		self::assertSame('case-1', $tasks[0]['case']);
	}//end testACaseReadAsksForEveryTaskOnThatObject()

	/**
	 * A blank case id reads nothing and never reaches the engine.
	 *
	 * @return void
	 */
	public function testABlankCaseReadsNothing(): void {
		$inbox = $this->inboxDouble(['results' => [['uuid' => 'task-1']]]);

		self::assertSame([], $this->service($inbox)->forCase('  ', 'behandelaar'));
		self::assertNull($inbox->seenCriteria);
	}//end testABlankCaseReadsNothing()

	/**
	 * The instance count asks for every open task, not the caller's own.
	 *
	 * @return void
	 */
	public function testTheInstanceCountAsksForAllOpenWork(): void {
		$inbox = $this->inboxDouble(['results' => [['uuid' => 'task-1']], 'total' => 42]);

		$count = $this->service($inbox)->countOpenEverywhere('admin', '2026-10-01T00:00:00+00:00');

		self::assertSame(42, $count);
		$criteria = $inbox->seenCriteria;
		self::assertInstanceOf(TaskInboxCriteria::class, $criteria);
		self::assertSame(TaskInboxCriteria::SCOPE_ALL, $criteria->scope);
		self::assertFalse($criteria->isTerminal);
		self::assertNull($criteria->dueAfter);
		self::assertSame('2026-10-01T00:00:00+00:00', $criteria->dueBefore?->format('c'));
		self::assertSame(1, $inbox->seenLimit);
	}//end testTheInstanceCountAsksForAllOpenWork()

	/**
	 * Without the seam, an instance with no OpenRegister reads empty, and says why.
	 *
	 * The default query is built lazily on first use and kept, so the reason
	 * the last read answered nothing is still there to ask for afterwards.
	 *
	 * @return void
	 */
	public function testTheDefaultQueryReadsEmptyWithoutOpenRegister(): void {
		$service = new EngineTaskInbox(
			$this->settings(false),
			$this->createMock(ContainerInterface::class),
			new NullLogger()
		);

		self::assertSame([], $service->forCase('case-1', 'admin'));
		self::assertSame(0, $service->countOpenEverywhere('admin'));
		self::assertSame('the task engine is not available', $service->lastError());
	}//end testTheDefaultQueryReadsEmptyWithoutOpenRegister()
}//end class
