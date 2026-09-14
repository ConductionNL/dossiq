<?php

/**
 * A transition that moved without all of its actions says so.
 *
 * REQ-STE-14. The status moves before any action runs, so an action can fail
 * after the case has already changed. `SideEffectDispatcher` records that
 * failure as a row with `ok: false`, and both execute paths used to answer
 * `status: ok` over it. On 2026-09-10 twelve refused `createTask` rows sat in
 * the status records while the API told every handler the move had worked.
 *
 * Each path is driven twice, once with every action done and once with a
 * failure, so a mutation that hard-codes either answer reddens a test.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\Dossiq\Service\Transitions\StatusChecklist;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The answer names the actions that did not run.
 *
 * @covers \OCA\Dossiq\Service\StatusTransitionService
 *
 * @spec openspec/changes/transition-reports-failed-actions/specs/status-transition-engine/spec.md
 */
final class StatusTransitionServiceFailedActionsTest extends TestCase {

	/**
	 * The dispatcher whose result rows each test sets.
	 *
	 * @var SideEffectDispatcher&MockObject
	 */
	private SideEffectDispatcher $dispatcher;

	/**
	 * The logger the partial answer warns through.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var StatusTransitionService
	 */
	private StatusTransitionService $service;

	/**
	 * Wire the engine onto a case one ordinary transition away from its next status.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->method('loadCase')->willReturn(
			[
				'id' => 'case-1',
				'caseType' => 'ct-1',
				'status' => 'st-received',
				'@self' => ['version' => 3],
			]
		);
		$store->method('lookupStatusName')->willReturn('In behandeling');
		$store->method('saveCase')->willReturnCallback(
			static fn (array $case): array => array_replace($case, ['@self' => ['version' => 4]])
		);
		$store->method('writeStatusRecord')->willReturn(['id' => 'rec-1']);
		$store->method('updateStatusRecord')->willReturnArgument(0);

		$templateLoader = $this->createMock(originalClassName: WorkflowTemplateLoader::class);
		$templateLoader->method('getTransitionForCase')->willReturn(
			[
				'id' => 't1',
				'label' => 'Start behandeling',
				'fromStatus' => 'st-received',
				'toStatus' => 'st-progress',
				'guards' => [],
			]
		);

		$guardRegistry = $this->createMock(originalClassName: GuardRegistry::class);
		$guardRegistry->method('evaluateAll')->willReturn([]);

		$specReader = $this->createMock(originalClassName: TransitionSpecReader::class);
		$specReader->method('extractGuards')->willReturn([]);
		$specReader->method('extractActions')->willReturn([]);
		$specReader->method('isRoleHidden')->willReturn(false);

		$authorizer = $this->createMock(originalClassName: TransitionAuthorizer::class);
		$authorizer->method('isAdmin')->willReturn(true);
		$authorizer->method('isTransitionGroupAuthorized')->willReturn(true);

		$resultWriter = $this->createMock(originalClassName: CaseResultWriter::class);
		$resultWriter->method('isFinalStatus')->willReturn(false);

		$this->dispatcher = $this->createMock(originalClassName: SideEffectDispatcher::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new StatusTransitionService(
			templateLoader: $templateLoader,
			guardRegistry: $guardRegistry,
			sideEffectDispatcher: $this->dispatcher,
			store: $store,
			authorizer: $authorizer,
			specReader: $specReader,
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $this->logger,
			resultWriter: $resultWriter,
			statusChecklist: $this->createMock(originalClassName: StatusChecklist::class),
		);
	}//end setUp()

	/**
	 * Rows where one action failed, among the shapes a row can take.
	 *
	 * A row without an `ok` key counts as done, and so does a row that is not
	 * an array at all: the real dispatcher always sets `ok`, so only an
	 * explicit `false` is a failure. A failed row without an `error` still
	 * reports one.
	 *
	 * @return array<int, mixed>
	 */
	private function mixedRows(): array {
		return [
			['type' => 'createTask', 'ok' => false, 'error' => 'no_actor'],
			['type' => 'sendEmail', 'ok' => true],
			['type' => 'setProperty'],
			'not-a-row',
			['ok' => false],
		];
	}//end mixedRows()

	/**
	 * Every action ran: the answer is `ok`, the list is empty, nothing warns.
	 *
	 * @return void
	 */
	public function testExecuteAnswersOkWhenEveryActionRan(): void {
		$this->dispatcher->method('dispatch')->willReturn(
			[
				['type' => 'createTask', 'ok' => true],
				['type' => 'sendEmail'],
			]
		);
		$this->logger->expects($this->never())->method('warning');

		$outcome = $this->service->execute(caseId: 'case-1', transitionId: 't1', comment: null);

		$this->assertSame(expected: 'ok', actual: $outcome['status']);
		$this->assertSame(expected: [], actual: $outcome['failedActions']);
		$this->assertSame(expected: 4, actual: $outcome['version'], message: 'the answer still carries the saved version');
	}//end testExecuteAnswersOkWhenEveryActionRan()

	/**
	 * An action failed after the move: the answer is `partial` and names it.
	 *
	 * @return void
	 */
	public function testExecuteAnswersPartialAndNamesTheFailedActions(): void {
		$this->dispatcher->method('dispatch')->willReturn($this->mixedRows());
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains(string: 'did not run'),
				$this->callback(
					callback: static fn (array $context): bool => $context['case'] === 'case-1'
						&& $context['count'] === 2
						&& count($context['failed']) === 2
				),
			);

		$outcome = $this->service->execute(caseId: 'case-1', transitionId: 't1', comment: null);

		$this->assertSame(expected: 'partial', actual: $outcome['status']);
		$this->assertSame(
			expected: [
				['type' => 'createTask', 'error' => 'no_actor'],
				['type' => '', 'error' => 'action_failed'],
			],
			actual: $outcome['failedActions'],
		);
		$this->assertSame(expected: 'rec-1', actual: $outcome['statusRecord']['id'], message: 'the move is recorded, not rolled back');
		$this->assertCount(expectedCount: 5, haystack: $outcome['dispatchedActions']);
	}//end testExecuteAnswersPartialAndNamesTheFailedActions()

	/**
	 * The free-form path answers `ok` when its checklist actions all ran.
	 *
	 * @return void
	 */
	public function testFreeFormAnswersOkWhenEveryActionRan(): void {
		$this->dispatcher->method('dispatch')->willReturn([['type' => 'createTask', 'ok' => true]]);
		$this->logger->expects($this->never())->method('warning');

		$outcome = $this->service->executeFreeForm(
			caseId: 'case-1',
			toStatusId: 'st-progress',
			comment: null,
			userId: 'admin',
		);

		$this->assertSame(expected: 'ok', actual: $outcome['status']);
		$this->assertSame(expected: [], actual: $outcome['failedActions']);
	}//end testFreeFormAnswersOkWhenEveryActionRan()

	/**
	 * The free-form path answers `partial` the same way.
	 *
	 * @return void
	 */
	public function testFreeFormAnswersPartialAndNamesTheFailedActions(): void {
		$this->dispatcher->method('dispatch')->willReturn(
			[
				['type' => 'createTask', 'ok' => false, 'error' => 'no_actor'],
			]
		);
		$this->logger->expects($this->once())->method('warning');

		$outcome = $this->service->executeFreeForm(
			caseId: 'case-1',
			toStatusId: 'st-progress',
			comment: null,
			userId: 'admin',
		);

		$this->assertSame(expected: 'partial', actual: $outcome['status']);
		$this->assertSame(expected: [['type' => 'createTask', 'error' => 'no_actor']], actual: $outcome['failedActions']);
	}//end testFreeFormAnswersPartialAndNamesTheFailedActions()
}//end class
