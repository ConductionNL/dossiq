<?php

/**
 * The createTask tool creates a task on a case the caller may change, as the caller.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Mcp\TaskTools;
use OCA\Dossiq\Service\Transitions\ActionResult;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Refused without the right to change the case, never acting as anyone else, and only on a case the caller reads.
 *
 * @covers \OCA\Dossiq\Service\Mcp\TaskTools
 * @uses   \OCA\Dossiq\Service\Transitions\ActionResult
 */
final class TaskToolsTest extends TestCase {

	/**
	 * What the task handler was asked: action, case and context.
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $handled = [];

	/**
	 * The tool for a caller.
	 *
	 * @param string|null $uid      The signed-in caller, or null for no session.
	 * @param bool        $mayEdit  Whether the caller may change the case.
	 * @param bool        $readable Whether the caller's own read finds the case.
	 * @param bool        $writes   Whether the task write succeeds.
	 *
	 * @return TaskTools
	 */
	private function tools(?string $uid = 'bob', bool $mayEdit = true, bool $readable = true, bool $writes = true): TaskTools {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$cases = $this->createMock(originalClassName: CaseStatusStore::class);
		$cases->method('loadCase')->willReturnCallback(
			static fn (string $caseId): ?array => (($readable === true && $caseId === 'case-1') ? ['id' => 'case-1', 'assignee' => 'bob'] : null)
		);

		$access = $this->createMock(originalClassName: CaseAccessGuard::class);
		$access->method('hasCaseMutationAccess')->willReturnCallback(
			static fn (string $caseId, IUser $caller): bool => ($mayEdit === true && $caller->getUID() === $uid && $caseId === 'case-1')
		);

		$handler = $this->createMock(originalClassName: CreateTaskHandler::class);
		$handler->method('handle')->willReturnCallback(
			function (array $actionConfig, array $case, array $transitionContext) use ($writes): ActionResult {
				$this->handled[] = [$actionConfig, $case, $transitionContext];
				if ($writes === false) {
					return new ActionResult(succeeded: false, error: 'create_task_failed');
				}

				return new ActionResult(succeeded: true, data: ['taskId' => 'task-9']);
			}
		);

		return new TaskTools(userSession: $session, caseAccess: $access, handler: $handler, cases: $cases);
	}//end tools()

	/**
	 * The caller's task lands on the case they read, created as them, with only the fields they may set.
	 *
	 * @return void
	 */
	public function testTheTaskIsCreatedOnTheCaseAsTheCaller(): void {
		$answer = $this->tools()->createTask(caseId: ' case-1 ', title: '  Bel de aanvrager  ', leadTimeDays: 3, for: 'me');

		self::assertSame(expected: ['taskId' => 'task-9'], actual: $answer);
		self::assertCount(expectedCount: 1, haystack: $this->handled);
		[$action, $case, $context] = $this->handled[0];
		self::assertSame(
			expected: ['type' => 'createTask', 'title' => 'Bel de aanvrager', 'assignee' => 'bob', 'declaration' => ['leadTimeDays' => 3]],
			actual: $action
		);
		self::assertSame(expected: 'case-1', actual: $case['id']);
		self::assertSame(expected: ['userId' => 'bob', 'transitionLabel' => ''], actual: $context);
	}//end testTheTaskIsCreatedOnTheCaseAsTheCaller()

	/**
	 * For the handler the task names no assignee, so the handler gives it to the case's handler.
	 *
	 * @return void
	 */
	public function testForTheHandlerTheTaskNamesNoAssignee(): void {
		$this->tools()->createTask(caseId: 'case-1', title: 'Controleer', leadTimeDays: 0);

		self::assertSame(expected: ['type' => 'createTask', 'title' => 'Controleer'], actual: $this->handled[0][0]);
	}//end testForTheHandlerTheTaskNamesNoAssignee()

	/**
	 * A caller without the right to change the case is refused, and no task is created.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotChangeTheCaseIsRefused(): void {
		$answer = $this->tools(mayEdit: false)->createTask(caseId: 'case-1', title: 'Controleer');

		self::assertSame(expected: 'forbidden', actual: $answer['error']);
		self::assertSame(expected: [], actual: $this->handled);
	}//end testACallerWhoMayNotChangeTheCaseIsRefused()

	/**
	 * A case the caller's own read does not find answers not found, even when the change check would pass.
	 *
	 * @return void
	 */
	public function testACaseTheCallerCannotReadIsNotFound(): void {
		$answer = $this->tools(readable: false)->createTask(caseId: 'case-1', title: 'Controleer');

		self::assertSame(expected: 'not_found', actual: $answer['error']);
		self::assertSame(expected: [], actual: $this->handled);
	}//end testACaseTheCallerCannotReadIsNotFound()

	/**
	 * No session, an empty title and a task for someone else are refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testNoSessionNoTitleOrSomeoneElseAreRefused(): void {
		self::assertSame(expected: 'not_authenticated', actual: $this->tools(uid: null)->createTask(caseId: 'case-1', title: 'X')['error']);
		self::assertSame(expected: 'title_required', actual: $this->tools()->createTask(caseId: 'case-1', title: '  ')['error']);
		self::assertSame(expected: 'for_unknown', actual: $this->tools()->createTask(caseId: 'case-1', title: 'X', for: 'carla')['error']);
		self::assertSame(expected: [], actual: $this->handled);
	}//end testNoSessionNoTitleOrSomeoneElseAreRefused()

	/**
	 * The tool takes only the fields a user may set: no id, no @self, no other assignee, no organisation.
	 *
	 * OpenRegister passes a tool only the arguments its signature names, so a forged key never arrives.
	 *
	 * @return void
	 */
	public function testTheToolTakesOnlyTheFieldsAUserMaySet(): void {
		$names = array_map(
			static fn (\ReflectionParameter $param): string => $param->getName(),
			(new ReflectionMethod(objectOrMethod: TaskTools::class, method: 'createTask'))->getParameters()
		);

		self::assertSame(expected: ['caseId', 'title', 'leadTimeDays', 'for'], actual: $names);
	}//end testTheToolTakesOnlyTheFieldsAUserMaySet()

	/**
	 * A refused task write is reported, not reported as done.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReported(): void {
		$answer = $this->tools(writes: false)->createTask(caseId: 'case-1', title: 'Controleer');

		self::assertSame(expected: 'create_task_failed', actual: $answer['error']);
	}//end testAFailedWriteIsReported()
}//end class
