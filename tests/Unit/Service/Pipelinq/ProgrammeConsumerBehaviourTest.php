<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * How a case is put under a programme, how the programme above a case and its
 * progress are read, and what a completed case hands to the satisfaction loop.
 *
 * @covers \OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer
 *
 * @uses \OCA\Dossiq\Service\Pipelinq\PipelinqGateway
 */
class ProgrammeConsumerBehaviourTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A consumer over a pipelinq that holds the given services.
	 *
	 * @param array<string, object> $services Keyed by class name.
	 */
	private function consumer(array $services = []): ProgrammeConsumer {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException("nothing answers to {$id}");
				}

				return $services[$id];
			}
		);

		return new ProgrammeConsumer(
			new PipelinqGateway($container, $this->createMock(LoggerInterface::class)),
			$this->logger
		);
	}//end consumer()

	/**
	 * A programme portfolio answering fixed values and recording every call.
	 *
	 * @param array<string, mixed> $answers Per method; `read` may be keyed by schema key.
	 */
	private function portfolio(array $answers): object {
		return new class($answers) {
			/** @var list<array{string, array<string, mixed>}> */
			public array $calls = [];

			public function __construct(private array $answers) {
			}

			public function linkWork(string $programmeId, string $domainObjectType, string $domainObjectRef, string $title): mixed {
				$this->calls[] = ['linkWork', compact('programmeId', 'domainObjectType', 'domainObjectRef', 'title')];
				return $this->answers['linkWork'] ?? null;
			}

			public function progressFor(array $programme, array $tasks): mixed {
				$this->calls[] = ['progressFor', compact('programme', 'tasks')];
				return $this->answers['progressFor'] ?? null;
			}

			public function tasksOf(string $programmeId): mixed {
				$this->calls[] = ['tasksOf', compact('programmeId')];
				return $this->answers['tasksOf'] ?? null;
			}

			public function read(string $schemaKey, array $filters): mixed {
				$this->calls[] = ['read', compact('schemaKey', 'filters')];
				return $this->answers['read'][$schemaKey] ?? null;
			}
		};
	}//end portfolio()

	public function testALinkedCaseSendsTrimmedReferences(): void {
		$portfolio = $this->portfolio(['linkWork' => ['status' => 201]]);

		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $portfolio])
			->linkCase(programmeId: ' p1 ', caseId: ' c1 ', title: ' Bouw ');

		$this->assertSame(['linked' => true, 'reason' => ''], $result);
		$this->assertSame(
			[['linkWork', ['programmeId' => 'p1', 'domainObjectType' => 'dossiq:case', 'domainObjectRef' => 'c1', 'title' => 'Bouw']]],
			$portfolio->calls
		);
	}//end testALinkedCaseSendsTrimmedReferences()

	public function testARefusedLinkIsLoggedWithWhereItWasAsked(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('names where it already is: already under p0'),
				['case' => 'c1', 'programme' => 'p1']
			);

		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $this->portfolio(['linkWork' => ['status' => 409, 'error' => 'already under p0']])])
			->linkCase(programmeId: 'p1', caseId: 'c1');

		$this->assertSame(['linked' => false, 'reason' => 'already under p0'], $result);
	}//end testARefusedLinkIsLoggedWithWhereItWasAsked()

	public function testARefusedLinkWithoutAnErrorGetsTheDefaultReason(): void {
		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $this->portfolio(['linkWork' => []])])
			->linkCase(programmeId: 'p1', caseId: 'c1');

		$this->assertSame(['linked' => false, 'reason' => 'pipelinq refused the link'], $result);
	}//end testARefusedLinkWithoutAnErrorGetsTheDefaultReason()

	public function testALinkPipelinqCannotAnswerSaysWhy(): void {
		$this->logger->expects($this->never())->method('info');

		$this->assertSame(
			['linked' => false, 'reason' => 'pipelinq does not serve this capability on this instance'],
			$this->consumer()->linkCase(programmeId: 'p1', caseId: 'c1')
		);
		$this->assertSame(
			['linked' => false, 'reason' => ''],
			$this->consumer([PipelinqGateway::PROGRAMMES => $this->portfolio(['linkWork' => 'ok'])])->linkCase(programmeId: 'p1', caseId: 'c1')
		);
	}//end testALinkPipelinqCannotAnswerSaysWhy()

	public function testProgressIsUnreadableWithoutPipelinq(): void {
		$this->assertSame(
			[
				'available' => false,
				'computable' => false,
				'progress' => null,
				'mode' => '',
				'sentence' => 'Progress cannot be read on this instance.',
			],
			$this->consumer()->progressOf(programmeId: 'p1')
		);
	}//end testProgressIsUnreadableWithoutPipelinq()

	public function testProgressIsAskedWithTheProgrammeIdAddedOnlyWhenAbsent(): void {
		$portfolio = $this->portfolio(['progressFor' => ['computable' => false]]);
		$consumer = $this->consumer([PipelinqGateway::PROGRAMMES => $portfolio]);

		$consumer->progressOf(programmeId: ' p1 ', programme: ['name' => 'N'], tasks: [['id' => 't']]);
		$consumer->progressOf(programmeId: 'p1', programme: ['id' => 'kept']);

		$this->assertSame(
			[
				['progressFor', ['programme' => ['name' => 'N', 'id' => 'p1'], 'tasks' => [['id' => 't']]]],
				['progressFor', ['programme' => ['id' => 'kept'], 'tasks' => []]],
			],
			$portfolio->calls
		);
	}//end testProgressIsAskedWithTheProgrammeIdAddedOnlyWhenAbsent()

	public function testAnUncomputableFigureCarriesPipelinqsReason(): void {
		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $this->portfolio(['progressFor' => ['computable' => 'yes', 'mode' => 'fromTasks', 'reason' => 'no tasks']])])
			->progressOf(programmeId: 'p1');

		$this->assertSame(
			['available' => true, 'computable' => false, 'progress' => null, 'mode' => 'fromTasks', 'sentence' => 'no tasks'],
			$result
		);
	}//end testAnUncomputableFigureCarriesPipelinqsReason()

	public function testAnUncomputableFigureWithoutAReasonGetsTheDefault(): void {
		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $this->portfolio(['progressFor' => []])])->progressOf(programmeId: 'p1');

		$this->assertSame('', $result['mode']);
		$this->assertSame('Progress cannot be computed.', $result['sentence']);
	}//end testAnUncomputableFigureWithoutAReasonGetsTheDefault()

	/**
	 * Modes and the sentence each produces.
	 *
	 * @return array<string, array{mixed, string, string}>
	 */
	public static function modes(): array {
		return [
			'manual' => ['manual', '40', '40 per cent, entered by hand.'],
			'from tasks' => ['fromTasks', 40, '40 per cent, derived from the tasks that are closed.'],
			'from effort' => ['fromEffort', 40.9, '40 per cent, derived from the hours booked against the estimate.'],
			'unknown' => ['guess', 40, '40 per cent, from a mode this instance does not name.'],
		];
	}//end modes()

	/**
	 * @dataProvider modes
	 */
	public function testAComputableFigureSaysHowItWasDerived(string $mode, mixed $progress, string $sentence): void {
		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $this->portfolio(['progressFor' => ['computable' => true, 'mode' => $mode, 'progress' => $progress]])])
			->progressOf(programmeId: 'p1');

		$this->assertSame(
			['available' => true, 'computable' => true, 'progress' => 40, 'mode' => $mode, 'sentence' => $sentence],
			$result
		);
	}//end testAComputableFigureSaysHowItWasDerived()

	public function testAComputableFigureWithoutProgressIsZero(): void {
		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $this->portfolio(['progressFor' => ['computable' => true]])])
			->progressOf(programmeId: 'p1');

		$this->assertSame(0, $result['progress']);
		$this->assertSame('0 per cent, from a mode this instance does not name.', $result['sentence']);
	}//end testAComputableFigureWithoutProgressIsZero()

	public function testProgrammesSkipRowsWithoutAnIdAndTrimNames(): void {
		$portfolio = $this->portfolio(
			[
				'read' => [
					'programme_schema' => [
						['id' => ' p1 ', 'name' => ' Wonen '],
						['uuid' => 'p2'],
						['id' => '  ', 'name' => 'nameless'],
						'junk',
					],
				],
			]
		);

		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $portfolio])->programmes();

		$this->assertSame(
			['available' => true, 'programmes' => [['id' => 'p1', 'name' => 'Wonen'], ['id' => 'p2', 'name' => '']]],
			$result
		);
		$this->assertSame([['read', ['schemaKey' => 'programme_schema', 'filters' => []]]], $portfolio->calls);
	}//end testProgrammesSkipRowsWithoutAnIdAndTrimNames()

	public function testProgrammesAreUnavailableWhenTheReadFails(): void {
		$this->assertSame(['available' => false, 'programmes' => []], $this->consumer()->programmes());
		$this->assertSame(
			['available' => false, 'programmes' => []],
			$this->consumer([PipelinqGateway::PROGRAMMES => $this->portfolio(['read' => ['programme_schema' => 'x']])])->programmes()
		);
	}//end testProgrammesAreUnavailableWhenTheReadFails()

	public function testACaseWithoutWorkItemsIsUnderNoProgramme(): void {
		$portfolio = $this->portfolio(['read' => ['programmeWorkItem_schema' => [['programme' => ' '], 'junk']]]);

		$this->assertSame(
			['available' => true, 'programme' => null],
			$this->consumer([PipelinqGateway::PROGRAMMES => $portfolio])->programmeOf(caseId: ' c1 ')
		);
		$this->assertSame(
			[['read', ['schemaKey' => 'programmeWorkItem_schema', 'filters' => ['domainObjectType' => 'dossiq:case', 'domainObjectRef' => 'c1']]]],
			$portfolio->calls
		);
	}//end testACaseWithoutWorkItemsIsUnderNoProgramme()

	public function testTheProgrammeOfACaseIsUnavailableWithoutPipelinq(): void {
		$this->assertSame(['available' => false, 'programme' => null], $this->consumer()->programmeOf(caseId: 'c1'));
	}//end testTheProgrammeOfACaseIsUnavailableWithoutPipelinq()

	public function testTheProgrammeOfACaseIsDescribedWithItsRecordTasksAndProgress(): void {
		$portfolio = $this->portfolio(
			[
				'read' => [
					'programmeWorkItem_schema' => [['programme' => ''], ['programme' => ' p2 '], ['programme' => 'p3']],
					'programme_schema' => [['id' => 'p1', 'name' => 'Other'], ['uuid' => 'p2', 'name' => ' Wonen ', 'progressMode' => 'fromTasks']],
				],
				'tasksOf' => [['id' => 't1'], 'junk', ['id' => 't2']],
				'progressFor' => ['computable' => true, 'mode' => 'fromTasks', 'progress' => 50],
			]
		);

		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $portfolio])->programmeOf(caseId: 'c1');

		$this->assertSame(
			[
				'available' => true,
				'programme' => [
					'id' => 'p2',
					'name' => 'Wonen',
					'progress' => [
						'available' => true,
						'computable' => true,
						'progress' => 50,
						'mode' => 'fromTasks',
						'sentence' => '50 per cent, derived from the tasks that are closed.',
					],
				],
			],
			$result
		);
		$this->assertContains(['tasksOf', ['programmeId' => 'p2']], $portfolio->calls);
		$this->assertContains(
			[
				'progressFor',
				[
					'programme' => ['uuid' => 'p2', 'name' => ' Wonen ', 'progressMode' => 'fromTasks', 'id' => 'p2'],
					'tasks' => [['id' => 't1'], ['id' => 't2']],
				],
			],
			$portfolio->calls
		);
	}//end testTheProgrammeOfACaseIsDescribedWithItsRecordTasksAndProgress()

	public function testAProgrammeWithoutARecordIsDescribedByItsIdAlone(): void {
		$portfolio = $this->portfolio(
			[
				'read' => ['programmeWorkItem_schema' => [['programme' => 'p9']]],
				'tasksOf' => 'none',
			]
		);

		$result = $this->consumer([PipelinqGateway::PROGRAMMES => $portfolio])->programmeOf(caseId: 'c1');

		$this->assertSame('p9', $result['programme']['id']);
		$this->assertSame('', $result['programme']['name']);
		$this->assertSame('Progress cannot be read on this instance.', $result['programme']['progress']['sentence']);
		$this->assertContains(['progressFor', ['programme' => ['id' => 'p9'], 'tasks' => []]], $portfolio->calls);
	}//end testAProgrammeWithoutARecordIsDescribedByItsIdAlone()

	public function testACompletedCaseIsHandedToTheSurveyDispatch(): void {
		$dispatch = new class {
			/** @var list<array<string, mixed>> */
			public array $calls = [];

			public function onInteractionCompleted(string $entityType, array $entity, array $contact): void {
				$this->calls[] = compact('entityType', 'entity', 'contact');
			}
		};

		$result = $this->consumer([PipelinqGateway::SURVEY_DISPATCH => $dispatch])
			->caseCompleted(case: ['id' => 'c1'], party: ['id' => 'p1']);

		$this->assertSame(['handedOff' => true, 'reason' => ''], $result);
		$this->assertSame([['entityType' => 'case', 'entity' => ['id' => 'c1'], 'contact' => ['id' => 'p1']]], $dispatch->calls);
	}//end testACompletedCaseIsHandedToTheSurveyDispatch()

	public function testACompletedCaseWithoutADispatchIsNotHandedOff(): void {
		$this->assertSame(
			['handedOff' => false, 'reason' => 'pipelinq does not serve this capability on this instance'],
			$this->consumer()->caseCompleted(case: ['id' => 'c1'])
		);
	}//end testACompletedCaseWithoutADispatchIsNotHandedOff()
}//end class
