<?php

/**
 * What a caller gets for free by going through the engine.
 *
 * `StatusTransitionGroupAuthTest` proves the gate itself answers correctly.
 * It does not prove anyone asks it. That is the gap this file covers, and it
 * is not a hypothetical one: the workflow board moved a case by writing
 * `case.status` through `saveObject('case', ...)`, which reaches neither the
 * gate nor the dispatcher, so a card dragged across the board was moved by a
 * user the transition did not authorise and sent none of the emails and
 * notifications the transition configures. A gate that is never called is a
 * gate that is not there (OWASP A01:2021), and an action that never fires
 * leaves the case in a state the workflow says should have produced one.
 *
 * So this file exercises `execute()` end to end with a REAL
 * `TransitionAuthorizer` and a REAL `TransitionSpecReader` behind it, and asks
 * both questions of the one call:
 *
 *   - a user outside the transition's `authorization` list is refused, and the
 *     case is never saved;
 *   - a user inside it succeeds, and the transition's configured actions are
 *     dispatched with the case and the transition's context.
 *
 * WHICH IDENTITY THIS RUNS AS. Deliberately an explicit, ordinary uid, never
 * the session default. `IUserSession` is mocked to return no user at all, so
 * an accidental fallback to the session would produce the anonymous uid `''`
 * — and `''` is refused by the gate for its own unrelated reason (an
 * anonymous caller can never satisfy a group), which would make the denial
 * assertion pass while proving nothing about roles. The uid is therefore
 * passed explicitly on every call, and the permitted case asserts a real
 * member getting through, which `''` never could.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
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
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The role check and the side effects, asked of one execute() call.
 *
 * @covers \OCA\Dossiq\Service\StatusTransitionService
 * @covers \OCA\Dossiq\Service\Transitions\TransitionAuthorizer
 *
 * @spec openspec/specs/status-transition-engine/spec.md#requirement-transition-execution
 */
class StatusTransitionServiceEnforcementTest extends TestCase {

	/**
	 * The NC group the seeded transition is gated on.
	 */
	private const GATED_GROUP = 'behandelaars';

	/**
	 * A member of that group.
	 */
	private const MEMBER = 'sanne';

	/**
	 * A signed-in user who is not a member, and not an admin.
	 */
	private const OUTSIDER = 'joris';

	/**
	 * The store the engine reads and writes through.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $store;

	/**
	 * The collaborator that runs a transition's configured actions.
	 *
	 * @var SideEffectDispatcher&MockObject
	 */
	private SideEffectDispatcher $dispatcher;

	/**
	 * The service under test.
	 *
	 * @var StatusTransitionService
	 */
	private StatusTransitionService $service;

	/**
	 * Wire the engine onto a case with one gated, action-carrying transition.
	 *
	 * The transition moves between two NON-FINAL statuses on purpose: that is
	 * the only kind of move the workflow board can make, since the board draws
	 * a column per non-final status type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(CaseStatusStore::class);
		$this->dispatcher = $this->createMock(SideEffectDispatcher::class);

		$case = [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'status' => 'st-received',
			'@self' => ['version' => 3],
		];
		$this->store->method('loadCase')->willReturn($case);
		$this->store->method('lookupStatusName')->willReturn('In behandeling');
		$this->store->method('writeStatusRecord')->willReturn(['id' => 'rec-1']);
		$this->store->method('updateStatusRecord')->willReturnArgument(0);
		$this->store->method('saveCase')->willReturnArgument(0);

		$templateLoader = $this->createMock(WorkflowTemplateLoader::class);
		$templateLoader->method('getTransitionForCase')->willReturn(
			[
				'id' => 't1',
				'label' => 'Start behandeling',
				'fromStatus' => 'st-received',
				'toStatus' => 'st-progress',
				'guards' => [],
				// Frozen onto the transition at publish time by
				// WorkflowDefinitionService, from roleType.ncGroupId.
				'authorization' => [self::GATED_GROUP],
				'automaticActions' => [
					['type' => 'sendEmail', 'template' => 'in-behandeling'],
					['type' => 'notify', 'target' => 'requester'],
				],
			]
		);

		$guardRegistry = $this->createMock(GuardRegistry::class);
		$guardRegistry->method('evaluateAll')->willReturn([]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => (
				$uid === self::MEMBER && $gid === self::GATED_GROUP
			)
		);

		$resultWriter = $this->createMock(CaseResultWriter::class);
		$resultWriter->method('isFinalStatus')->willReturn(false);

		$checklist = $this->createMock(StatusChecklist::class);
		$checklist->method('actionsFor')->willReturn([]);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->service = new StatusTransitionService(
			templateLoader: $templateLoader,
			guardRegistry: $guardRegistry,
			sideEffectDispatcher: $this->dispatcher,
			store: $this->store,
			// The REAL gate, over a mocked membership lookup. A mocked
			// authorizer answering `true` is the shape that let the board's
			// bypass go unnoticed for as long as it did.
			authorizer: new TransitionAuthorizer(
				$groupManager,
				$this->createMock(LoggerInterface::class),
			),
			specReader: new TransitionSpecReader(),
			userSession: $userSession,
			logger: $this->createMock(LoggerInterface::class),
			resultWriter: $resultWriter,
			statusChecklist: $checklist,
		);
	}//end setUp()

	/**
	 * A signed-in user outside the transition's group cannot move the case.
	 *
	 * The assertion that carries the requirement is `saveCase` never being
	 * reached: a refusal that still wrote the status would be a refusal in
	 * name only.
	 *
	 * @return void
	 */
	public function testOutsiderIsRefusedAndTheCaseIsNotSaved(): void {
		$this->store->expects($this->never())->method('saveCase');
		$this->dispatcher->expects($this->never())->method('dispatch');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('transition_unauthorized');

		$this->service->execute(
			caseId: 'case-1',
			transitionId: 't1',
			comment: null,
			userId: self::OUTSIDER,
		);
	}//end testOutsiderIsRefusedAndTheCaseIsNotSaved()

	/**
	 * A member moves the case, and the transition's actions run.
	 *
	 * Both halves matter. A move that skipped its actions would leave the case
	 * in a status the workflow says sends an email and a notification, with
	 * neither sent and nothing recording that they were not.
	 *
	 * @return void
	 */
	public function testMemberMovesTheCaseAndItsActionsAreDispatched(): void {
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->with(
				$this->equalTo(
					[
						['type' => 'sendEmail', 'template' => 'in-behandeling'],
						['type' => 'notify', 'target' => 'requester'],
					]
				),
				$this->anything(),
				$this->callback(
					static fn (array $context): bool => (
						($context['fromStatus'] ?? null) === 'st-received'
						&& ($context['toStatus'] ?? null) === 'st-progress'
						&& ($context['userId'] ?? null) === self::MEMBER
					)
				),
			)
			->willReturn(
				[
					['type' => 'sendEmail', 'status' => 'ok'],
					['type' => 'notify', 'status' => 'ok'],
				]
			);

		$this->store->expects($this->once())
			->method('saveCase')
			->with($this->callback(
				static fn (array $case): bool => ($case['status'] ?? null) === 'st-progress'
			))
			->willReturnArgument(0);

		$result = $this->service->execute(
			caseId: 'case-1',
			transitionId: 't1',
			comment: null,
			userId: self::MEMBER,
		);

		$this->assertSame('ok', $result['status']);
		$this->assertCount(2, $result['dispatchedActions']);
	}//end testMemberMovesTheCaseAndItsActionsAreDispatched()
}//end class
