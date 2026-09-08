<?php

/**
 * The seam between the transition engine and the workflow a case runs on.
 *
 * 🔴 THE ENGINE ASKED BY CASE TYPE, AND THE CASE TYPE IS NOT THE ANSWER.
 * `getAvailableTransitions()` and `execute()` both resolved through
 * `WorkflowTemplateLoader::getActiveTemplate($caseTypeId)`, which searches for
 * active definitions and takes the first. A case type may now carry several
 * ROUTES with an active definition each, so asking by case type would offer a
 * spoedeisende case the ordinary route's transitions, silently.
 *
 * This test locks the seam itself. The resolution logic is covered by
 * WorkflowTemplateLoaderVariantTest; what is asserted here is that the engine
 * hands over the case and never falls back to asking by case type, because that
 * is the line a later refactor would quietly put back.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\Dossiq\Service\Transitions\StatusChecklist;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The OpenRegister ObjectService shape CaseStatusStore reads through.
 *
 * `register` and `schema` are `mixed` because the production caller hands them
 * over as the strings app-config stores.
 */
interface RouteSeamObjectServiceStub {
	public function find(string $id, mixed $register = null, mixed $schema = null): mixed;

	public function searchObjects(array $query): array;

	public function saveObject(array $object, mixed $register = null, mixed $schema = null): mixed;
}//end interface

/**
 * The engine resolves the workflow through the case.
 *
 * @covers \OCA\Dossiq\Service\StatusTransitionService
 * @uses \OCA\Dossiq\Service\Transitions\CaseResultWriter
 * @uses \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @uses \OCA\Dossiq\Service\Transitions\TransitionAuthorizer
 * @uses \OCA\Dossiq\Service\Transitions\StatusTypeLookup
 * @uses \OCA\Dossiq\Service\Transitions\TransitionSpecReader
 * @uses \OCA\Dossiq\Service\CaseTypeResolver
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 */
class StatusTransitionServiceRouteSeamTest extends TestCase {

	/**
	 * @var WorkflowTemplateLoader&MockObject
	 */
	private WorkflowTemplateLoader $templateLoader;

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The service under test.
	 *
	 * @var StatusTransitionService
	 */
	private StatusTransitionService $service;

	/**
	 * @var SideEffectDispatcher&MockObject
	 */
	private SideEffectDispatcher $dispatcher;

	/**
	 * @var StatusChecklist&MockObject
	 */
	private StatusChecklist $statusChecklist;

	/**
	 * @var GuardRegistry&MockObject
	 */
	private GuardRegistry $guardRegistry;

	/**
	 * The group manager the admin gate reads.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Wire the engine onto a loader we can watch, and a store that answers with
	 * one case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->templateLoader = $this->createMock(WorkflowTemplateLoader::class);

		// Answers BY ID, not with one row for every read: the free-form path
		// asks the target STATUS which case type it belongs to, and a stub that
		// hands back the case for that question refuses every move.
		$objectService = $this->createMock(RouteSeamObjectServiceStub::class);
		$objectService->method('find')->willReturnCallback(
			static fn (string $id): array => match ($id) {
				'case-1' => [
					'id' => 'case-1',
					'caseType' => 'ct-handhaving',
					'status' => 'st-constatering',
					'workflowTemplate' => 'spoed-1',
				],
				'ct-handhaving' => ['id' => 'ct-handhaving'],
				// The status names its case type, which is the link the schema
				// actually declares — see CaseStatusStoreOwnershipTest.
				'st-behandeling' => [
					'id' => 'st-behandeling',
					'name' => 'In behandeling',
					'isFinal' => false,
					'caseType' => 'ct-handhaving',
				],
				default => [],
			}
		);
		$objectService->method('searchObjects')->willReturn([]);
		$objectService->method('saveObject')->willReturnCallback(
			static fn (array $object): array => array_merge(['id' => 'saved-1'], $object)
		);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '7',
				'case_schema' => '11',
				'status_type_schema' => '12',
				'status_record_schema' => '13',
				'case_type_schema' => '14',
				default => '',
			}
		);

		$this->dispatcher = $this->createMock(SideEffectDispatcher::class);
		$this->guardRegistry = $this->createMock(GuardRegistry::class);
		$this->statusChecklist = $this->createMock(StatusChecklist::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->service = new StatusTransitionService(
			$this->templateLoader,
			$this->guardRegistry,
			$this->dispatcher,
			new CaseStatusStore($this->settingsService, new StatusTypeLookup($this->settingsService, new CaseTypeResolver(new CaseTypeStore($this->settingsService))), $logger),
			new TransitionAuthorizer($this->groupManager, $logger),
			new TransitionSpecReader(),
			$this->createMock(IUserSession::class),
			$logger,
			new CaseResultWriter($this->settingsService, new CaseTypeResolver(new CaseTypeStore($this->settingsService))),
			$this->statusChecklist,
		);
	}//end setUp()

	/**
	 * 🔴 Listing a case's transitions hands the loader the CASE.
	 *
	 * @return void
	 */
	public function testAvailableTransitionsResolveThroughTheCase(): void {
		$this->templateLoader->expects($this->once())
			->method('getTemplateForCase')
			->with(
				$this->callback(
					static fn (array $case): bool => ($case['workflowTemplate'] ?? '') === 'spoed-1'
				)
			)
			->willReturn(['id' => 'spoed-1', 'transitions' => []]);

		$this->templateLoader->expects($this->never())->method('getActiveTemplate');

		$result = $this->service->getAvailableTransitions(caseId: 'case-1', userId: 'alice');

		self::assertSame([], $result['transitions']);
	}//end testAvailableTransitionsResolveThroughTheCase()

	/**
	 * 🔴 Executing a transition looks it up inside the case's own workflow.
	 *
	 * @return void
	 */
	public function testExecuteResolvesTheTransitionThroughTheCase(): void {
		$this->templateLoader->expects($this->once())
			->method('getTransitionForCase')
			->with(
				$this->callback(
					static fn (array $case): bool => ($case['workflowTemplate'] ?? '') === 'spoed-1'
				),
				'spoed-t1'
			)
			->willReturn(null);

		$this->templateLoader->expects($this->never())->method('getTransitionById');

		$this->expectExceptionMessage('transition_not_found');

		$this->service->execute(caseId: 'case-1', transitionId: 'spoed-t1', comment: null, userId: 'alice');
	}//end testExecuteResolvesTheTransitionThroughTheCase()

	/**
	 * A guarded move dispatches the target status's checklist FIRST.
	 *
	 * The order is the assertion, not decoration: the transition's own actions
	 * may read the tasks the phase asks for, and a notify that runs before the
	 * tasks exist reports an empty list.
	 *
	 * @return void
	 */
	public function testExecuteDispatchesTheChecklistBeforeTheTransitionsOwnActions(): void {
		$this->templateLoader->method('getTransitionForCase')->willReturn(
			[
				'id' => 'spoed-t1',
				'label' => 'Start behandeling',
				'fromStatus' => 'st-constatering',
				'toStatus' => 'st-behandeling',
				'guards' => [],
				'automaticActions' => [['type' => 'notify', 'message' => 'moved']],
			]
		);

		$this->statusChecklist->expects($this->once())
			->method('actionsFor')
			->with('st-behandeling', $this->callback(static fn (array $case): bool => ($case['id'] ?? '') === 'case-1'))
			->willReturn(
				[['type' => 'createTask', 'title' => 'Assemble the case file', 'workflowStepId' => 'st-behandeling']]
			);

		$dispatched = [];
		$this->dispatcher->method('dispatch')->willReturnCallback(
			function (array $actions) use (&$dispatched): array {
				$dispatched = $actions;
				return [];
			}
		);

		$this->service->execute(caseId: 'case-1', transitionId: 'spoed-t1', comment: null, userId: 'alice');

		self::assertSame(
			['createTask', 'notify'],
			array_map(static fn (array $action): string => (string)$action['type'], $dispatched)
		);
		self::assertSame('Assemble the case file', $dispatched[0]['title']);
		self::assertSame('st-behandeling', $dispatched[0]['workflowStepId']);
	}//end testExecuteDispatchesTheChecklistBeforeTheTransitionsOwnActions()

	/**
	 * An admin's free-form move brings the checklist too.
	 *
	 * This path dispatched NOTHING before: it has no transition to read actions
	 * off. The work belongs to the phase, so a case an admin drops into a
	 * status arrives with the same tasks as one that walked in.
	 *
	 * @return void
	 */
	public function testFreeFormDispatchesTheChecklistOfTheStatusItMovesTo(): void {
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->statusChecklist->expects($this->once())
			->method('actionsFor')
			->with('st-behandeling', $this->callback(static fn (array $case): bool => ($case['id'] ?? '') === 'case-1'))
			->willReturn(
				[['type' => 'createTask', 'title' => 'Assemble the case file', 'workflowStepId' => 'st-behandeling']]
			);

		$dispatched = [];
		$this->dispatcher->method('dispatch')->willReturnCallback(
			function (array $actions) use (&$dispatched): array {
				$dispatched = $actions;
				return [['type' => 'createTask', 'succeeded' => true]];
			}
		);

		$result = $this->service->executeFreeForm(
			caseId: 'case-1',
			toStatusId: 'st-behandeling',
			comment: null,
			userId: 'alice',
		);

		self::assertCount(1, $dispatched);
		self::assertSame('createTask', $dispatched[0]['type']);
		self::assertSame([['type' => 'createTask', 'succeeded' => true]], $result['dispatchedActions']);
	}//end testFreeFormDispatchesTheChecklistOfTheStatusItMovesTo()

	/**
	 * Every transition is checked against the status checklist, declared or not.
	 *
	 * A guard a template has to remember is a guard the next case type forgets,
	 * and a required item that holds only one road out of a phase holds
	 * nothing at all. The transition below declares no guards whatsoever.
	 *
	 * @return void
	 */
	public function testEveryTransitionIsCheckedAgainstTheStatusChecklist(): void {
		$this->templateLoader->method('getTemplateForCase')->willReturn(
			[
				'id' => 'spoed-1',
				'transitions' => [
					[
						'id' => 'spoed-t1',
						'label' => 'Start behandeling',
						'fromStatus' => 'st-constatering',
						'toStatus' => 'st-behandeling',
					],
				],
			]
		);

		$seen = [];
		$this->guardRegistry->method('evaluateAll')->willReturnCallback(
			function (array $guards) use (&$seen): array {
				$seen = $guards;
				return [];
			}
		);

		$this->service->getAvailableTransitions(caseId: 'case-1', userId: 'alice');

		self::assertSame(
			['statusChecklist'],
			array_map(static fn (array $guard): string => (string)$guard['type'], $seen)
		);
	}//end testEveryTransitionIsCheckedAgainstTheStatusChecklist()
}//end class
