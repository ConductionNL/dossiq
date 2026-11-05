<?php

/**
 * CaseTaskCancelController and the completion notice of CaseTaskActions.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseTaskCancelController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCA\Dossiq\Service\Task\CaseTaskActions;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\TaskEffects;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\CaseTaskCancelController
 * @covers \OCA\Dossiq\Service\Task\CaseTaskActions
 * @covers \OCA\Dossiq\Service\Notice\CaseActNotices
 */
class CaseTaskCancelControllerTest extends TestCase {

	/**
	 * The engine gateway mock.
	 *
	 * @var EngineTaskGateway|MockObject
	 */
	private EngineTaskGateway $engine;

	/**
	 * The case guard mock.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The real task actions over the mocked engine.
	 *
	 * @var CaseTaskActions
	 */
	private CaseTaskActions $actions;

	/**
	 * Build the actions over a mocked engine with a task on case-7.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$effects = $this->createMock(TaskEffects::class);
		$effects->method('declaredOn')->willReturn([]);
		$effects->method('unresolved')->willReturn([]);
		$this->engine = $this->createMock(EngineTaskGateway::class);
		$this->engine->method('find')->willReturn(['id' => 'task-1', 'objectUuid' => 'case-7', 'title' => 'Documenten beoordelen']);
		$this->guard   = $this->createMock(CaseAccessGuard::class);
		$this->actions = new CaseTaskActions(engineTasks: $this->engine, effects: $effects, notices: new CaseActNotices(l10n: $l10n));
	}//end setUp()

	/**
	 * The controller for user `behandelaar`, with an optional reason in the request.
	 *
	 * @param string $reason The reason parameter.
	 *
	 * @return CaseTaskCancelController
	 */
	private function controller(string $reason = ''): CaseTaskCancelController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($key === 'reason') ? $reason : $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new CaseTaskCancelController(
			request: $request,
			tasks: $this->actions,
			caseAccess: $this->guard,
			notices: new CaseActNotices(l10n: $l10n),
			userSession: $session,
		);
	}//end controller()

	/**
	 * A completion answers with a success notice naming the task.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function testACompletionCarriesASuccessNotice(): void {
		$this->engine->method('complete')->willReturn(true);

		$answer = $this->actions->complete(taskId: 'task-1', data: [], outcome: 'done', actor: 'behandelaar');

		self::assertSame(['variant' => 'success', 'role' => 'status', 'title' => 'Task Documenten beoordelen completed.', 'text' => 'You stay on the case; the list is updated.'], $answer['notice']);
	}//end testACompletionCarriesASuccessNotice()

	/**
	 * A cancel the engine refuses is a 409 whose notice carries the engine's reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function testARefusedCancelAnswersWithTheEnginesReason(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->engine->method('cancel')->willReturn(false);
		$this->engine->method('lastError')->willReturn('Only the requester or an administrator may cancel this task.');

		$response = $this->controller()->cancel(taskId: 'task-1');
		$body     = $response->getData();

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('cancel_refused', $body['error']);
		self::assertSame(['variant' => 'error', 'role' => 'alert', 'title' => 'Cancelling refused for Documenten beoordelen:', 'text' => 'Only the requester or an administrator may cancel this task.'], $body['notice']);
	}//end testARefusedCancelAnswersWithTheEnginesReason()

	/**
	 * Without write access on the task's case the engine is not asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function testCancelWithoutWriteAccessIsRefusedBeforeTheEngine(): void {
		$this->guard->method('hasCaseMutationAccess')->with('case-7')->willReturn(false);
		$this->engine->expects($this->never())->method('cancel');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->cancel(taskId: 'task-1')->getStatus());
	}//end testCancelWithoutWriteAccessIsRefusedBeforeTheEngine()

	/**
	 * An accepted cancel passes the reason and actor to the engine.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function testAnAcceptedCancelPassesTheReason(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->engine->expects($this->once())->method('cancel')->with('task-1', 'Dubbel aangemaakt', 'behandelaar')->willReturn(true);

		$response = $this->controller(reason: 'Dubbel aangemaakt')->cancel(taskId: 'task-1');

		self::assertSame(['cancelled' => true, 'task' => 'task-1'], $response->getData());
	}//end testAnAcceptedCancelPassesTheReason()
}//end class
