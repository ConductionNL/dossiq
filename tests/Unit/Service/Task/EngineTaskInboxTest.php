<?php

/**
 * EngineTaskInbox Unit Tests
 *
 * The read half of the seam onto OpenRegister's task engine, and the one
 * place the engine's vocabulary is translated into the register's. Four
 * things are only observable here:
 *
 *  1. The name map. The engine says `state`, `dueAt` and `objectUuid`; the
 *     readers that used to query `caseTask` say `status`, `dueDate` and
 *     `case`. The VALUES need no translation, so a translation table for
 *     them would be wrong rather than merely redundant.
 *  2. Terminality is filtered by the ENGINE, not here. Filtering a paged
 *     window client-side drops every open task past the boundary, which is
 *     how a queue comes to look empty on the day somebody has a hundred
 *     things to do — so the criteria this class builds is asserted on
 *     directly.
 *  3. A row with no uuid is dropped, because a task nothing can be opened
 *     from is worse in a queue than a task that is missing from it.
 *  4. A read that throws returns an empty queue and LOGS. The alternative is
 *     a work queue page that 500s because the engine hiccuped.
 *
 * ⚠️ WHY resolveInbox() IS OVERRIDDEN RATHER THAN MOCKED THROUGH THE
 * CONTAINER. OpenRegister is not installed in this suite, so
 * `class_exists()` inside `resolveInbox()` is false and every method
 * short-circuits to `[]` before it reads a single row. A test built on the
 * container double would assert on an empty array and pass whatever the body
 * did — the same shape as the two tests mutation testing caught on the
 * gateway.
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
 * @spec openspec/changes/remove-casetask/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use DateTime;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Task\EngineTaskInbox
 */
class EngineTaskInboxTest extends TestCase {

	/**
	 * An inbox reading over rows this test supplies.
	 *
	 * Records the criteria it was handed, because the terminality filter
	 * being pushed to the engine is the behaviour, not an implementation
	 * detail — a version that filtered afterwards would return the same
	 * rows for these fixtures.
	 *
	 * @param mixed $answer What `inbox()` returns, or a Throwable to raise.
	 *
	 * @return object The double.
	 */
	private function inboxDouble(mixed $answer): object {
		return new class ($answer) {

			/**
			 * The criteria the last read was made with.
			 *
			 * @var object|null
			 */
			public ?object $seenCriteria = null;

			/**
			 * The page size the last read asked for.
			 *
			 * @var integer
			 */
			public int $seenLimit = 0;

			/**
			 * @param mixed $answer What to answer with.
			 */
			public function __construct(private readonly mixed $answer) {
			}

			/**
			 * @param object  $criteria The read's scope and filters.
			 * @param integer $limit    The page size.
			 * @param integer $offset   Where the page starts.
			 *
			 * @return mixed The rows.
			 */
			public function inbox(object $criteria, int $limit, int $offset): mixed {
				$this->seenCriteria = $criteria;
				$this->seenLimit    = $limit;

				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end inboxDouble()

	/**
	 * The service under test, wired to one inbox double.
	 *
	 * @param object|null $inbox The inbox, or null for "engine unavailable".
	 *
	 * @return EngineTaskInbox The service.
	 */
	private function service(?object $inbox): EngineTaskInbox {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();
		$container = $this->createMock(ContainerInterface::class);
		$logger = new NullLogger();

		$query = new class ($settings, $container, $logger, $inbox) extends EngineInboxQuery {

			/**
			 * @param SettingsService    $settings  The settings bridge.
			 * @param ContainerInterface $container The app container.
			 * @param NullLogger         $logger    The logger.
			 * @param object|null        $inbox     The inbox to answer with.
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
			 * @return object|null The inbox this test supplied.
			 */
			protected function resolveInbox(): ?object {
				return $this->inbox;
			}
		};

		return new class ($settings, $container, $logger, $query) extends EngineTaskInbox {

			/**
			 * @param SettingsService    $settings  The settings bridge.
			 * @param ContainerInterface $container The app container.
			 * @param NullLogger         $logger    The logger.
			 * @param EngineInboxQuery   $query     The query this test supplied.
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
			 * @return EngineInboxQuery The query this test supplied.
			 */
			protected function query(): EngineInboxQuery {
				return $this->stubQuery;
			}
		};
	}//end service()

	/**
	 * The engine's column names become the ones the readers already use.
	 *
	 * @return void
	 */
	public function testAnEngineRowArrivesInTheRegistersVocabulary(): void {
		$service = $this->service(
			$this->inboxDouble(
				[
					'results' => [
						[
							'uuid'       => 'task-1',
							'title'      => 'Meetrapport toezicht opvragen',
							'state'      => 'available',
							'priority'   => 'high',
							'dueAt'      => '2026-09-15T00:00:00+00:00',
							'objectUuid' => 'case-9',
							'assignee'   => 'user:admin',
							'checklist'  => [['id' => 'i-1', 'label' => 'Stuk 1', 'checked' => false]],
						],
					],
				]
			)
		);

		$this->assertSame(
			[
				[
					'id'       => 'task-1',
					'title'    => 'Meetrapport toezicht opvragen',
					'status'   => 'available',
					'priority' => 'high',
					'dueDate'  => '2026-09-15T00:00:00+00:00',
					'case'     => 'case-9',
					'assignee' => 'user:admin',
					// A TYPED list, not a string. `caseTask` held JSON in a
					// string and the checklist guard had to decode it; the
					// engine refuses a string at write time, so this arrives
					// ready to read and must not be flattened by a cast.
					'checklist' => [['id' => 'i-1', 'label' => 'Stuk 1', 'checked' => false]],
				],
			],
			$service->openForAssignee('admin')
		);
	}//end testAnEngineRowArrivesInTheRegistersVocabulary()

	/**
	 * An entity row answers through its getters, not array keys.
	 *
	 * The engine's mapper hands back `Task` entities on one path and plain
	 * arrays on another, and a reader that only understood one of them would
	 * report an empty queue against the other.
	 *
	 * @return void
	 */
	public function testAnEntityRowIsReadThroughItsGetters(): void {
		$row = new class {

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'task-2';
			}

			/**
			 * @return string The title.
			 */
			public function getTitle(): string {
				return 'Bezwaar beoordelen';
			}

			/**
			 * @return string The state.
			 */
			public function getState(): string {
				return 'enabled';
			}

			/**
			 * @return string The priority.
			 */
			public function getPriority(): string {
				return 'normal';
			}

			/**
			 * @return string|null The deadline.
			 */
			public function getDueAt(): ?string {
				return null;
			}

			/**
			 * @return string The anchoring object.
			 */
			public function getObjectUuid(): string {
				return 'case-4';
			}

			/**
			 * @return string The assignee.
			 */
			public function getAssignee(): string {
				return 'user:admin';
			}

			/**
			 * @return array<int, mixed>|null The checklist.
			 */
			public function getChecklist(): ?array {
				return [['id' => 'i-9', 'label' => 'Stuk 9', 'checked' => true]];
			}
		};

		$tasks = $this->service($this->inboxDouble(['results' => [$row]]))->openForAssignee('admin');

		$this->assertCount(1, $tasks);
		$this->assertSame('task-2', $tasks[0]['id']);
		$this->assertSame('enabled', $tasks[0]['status']);
		$this->assertSame('case-4', $tasks[0]['case']);
		$this->assertSame('', $tasks[0]['dueDate']);
		$this->assertSame([['id' => 'i-9', 'label' => 'Stuk 9', 'checked' => true]], $tasks[0]['checklist']);
	}//end testAnEntityRowIsReadThroughItsGetters()

	/**
	 * Terminality is asked of the engine, so a page boundary cannot hide work.
	 *
	 * @return void
	 */
	public function testOnlyOpenTasksAreAskedOfTheEngine(): void {
		$inbox   = $this->inboxDouble(['results' => []]);
		$service = $this->service($inbox);

		$service->openForAssignee('admin', 25);

		$criteria = $inbox->seenCriteria;
		$this->assertInstanceOf(TaskInboxCriteria::class, $criteria);
		$this->assertFalse($criteria->isTerminal, 'the engine must be the one dropping terminal tasks');
		$this->assertSame(TaskInboxCriteria::SCOPE_ASSIGNED, $criteria->scope);
		$this->assertSame('admin', $criteria->uid);
		$this->assertSame(25, $inbox->seenLimit);
	}//end testOnlyOpenTasksAreAskedOfTheEngine()

	/**
	 * The count is the engine's total, NOT the number of rows on the page.
	 *
	 * The inbox pages. A dashboard tile built on `count($rows)` reads the
	 * page size once there are more matches than the limit, so it would
	 * have said the same number for ever no matter how the work grew.
	 *
	 * @return void
	 */
	public function testTheCountIsTheEnginesTotalNotThePageSize(): void {
		$inbox = $this->inboxDouble(
			[
				'results' => [['uuid' => 'task-1'], ['uuid' => 'task-2']],
				'total' => 87,
			]
		);

		self::assertSame(87, $this->service($inbox)->countOpenForAssignee('admin'));
		self::assertSame(1, $inbox->seenLimit, 'a count asks for one row and throws it away');
	}//end testTheCountIsTheEnginesTotalNotThePageSize()

	/**
	 * A due window reaches the engine as criteria, not a client-side filter.
	 *
	 * @return void
	 */
	public function testTheDueWindowReachesTheCriteria(): void {
		$inbox = $this->inboxDouble(['results' => [], 'total' => 0]);

		$this->service($inbox)->countOpenForAssignee(
			'admin',
			'2026-09-10T00:00:00+00:00',
			'2026-09-10T23:59:59+00:00'
		);

		$criteria = $inbox->seenCriteria;
		self::assertInstanceOf(TaskInboxCriteria::class, $criteria);
		self::assertInstanceOf(DateTime::class, $criteria->dueAfter);
		self::assertInstanceOf(DateTime::class, $criteria->dueBefore);
		self::assertSame('2026-09-10T00:00:00+00:00', $criteria->dueAfter->format('c'));
		self::assertFalse($criteria->isTerminal, 'a due-window count still asks only for open work');
	}//end testTheDueWindowReachesTheCriteria()

	/**
	 * A bound the engine cannot read is dropped, not silently made "now".
	 *
	 * A window reset to the current instant answers a plausible number for
	 * the wrong question, which is worse than answering the unbounded one.
	 *
	 * @return void
	 */
	public function testABoundThatDoesNotParseIsDropped(): void {
		$inbox = $this->inboxDouble(['results' => [], 'total' => 4]);

		self::assertSame(4, $this->service($inbox)->countOpenForAssignee('admin', 'not a date'));

		$criteria = $inbox->seenCriteria;
		self::assertInstanceOf(TaskInboxCriteria::class, $criteria);
		self::assertNull($criteria->dueAfter);
	}//end testABoundThatDoesNotParseIsDropped()

	/**
	 * A row carrying no id is dropped rather than queued unopenable.
	 *
	 * @return void
	 */
	public function testARowWithNoIdIsDropped(): void {
		$service = $this->service(
			$this->inboxDouble(
				[
					'results' => [
						['uuid' => '', 'title' => 'Nameless'],
						['uuid' => 'task-3', 'title' => 'Real'],
					],
				]
			)
		);

		$tasks = $service->openForAssignee('admin');

		$this->assertCount(1, $tasks);
		$this->assertSame('task-3', $tasks[0]['id']);
	}//end testARowWithNoIdIsDropped()

	/**
	 * A read that throws yields an empty queue, not a failed page.
	 *
	 * @return void
	 */
	public function testAFailedReadYieldsAnEmptyQueue(): void {
		$service = $this->service($this->inboxDouble(new RuntimeException('engine down')));

		$this->assertSame([], $service->openForAssignee('admin'));
	}//end testAFailedReadYieldsAnEmptyQueue()

	/**
	 * With no engine, and with no actor, there is nothing to read.
	 *
	 * The empty actor matters on its own: the engine is fail-closed and an
	 * inbox read with no identity is a question with no answer, so asking it
	 * would either throw or, worse, be answered broadly.
	 *
	 * @return void
	 */
	public function testNoEngineAndNoActorBothReadEmpty(): void {
		$this->assertSame([], $this->service(null)->openForAssignee('admin'));
		$this->assertSame([], $this->service($this->inboxDouble(['results' => []]))->openForAssignee('  '));
	}//end testNoEngineAndNoActorBothReadEmpty()

	/**
	 * A read that failed is distinguishable from one that found nothing.
	 *
	 * 🔴 THE CHECKLIST GUARD TURNS THIS INTO A TRANSITION DECISION. Both
	 * answer `[]`, and "no tasks" means "nothing unticked", which PASSES.
	 * Without `lastError()` actually being set, an unavailable engine would
	 * wave every guarded transition through, and the guard's own test
	 * cannot catch it: that one mocks this method.
	 *
	 * @return void
	 */
	public function testAFailedReadIsTellableFromAnEmptyOne(): void {
		$unavailable = $this->service(null);
		$unavailable->openForAssignee('admin');
		$this->assertNotSame('', $unavailable->lastError(), 'an absent engine must say so');

		$broken = $this->service($this->inboxDouble(new RuntimeException('engine down')));
		$broken->openForAssignee('admin');
		$this->assertSame('engine down', $broken->lastError());

		$fine = $this->service($this->inboxDouble(['results' => []]));
		$fine->openForAssignee('admin');
		$this->assertSame('', $fine->lastError(), 'a genuinely empty case must NOT look like a failure');
	}//end testAFailedReadIsTellableFromAnEmptyOne()

	/**
	 * A later read clears the earlier read's failure.
	 *
	 * The services holding this reader are long-lived, so one instance
	 * makes many reads. A failure that stuck would make the checklist
	 * guard refuse every transition for the rest of the request after one
	 * hiccup, and it would look exactly like the engine still being down.
	 *
	 * @return void
	 */
	public function testAFailureDoesNotOutliveTheReadThatCausedIt(): void {
		$flaky = new class {

			/**
			 * Whether the next read is the first one.
			 *
			 * @var boolean
			 */
			private bool $first = true;

			/**
			 * @param object  $criteria The read's scope and filters.
			 * @param integer $limit    The page size.
			 * @param integer $offset   Where the page starts.
			 *
			 * @return array<string, mixed> The page.
			 */
			public function inbox(object $criteria, int $limit, int $offset): array {
				if ($this->first === true) {
					$this->first = false;
					throw new RuntimeException('engine down');
				}

				return ['results' => [], 'total' => 0];
			}
		};

		$service = $this->service($flaky);

		$service->openForAssignee('admin');
		$this->assertSame('engine down', $service->lastError());

		$service->openForAssignee('admin');
		$this->assertSame('', $service->lastError(), 'the second read succeeded, so nothing is wrong now');
	}//end testAFailureDoesNotOutliveTheReadThatCausedIt()
}//end class
