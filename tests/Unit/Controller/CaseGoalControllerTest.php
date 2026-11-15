<?php

/**
 * CaseGoalController Unit Tests
 *
 * The goals of a case are read only by someone who may read the case and
 * written only by someone who may update it, and the check runs before the
 * store is touched. The read also carries the case type's prompt for the empty
 * goals row.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/changes/case-goals/specs/case-goals/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseGoalController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\SociaalDomein\CasePlanGoals;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CaseGoalController.
 *
 * @covers \OCA\Dossiq\Controller\CaseGoalController
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseGoalControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var CasePlanGoals&MockObject
	 */
	private CasePlanGoals $goals;

	/**
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * @var PauseReasonReader&MockObject
	 */
	private PauseReasonReader $caseTypes;

	/**
	 * The controller under test.
	 *
	 * @var CaseGoalController
	 */
	private CaseGoalController $controller;

	/**
	 * A signed-in user and mocks for the rest.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->goals = $this->createMock(originalClassName: CasePlanGoals::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->caseTypes = $this->createMock(originalClassName: PauseReasonReader::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = new CaseGoalController(
			request: $this->request,
			userSession: $session,
			caseAccessGuard: $this->guard,
			goals: $this->goals,
			caseTypes: $this->caseTypes,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A reader of the case gets its goals and the case type's prompt.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheGoalsAndThePrompt(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->goals->method('ofCase')->with('case-1')->willReturn([['id' => 'g-1', 'title' => 'Iets']]);
		$this->caseTypes->method('caseTypeForCase')->willReturn(['goalPrompt' => 'What does the requester want to change?']);

		$response = $this->controller->index(caseId: 'case-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(
			expected: ['results' => [['id' => 'g-1', 'title' => 'Iets']], 'prompt' => 'What does the requester want to change?'],
			actual: $response->getData()
		);
	}//end testAReaderGetsTheGoalsAndThePrompt()

	/**
	 * 🔴 Someone who may not read the case gets 403 and no goal is read.
	 *
	 * @return void
	 */
	public function testANonReaderIsRefusedBeforeTheStore(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(false);
		$this->goals->expects(self::never())->method('ofCase');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->index(caseId: 'case-1')->getStatus());
	}//end testANonReaderIsRefusedBeforeTheStore()

	/**
	 * Saving stores the goal on the case from the URL, not from the body.
	 *
	 * @return void
	 */
	public function testAHandlerSavesAGoalOnTheCase(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['title', '', 'De verzoeker weet waarom'],
			['metWhen', '', 'Het besluit noemt de reden'],
			['case', '', 'other-case'],
		]);
		$this->goals->expects(self::once())->method('save')->with([
			'case' => 'case-1',
			'title' => 'De verzoeker weet waarom',
			'metWhen' => 'Het besluit noemt de reden',
			'state' => 'open',
		])->willReturn(['id' => 'g-2', 'case' => 'case-1']);

		$response = $this->controller->create(caseId: 'case-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['id' => 'g-2', 'case' => 'case-1'], actual: $response->getData());
	}//end testAHandlerSavesAGoalOnTheCase()

	/**
	 * 🔴 Someone who may not update the case cannot add a goal to it.
	 *
	 * @return void
	 */
	public function testANonHandlerCannotSave(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->goals->expects(self::never())->method('save');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->create(caseId: 'case-1')->getStatus());
	}//end testANonHandlerCannotSave()

	/**
	 * A refused goal answers 422 with the rule.
	 *
	 * @return void
	 */
	public function testARefusedGoalSaysWhy(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->goals->method('save')->willThrowException(
			new RefusedException(rule: 'goal-needs-a-title', sentence: 'Write down the goal itself.', status: RefusedException::STATUS_UNPROCESSABLE)
		);

		$response = $this->controller->create(caseId: 'case-1');

		self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		self::assertSame(expected: 'goal-needs-a-title', actual: $response->getData()['error']);
	}//end testARefusedGoalSaysWhy()

	/**
	 * A handler closes a goal of the case with the state and observation sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-goals/tasks.md#2.1
	 */
	public function testAHandlerClosesAGoalOfTheCase(): void {
		$this->guard->method('hasCaseMutationAccess')->with('case-1', self::anything())->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['state', '', 'met'],
			['observation', '', 'Het besluit noemt de reden'],
		]);
		$this->goals->expects(self::once())->method('closeOfCase')
			->with('case-1', 'g-1', 'met', 'Het besluit noemt de reden')
			->willReturn(['id' => 'g-1', 'state' => 'met']);

		$response = $this->controller->close(caseId: 'case-1', goalId: 'g-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['id' => 'g-1', 'state' => 'met'], actual: $response->getData());
	}//end testAHandlerClosesAGoalOfTheCase()

	/**
	 * 🔴 Someone who may not update the case cannot close its goal, and no goal is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-goals/tasks.md#2.1
	 */
	public function testANonHandlerCannotCloseAGoal(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->goals->expects(self::never())->method('closeOfCase');
		$this->goals->expects(self::never())->method('close');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->close(caseId: 'case-1', goalId: 'g-1')->getStatus());
	}//end testANonHandlerCannotCloseAGoal()

	/**
	 * A refused close answers 422 with the rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-goals/tasks.md#2.1
	 */
	public function testARefusedCloseSaysWhy(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->goals->method('closeOfCase')->willThrowException(
			new RefusedException(rule: 'goal-not-found', sentence: 'That goal could not be found.', status: RefusedException::STATUS_UNPROCESSABLE)
		);

		$response = $this->controller->close(caseId: 'case-1', goalId: 'g-other');

		self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		self::assertSame(expected: 'goal-not-found', actual: $response->getData()['error']);
	}//end testARefusedCloseSaysWhy()
}//end class
