<?php

/**
 * One read of the engine task inbox: rows, totals, the due window and how a failed read is reported.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/add-work-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Task\EngineInboxQuery
 */
class EngineInboxQueryTest extends TestCase {

	/**
	 * A query whose engine is the given inbox; resolveInbox is overridden because the engine class is absent here.
	 *
	 * @param object|null     $inbox  The inbox, or null when the engine is absent.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return EngineInboxQuery
	 */
	private function query(?object $inbox, LoggerInterface $logger): EngineInboxQuery {
		return new class ($this->createMock(SettingsService::class), $this->createMock(ContainerInterface::class), $logger, $inbox) extends EngineInboxQuery {
			/**
			 * Constructor.
			 *
			 * @param SettingsService    $settings  Settings.
			 * @param ContainerInterface $container Container.
			 * @param LoggerInterface    $logger    Logger.
			 * @param object|null        $inbox     The inbox.
			 */
			public function __construct(SettingsService $settings, ContainerInterface $container, LoggerInterface $logger, private readonly ?object $inbox) {
				parent::__construct(settings: $settings, container: $container, logger: $logger);
			}

			/**
			 * The inbox this test supplied.
			 *
			 * @return object|null
			 */
			protected function resolveInbox(): ?object {
				return $this->inbox;
			}
		};
	}//end query()

	/**
	 * An inbox answering the given value, recording the criteria and paging it received.
	 *
	 * @param mixed $answer The answer, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function inbox(mixed $answer): object {
		return new class ($answer) {
			/**
			 * Calls, as [criteria, limit, offset].
			 *
			 * @var array<int, array{0: TaskInboxCriteria, 1: int, 2: int}>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer Answer.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * One page of the inbox.
			 *
			 * @param TaskInboxCriteria $criteria Criteria.
			 * @param int               $limit    Limit.
			 * @param int               $offset   Offset.
			 *
			 * @return mixed
			 */
			public function inbox(TaskInboxCriteria $criteria, int $limit, int $offset): mixed {
				$this->calls[] = [$criteria, $limit, $offset];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end inbox()

	public function testRowsBuildTheCriteriaAndUnwrapTheEnvelope(): void {
		$inbox = $this->inbox(answer: ['total' => 2, 'results' => ['a' => ['id' => 't-1'], 'b' => ['id' => 't-2']]]);
		$query = $this->query(inbox: $inbox, logger: $this->createMock(LoggerInterface::class));

		$rows = $query->rows(criteria: ['uid' => 'jan', 'scope' => 'pooled', 'objectUuid' => 'case-1'], limit: 25, failure: ['unused', []]);

		self::assertSame([['id' => 't-1'], ['id' => 't-2']], $rows);
		self::assertSame('', $query->lastError());
		[$criteria, $limit, $offset] = $inbox->calls[0];
		self::assertSame(['jan', 'pooled', 'case-1', 25, 0], [$criteria->uid, $criteria->scope, $criteria->objectUuid, $limit, $offset]);
	}//end testRowsBuildTheCriteriaAndUnwrapTheEnvelope()

	public function testTasksAndItemsEnvelopesAndABareListAreAllRows(): void {
		$logger = $this->createMock(LoggerInterface::class);

		self::assertSame([['id' => 1]], $this->query(inbox: $this->inbox(answer: ['tasks' => [['id' => 1]]]), logger: $logger)->rows(criteria: ['uid' => 'u'], limit: 1, failure: ['x', []]));
		self::assertSame([['id' => 2]], $this->query(inbox: $this->inbox(answer: ['items' => [['id' => 2]]]), logger: $logger)->rows(criteria: ['uid' => 'u'], limit: 1, failure: ['x', []]));
		self::assertSame([['id' => 3]], $this->query(inbox: $this->inbox(answer: [5 => ['id' => 3]]), logger: $logger)->rows(criteria: ['uid' => 'u'], limit: 1, failure: ['x', []]));
		self::assertSame([], $this->query(inbox: $this->inbox(answer: 'nonsense'), logger: $logger)->rows(criteria: ['uid' => 'u'], limit: 1, failure: ['x', []]));
	}//end testTasksAndItemsEnvelopesAndABareListAreAllRows()

	public function testTotalAsksForOneRowAndReadsTheTotal(): void {
		$inbox = $this->inbox(answer: ['total' => '17', 'results' => [['id' => 't-1']]]);

		self::assertSame(17, $this->query(inbox: $inbox, logger: $this->createMock(LoggerInterface::class))->total(criteria: ['uid' => 'u'], failure: ['x', []]));
		self::assertSame(1, $inbox->calls[0][1]);
	}//end testTotalAsksForOneRowAndReadsTheTotal()

	public function testWithoutAnEngineTheReadIsEmptyAndSaysWhy(): void {
		$query = $this->query(inbox: null, logger: $this->createMock(LoggerInterface::class));

		self::assertSame(0, $query->total(criteria: ['uid' => 'u'], failure: ['x', []]));
		self::assertSame('the task engine is not available', $query->lastError());
		self::assertSame([], $query->rows(criteria: ['uid' => 'u'], limit: 5, failure: ['x', []]));
	}//end testWithoutAnEngineTheReadIsEmptyAndSaysWhy()

	public function testAFailedReadIsAWarningCarryingTheCallersContext(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$logger->expects($this->once())->method('warning')->with('inbox unreadable', ['exception' => 'db down', 'case' => 'case-1']);

		$query = $this->query(inbox: $this->inbox(answer: new RuntimeException('db down')), logger: $logger);

		self::assertSame(0, $query->total(criteria: ['uid' => 'u'], failure: ['inbox unreadable', ['case' => 'case-1']]));
		self::assertSame('db down', $query->lastError());
	}//end testAFailedReadIsAWarningCarryingTheCallersContext()

	public function testAnUnknownNamedParameterIsAnErrorNamingTheCriteria(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->once())->method('error')->with(
			$this->stringContains('does not accept a task filter'),
			['exception' => 'Unknown named parameter $noSuchFilter', 'criteria' => ['uid', 'noSuchFilter']]
		);

		$query = $this->query(inbox: $this->inbox(answer: []), logger: $logger);

		self::assertSame([], $query->rows(criteria: ['uid' => 'u', 'noSuchFilter' => true], limit: 5, failure: ['x', []]));
		self::assertStringContainsString('Unknown named parameter', $query->lastError());
	}//end testAnUnknownNamedParameterIsAnErrorNamingTheCriteria()

	public function testALaterSuccessfulReadClearsTheLastError(): void {
		$query = $this->query(inbox: $this->inbox(answer: ['total' => 1]), logger: $this->createMock(LoggerInterface::class));

		$query->rows(criteria: ['uid' => 'u', 'bogus' => 1], limit: 1, failure: ['x', []]);
		self::assertNotSame('', $query->lastError());

		$query->total(criteria: ['uid' => 'u'], failure: ['x', []]);
		self::assertSame('', $query->lastError());
	}//end testALaterSuccessfulReadClearsTheLastError()

	public function testTheDueWindowKeepsReadableBoundsAndDropsTheRestWithAWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('due-window bound'), ['bound' => 'dueBefore', 'value' => 'garbage']);
		$query = $this->query(inbox: null, logger: $logger);

		$window = $query->dueWindow(after: '2026-03-01', before: 'garbage');

		self::assertSame(['dueAfter'], array_keys($window));
		self::assertSame('2026-03-01', $window['dueAfter']->format('Y-m-d'));
		self::assertSame([], $query->dueWindow(after: null, before: '  '));
	}//end testTheDueWindowKeepsReadableBoundsAndDropsTheRestWithAWarning()

	public function testScopeNamesTheEnginesConstantOrNothing(): void {
		$query = $this->query(inbox: null, logger: $this->createMock(LoggerInterface::class));

		self::assertSame(TaskInboxCriteria::SCOPE_POOLED, $query->scope(name: 'SCOPE_POOLED'));
		self::assertSame('', $query->scope(name: 'SCOPE_NOWHERE'));
	}//end testScopeNamesTheEnginesConstantOrNothing()
}//end class
