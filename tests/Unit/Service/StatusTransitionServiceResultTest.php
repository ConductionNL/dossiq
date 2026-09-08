<?php

/**
 * Closing a case records what came of it.
 *
 * REQ-STE-12: a transition whose target status is final needs a result type,
 * and the result row is written BEFORE the status moves, in the same save.
 * The order matters: a case that reached its final status while the result
 * write failed would be closed with nothing recorded, and no later reader
 * could tell that apart from a case closed without a result on purpose.
 *
 * The third test is the one that keeps the feature usable: a case type with
 * no result types configured has nothing to ask for, so demanding an answer
 * would make its cases unclosable — including for the bezwaar and beroep
 * services, which close cases without a human present.
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
use RuntimeException;

/**
 * A final transition writes a result, or it does not happen.
 *
 * @covers \OCA\Dossiq\Service\StatusTransitionService
 */
class StatusTransitionServiceResultTest extends TestCase {

	/**
	 * The store the engine reads and writes through.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $store;

	/**
	 * The collaborator that reads finality and writes the result.
	 *
	 * @var CaseResultWriter&MockObject
	 */
	private CaseResultWriter $resultWriter;

	/**
	 * The workflow the case runs on.
	 *
	 * @var WorkflowTemplateLoader&MockObject
	 */
	private WorkflowTemplateLoader $templateLoader;

	/**
	 * The service under test.
	 *
	 * @var StatusTransitionService
	 */
	private StatusTransitionService $service;

	/**
	 * Wire the engine onto a case one transition away from a final status.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(CaseStatusStore::class);
		$this->resultWriter = $this->createMock(CaseResultWriter::class);
		$this->templateLoader = $this->createMock(WorkflowTemplateLoader::class);

		$case = [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'status' => 'st-progress',
			'@self' => ['version' => 3],
		];
		$this->store->method('loadCase')->willReturn($case);
		$this->store->method('lookupStatusName')->willReturn('Afgehandeld');
		$this->store->method('writeStatusRecord')->willReturn(['id' => 'rec-1']);
		$this->store->method('updateStatusRecord')->willReturnArgument(0);

		$this->templateLoader->method('getTransitionForCase')->willReturn(
			[
				'id' => 't2',
				'label' => 'Afhandelen',
				'fromStatus' => 'st-progress',
				'toStatus' => 'st-done',
				'guards' => [],
			]
		);

		$guardRegistry = $this->createMock(GuardRegistry::class);
		$guardRegistry->method('evaluateAll')->willReturn([]);

		$specReader = $this->createMock(TransitionSpecReader::class);
		$specReader->method('extractGuards')->willReturn([]);
		$specReader->method('extractActions')->willReturn([]);
		$specReader->method('isRoleHidden')->willReturn(false);

		$dispatcher = $this->createMock(SideEffectDispatcher::class);
		$dispatcher->method('dispatch')->willReturn([]);

		$authorizer = $this->createMock(TransitionAuthorizer::class);
		$authorizer->method('isAdmin')->willReturn(true);
		$authorizer->method('isTransitionGroupAuthorized')->willReturn(true);

		$this->service = new StatusTransitionService(
			templateLoader: $this->templateLoader,
			guardRegistry: $guardRegistry,
			sideEffectDispatcher: $dispatcher,
			store: $this->store,
			authorizer: $authorizer,
			specReader: $specReader,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
			resultWriter: $this->resultWriter,
			statusChecklist: $this->createMock(StatusChecklist::class),
		);
	}//end setUp()

	/**
	 * The refused path first: a closing transition with no result type is
	 * rejected, and the case is never saved.
	 *
	 * @return void
	 */
	public function testFinalTransitionWithoutAResultTypeIsRefused(): void {
		$this->resultWriter->method('isFinalStatus')->willReturn(true);
		$this->resultWriter->method('resolveClosingResult')
			->willThrowException(new RuntimeException('result_type_required'));
		$this->store->expects($this->never())->method('saveCase');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('result_type_required');

		$this->service->execute(caseId: 'case-1', transitionId: 't2', comment: null);
	}//end testFinalTransitionWithoutAResultTypeIsRefused()

	/**
	 * With a result type the row is written and the case references it,
	 * in the same save as the status.
	 *
	 * @return void
	 */
	public function testFinalTransitionWritesTheResultOntoTheCase(): void {
		$this->resultWriter->method('isFinalStatus')->willReturn(true);
		$this->resultWriter->expects($this->once())
			->method('resolveClosingResult')
			->with(caseId: 'case-1', caseTypeId: 'ct-1', resultTypeId: 'rt-granted')
			->willReturn('result-9');

		$saved = [];
		$this->store->expects($this->once())
			->method('saveCase')
			->willReturnCallback(
				function (array $case) use (&$saved): array {
					$saved = $case;
					return ($case + ['@self' => ['version' => 4]]);
				}
			);

		$this->service->execute(
			caseId: 'case-1',
			transitionId: 't2',
			comment: 'Klaar',
			resultTypeId: 'rt-granted',
		);

		$this->assertSame('result-9', $saved['result'], 'the case references the written result');
		$this->assertSame('st-done', $saved['status'], 'the status moves in the same save');
	}//end testFinalTransitionWritesTheResultOntoTheCase()

	/**
	 * A case type with no result types has nothing to ask for, so the
	 * transition passes and no result row is written.
	 *
	 * @return void
	 */
	public function testFinalTransitionPassesWhenTheCaseTypeHasNoResultTypes(): void {
		$this->resultWriter->method('isFinalStatus')->willReturn(true);
		$this->resultWriter->method('resolveClosingResult')->willReturn(null);
		$this->store->expects($this->once())
			->method('saveCase')
			->willReturnCallback(static fn (array $case): array => $case);

		$outcome = $this->service->execute(caseId: 'case-1', transitionId: 't2', comment: null);

		$this->assertSame('ok', $outcome['status']);
	}//end testFinalTransitionPassesWhenTheCaseTypeHasNoResultTypes()

	/**
	 * A non-final transition asks nothing and writes no result.
	 *
	 * @return void
	 */
	public function testNonFinalTransitionWritesNoResult(): void {
		$this->resultWriter->method('isFinalStatus')->willReturn(false);
		$this->resultWriter->expects($this->never())->method('resolveClosingResult');

		$saved = [];
		$this->store->expects($this->once())
			->method('saveCase')
			->willReturnCallback(
				function (array $case) use (&$saved): array {
					$saved = $case;
					return $case;
				}
			);

		$this->service->execute(caseId: 'case-1', transitionId: 't2', comment: null);

		$this->assertArrayNotHasKey('result', $saved);
	}//end testNonFinalTransitionWritesNoResult()
}//end class
