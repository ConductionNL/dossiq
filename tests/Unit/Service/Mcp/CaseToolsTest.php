<?php

/**
 * CaseTools checks the caller may read the case before it lists transitions.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseAssignmentService;
use OCA\Dossiq\Service\Mcp\CaseTools;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Task\CaseTaskActions;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The case-scoped curated tools.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */
class CaseToolsTest extends TestCase {

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Case access guard mock.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * Transition engine mock.
	 *
	 * @var StatusTransitionService&MockObject
	 */
	private StatusTransitionService $transitions;

	/**
	 * The subject.
	 *
	 * @var CaseTools
	 */
	private CaseTools $tools;

	private CaseAssignmentService&MockObject $assignment;

	private CaseTaskActions&MockObject $tasks;

	private IGroupManager&MockObject $groups;

	/**
	 * Build the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->transitions = $this->createMock(StatusTransitionService::class);
		$this->assignment = $this->createMock(CaseAssignmentService::class);
		$this->tasks = $this->createMock(CaseTaskActions::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->tools = new CaseTools(
			userSession: $this->session,
			caseAccess: $this->guard,
			transitions: $this->transitions,
			assignment: $this->assignment,
			tasks: $this->tasks,
			groupManager: $this->groups,
		);
	}//end setUp()

	/**
	 * Without a session nothing is read.
	 *
	 * @return void
	 */
	public function testNoSessionReadsNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->transitions->expects($this->never())->method('getAvailableTransitions');

		$this->assertSame('not_authenticated', $this->tools->listAvailableTransitions(caseId: 'c-1')['error']);
	}//end testNoSessionReadsNothing()

	/**
	 * A case the caller may not read does not even give away its status.
	 *
	 * @return void
	 */
	public function testACaseTheCallerMayNotReadIsRefused(): void {
		$user = $this->user(uid: 'henk');
		$this->guard->expects($this->once())->method('hasCaseReadAccess')->with('c-1', $user)->willReturn(false);
		$this->transitions->expects($this->never())->method('getAvailableTransitions');

		$this->assertSame('forbidden', $this->tools->listAvailableTransitions(caseId: 'c-1')['error']);
	}//end testACaseTheCallerMayNotReadIsRefused()

	/**
	 * A readable case lists the transitions the engine allows THIS caller.
	 *
	 * @return void
	 */
	public function testAReadableCaseListsTheCallersTransitions(): void {
		$this->user(uid: 'henk');
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$answer = ['transitions' => [['to' => 's-2']], 'current' => ['id' => 's-1']];
		$this->transitions->expects($this->once())->method('getAvailableTransitions')
			->with('c-1', 'henk')
			->willReturn($answer);

		$this->assertSame($answer, $this->tools->listAvailableTransitions(caseId: 'c-1'));
	}//end testAReadableCaseListsTheCallersTransitions()

	/**
	 * Put a user in the session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		return $user;
	}//end user()

	// ── transitionCase (REQ-MCP-204) ─────────────────────────────────

	public function testTransitionOnACaseTheCallerMayNotChangeIsRefusedBeforeTheEngine(): void {
		$user = $this->user(uid: 'henk');
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('c-1', $user)->willReturn(false);
		$this->transitions->expects($this->never())->method('execute');

		$this->assertSame('forbidden', $this->tools->transitionCase(caseId: 'c-1', transitionId: 't-1')['error']);
	}//end testTransitionOnACaseTheCallerMayNotChangeIsRefusedBeforeTheEngine()

	public function testTransitionRunsTheEngineAsTheCaller(): void {
		$this->user(uid: 'henk');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$answer = ['status' => 'ok', 'statusRecord' => ['id' => 'sr-1'], 'dispatchedActions' => [], 'failedActions' => [], 'version' => 4];
		$this->transitions->expects($this->once())->method('execute')
			->with('c-1', 't-close', null, 'henk', 'rt-granted')
			->willReturn($answer);

		$this->assertSame($answer, $this->tools->transitionCase(caseId: 'c-1', transitionId: 't-close', resultTypeId: 'rt-granted'));
	}//end testTransitionRunsTheEngineAsTheCaller()

	public function testAFailedGuardComesBackAsAnEnvelope(): void {
		$this->user(uid: 'henk');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->transitions->method('execute')->willThrowException(new GuardFailedException([['guard' => 'documents-complete', 'passed' => false]]));

		$result = $this->tools->transitionCase(caseId: 'c-1', transitionId: 't-1');

		$this->assertSame('transition-guard-failed', $result['error']);
		$this->assertSame('documents-complete', $result['failedGuards'][0]['guard']);
	}//end testAFailedGuardComesBackAsAnEnvelope()

	public function testARefusalCarriesItsRuleAndSentence(): void {
		$this->user(uid: 'henk');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->transitions->method('execute')->willThrowException(new RefusedException(rule: 'transition-unauthorized', sentence: 'You may not make this move.'));

		$this->assertSame(
			['error' => 'transition-unauthorized', 'message' => 'You may not make this move.'],
			$this->tools->transitionCase(caseId: 'c-1', transitionId: 't-1')
		);
	}//end testARefusalCarriesItsRuleAndSentence()

	// ── reassignCase ─────────────────────────────────────────────────

	public function testOnlyACoordinatorMayReassign(): void {
		$this->user(uid: 'henk');
		$this->groups->method('isAdmin')->with('henk')->willReturn(false);
		$this->assignment->expects($this->never())->method('reassign');

		$this->assertSame('forbidden', $this->tools->reassignCase(caseId: 'c-1', toUser: 'fatima')['error']);
	}//end testOnlyACoordinatorMayReassign()

	public function testACoordinatorReassignsThroughTheAssignmentService(): void {
		$this->user(uid: 'coordinator');
		$this->groups->method('isAdmin')->willReturn(true);
		$this->assignment->expects($this->once())->method('reassign')
			->with('c-1', 'fatima', 'coordinator')
			->willReturn(['caseId' => 'c-1', 'assignee' => 'fatima', 'previousAssignee' => 'henk']);

		$this->assertSame('fatima', $this->tools->reassignCase(caseId: 'c-1', toUser: 'fatima')['assignee']);
	}//end testACoordinatorReassignsThroughTheAssignmentService()

	public function testAReassignRefusalIsAnEnvelope(): void {
		$this->user(uid: 'coordinator');
		$this->groups->method('isAdmin')->willReturn(true);
		$this->assignment->method('reassign')->willThrowException(new \RuntimeException('already_assigned'));

		$this->assertSame(['error' => 'refused', 'message' => 'already_assigned'], $this->tools->reassignCase(caseId: 'c-1', toUser: 'henk'));
	}//end testAReassignRefusalIsAnEnvelope()

	// ── completeTask ─────────────────────────────────────────────────

	public function testCompletingATaskOnACaseTheCallerMayNotChangeIsRefused(): void {
		$user = $this->user(uid: 'henk');
		$this->tasks->method('find')->willReturn(['id' => 'task-1', 'objectUuid' => 'c-1']);
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('c-1', $user)->willReturn(false);
		$this->tasks->expects($this->never())->method('complete');

		$this->assertSame('forbidden', $this->tools->completeTask(taskId: 'task-1')['error']);
	}//end testCompletingATaskOnACaseTheCallerMayNotChangeIsRefused()

	public function testATaskCompletesThroughTheEngineVerb(): void {
		$this->user(uid: 'henk');
		$this->tasks->method('find')->willReturn(['id' => 'task-1', 'objectUuid' => 'c-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->tasks->expects($this->once())->method('complete')
			->with('task-1', [], 'done', 'henk')
			->willReturn(['id' => 'task-1', 'status' => 'completed']);

		$this->assertSame('completed', $this->tools->completeTask(taskId: 'task-1', outcome: ' ')['status']);
	}//end testATaskCompletesThroughTheEngineVerb()

	public function testAMissingTaskOrADownEngineIsSaidPlainly(): void {
		$this->user(uid: 'henk');
		$this->tasks->method('find')->willReturnOnConsecutiveCalls(null, $this->throwException(new \RuntimeException('db down')));

		$this->assertSame('task_not_found', $this->tools->completeTask(taskId: 'task-404')['error']);
		$this->assertSame('storage_unavailable', $this->tools->completeTask(taskId: 'task-1')['error']);
	}//end testAMissingTaskOrADownEngineIsSaidPlainly()

	public function testARequiredFieldIsNamedBack(): void {
		$this->user(uid: 'henk');
		$this->tasks->method('find')->willReturn(['id' => 'task-1', 'objectUuid' => 'c-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->tasks->method('complete')->willThrowException(new \RuntimeException('required_field:besluitDatum'));

		$this->assertSame('required_field:besluitDatum', $this->tools->completeTask(taskId: 'task-1')['message']);
	}//end testARequiredFieldIsNamedBack()
}//end class
