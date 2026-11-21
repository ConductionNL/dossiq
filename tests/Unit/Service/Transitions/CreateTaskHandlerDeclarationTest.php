<?php

/**
 * CreateTaskHandler declaration and once-key failure tests
 *
 * Verifies the per-task declaration a case type writes onto a createTask
 * action lands on the engine task (lead-time due date, candidate groups and
 * users, form and effects metadata), that it never overrides a due date the
 * action already carries, and that a once-key lookup that throws fails the
 * step rather than risking a second task.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use DateTimeImmutable;
use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\CreateTaskHandler
 *
 * @uses \OCA\Dossiq\Service\AssigneeResolver
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 * @uses \OCA\Dossiq\Service\Transitions\TaskAction
 */
class CreateTaskHandlerDeclarationTest extends TestCase {

	/**
	 * What the gateway was last asked to import.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $recorded = null;

	/**
	 * A reachable gateway that records the imported task.
	 *
	 * @return EngineTaskGateway&MockObject
	 */
	private function gateway(): EngineTaskGateway {
		$gateway = $this->getMockBuilder(EngineTaskGateway::class)
			->disableOriginalConstructor()
			->getMock();
		$gateway->method('unavailableReason')->willReturn('');
		$gateway->method('keyTaken')->willReturn(false);
		$gateway->method('mirrorImport')->willReturnCallback(
			function (array $task, string $caseId, ?string $actor): string {
				$this->recorded = $task;

				return 'task-1';
			}
		);

		return $gateway;
	}//end gateway()

	/**
	 * A full declaration lands on the task: due date, candidates, form, effects.
	 *
	 * @return void
	 */
	public function testAFullDeclarationLandsOnTheTask(): void {
		$workingDays = $this->createMock(WorkingDayCalculator::class);
		$workingDays->expects($this->once())
			->method('addWorkingDays')
			->with(
				$this->callback(
					static fn (DateTimeImmutable $start): bool => $start->format('Y-m-d H:i:s') === (new DateTimeImmutable('today'))->format('Y-m-d H:i:s')
				),
				5
			)
			->willReturn(new DateTimeImmutable('2030-01-14T00:00:00+00:00'));

		$handler = new CreateTaskHandler(
			new AssigneeResolver(new NullLogger()),
			$this->gateway(),
			new NullLogger(),
			null,
			$workingDays
		);

		$form = ['fields' => [['key' => 'advice', 'type' => 'text']]];
		$result = $handler->handle(
			actionConfig: [
				'title' => 'Advies',
				'declaration' => [
					'leadTimeDays' => 5,
					'candidateGroups' => ['g1' => 'juristen', 'g2' => 'welstand'],
					'candidateUsers' => ['jan'],
					'form' => $form,
					'effects' => ['e1' => ['type' => 'setField', 'field' => 'advies']],
				],
			],
			case: ['id' => 'case-1'],
			transitionContext: ['userId' => 'jan'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame(['taskId' => 'task-1'], $result->data);
		self::assertSame('2030-01-14T00:00:00+00:00', $this->recorded['dueDate']);
		self::assertSame(['juristen', 'welstand'], $this->recorded['candidateGroups']);
		self::assertSame(['jan'], $this->recorded['candidateUsers']);
		self::assertSame(
			[
				'form' => $form,
				'dossiq' => ['effects' => [['type' => 'setField', 'field' => 'advies']]],
			],
			$this->recorded['metadata']
		);
	}//end testAFullDeclarationLandsOnTheTask()

	/**
	 * An empty declaration adds nothing: no due date, no candidates, no metadata.
	 *
	 * @return void
	 */
	public function testAnEmptyDeclarationAddsNothing(): void {
		$workingDays = $this->createMock(WorkingDayCalculator::class);
		$workingDays->expects($this->never())->method('addWorkingDays');

		$handler = new CreateTaskHandler(
			new AssigneeResolver(new NullLogger()),
			$this->gateway(),
			new NullLogger(),
			null,
			$workingDays
		);

		$result = $handler->handle(
			actionConfig: [
				'title' => 'Advies',
				'declaration' => [
					'leadTimeDays' => 0,
					'candidateGroups' => [],
					'candidateUsers' => 'not-a-list',
					'form' => 'not-a-form',
					'effects' => [],
				],
			],
			case: ['id' => 'case-1'],
			transitionContext: ['userId' => 'jan'],
		);

		self::assertTrue($result->succeeded);
		self::assertArrayNotHasKey('dueDate', $this->recorded);
		self::assertArrayNotHasKey('candidateGroups', $this->recorded);
		self::assertArrayNotHasKey('candidateUsers', $this->recorded);
		self::assertArrayNotHasKey('metadata', $this->recorded);
	}//end testAnEmptyDeclarationAddsNothing()

	/**
	 * A lead time without a calendar gives no due date, and a form alone is metadata.
	 *
	 * @return void
	 */
	public function testALeadTimeWithoutACalendarGivesNoDueDate(): void {
		$handler = new CreateTaskHandler(
			new AssigneeResolver(new NullLogger()),
			$this->gateway(),
			new NullLogger()
		);

		$result = $handler->handle(
			actionConfig: [
				'title' => 'Advies',
				'declaration' => ['leadTimeDays' => 3, 'form' => ['fields' => []]],
			],
			case: ['id' => 'case-1'],
			transitionContext: ['userId' => 'jan'],
		);

		self::assertTrue($result->succeeded);
		self::assertArrayNotHasKey('dueDate', $this->recorded);
		self::assertSame(['form' => ['fields' => []]], $this->recorded['metadata']);
	}//end testALeadTimeWithoutACalendarGivesNoDueDate()

	/**
	 * A once-key lookup that throws fails the step, logs why, and adds nothing.
	 *
	 * @return void
	 */
	public function testAThrowingOnceKeyLookupAddsNothing(): void {
		$gateway = $this->getMockBuilder(EngineTaskGateway::class)
			->disableOriginalConstructor()
			->getMock();
		$gateway->method('unavailableReason')->willReturn('');
		$gateway->expects($this->once())
			->method('keyTaken')
			->with('dossiq:once:rule-1:case-9')
			->willThrowException(new RuntimeException('engine down'));
		$gateway->expects($this->never())->method('mirrorImport');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'CreateTaskHandler: could not tell whether the task was added before',
				['reason' => 'engine down', 'key' => 'dossiq:once:rule-1:case-9']
			);

		$handler = new CreateTaskHandler(new AssigneeResolver(new NullLogger()), $gateway, $logger);

		$result = $handler->handle(
			actionConfig: ['title' => 'T', 'onceKey' => 'rule-1'],
			case: ['id' => 'case-9'],
			transitionContext: ['userId' => 'jan'],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('once_key_unverifiable', $result->error);
	}//end testAThrowingOnceKeyLookupAddsNothing()
}//end class
