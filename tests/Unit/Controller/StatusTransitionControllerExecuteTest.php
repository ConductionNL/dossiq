<?php

/**
 * StatusTransitionController execute characterisation.
 *
 * Pins every answer of the guarded transition endpoint, and the failure
 * answers of the free-form one: the 401 for a caller without a session, the
 * 400 without a transition, the values handed to the engine, the 409 with
 * the failed guards, the refusal, the engine's codes mapped to 404, 403 and
 * 400 with their log line, and the logged 500.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use LogicException;
use OCA\Dossiq\Controller\StatusTransitionController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The guarded transition endpoint, allowed and refused.
 *
 * @covers \OCA\Dossiq\Controller\StatusTransitionController
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Transitions\GuardFailedException
 */
class StatusTransitionControllerExecuteTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The engine.
	 *
	 * @var StatusTransitionService&MockObject
	 */
	private StatusTransitionService&MockObject $engine;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->engine = $this->createMock(StatusTransitionService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller, with a signed-in user and this body.
	 *
	 * @param array<string, mixed> $body     The request body.
	 * @param bool                 $signedIn Whether a user is signed in.
	 *
	 * @return StatusTransitionController
	 */
	private function controller(array $body, bool $signedIn = true): StatusTransitionController {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$this->session->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn($body);

		return new StatusTransitionController(
			appName: 'dossiq',
			request: $this->request,
			transitionEngine: $this->engine,
			userSession: $this->session,
			logger: $this->logger,
			caseAccessGuard: $this->createMock(CaseAccessGuard::class),
		);
	}//end controller()

	/**
	 * A caller without a session is refused with 401 before anything is read.
	 *
	 * @return void
	 */
	public function testACallerWithoutASessionIsUnauthorised(): void {
		$this->engine->expects($this->never())->method('execute');

		$response = $this->controller(['transitionId' => 't-1'], false)->execute('case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testACallerWithoutASessionIsUnauthorised()

	/**
	 * Without a transition the answer is 400 and the engine is not asked.
	 *
	 * @return void
	 */
	public function testWithoutATransitionTheAnswerIsBadRequest(): void {
		$this->engine->expects($this->never())->method('execute');

		$response = $this->controller(['comment' => 'x'])->execute('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'transitionId is required'], $response->getData());
	}//end testWithoutATransitionTheAnswerIsBadRequest()

	/**
	 * The engine gets the case, the transition, and the comment and result cast to strings.
	 *
	 * @return void
	 */
	public function testTheEngineGetsTheTransitionCommentAndResult(): void {
		$this->engine->expects($this->once())->method('execute')
			->with('case-1', '12', '34', null, '56')
			->willReturn(['status' => 'done']);

		$response = $this->controller(['transitionId' => 12, 'comment' => 34, 'resultTypeId' => 56])->execute('case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'done'], $response->getData());
	}//end testTheEngineGetsTheTransitionCommentAndResult()

	/**
	 * An absent comment and result reach the engine as null.
	 *
	 * @return void
	 */
	public function testAnAbsentCommentAndResultAreNull(): void {
		$this->engine->expects($this->once())->method('execute')->with('case-1', 't-1', null, null, null)->willReturn([]);

		$this->controller(['transitionId' => 't-1', 'comment' => null])->execute('case-1');
	}//end testAnAbsentCommentAndResultAreNull()

	/**
	 * A failed guard is a 409 naming the guards.
	 *
	 * @return void
	 */
	public function testAFailedGuardIsAConflict(): void {
		$this->engine->method('execute')->willThrowException(new GuardFailedException([['guard' => 'docs']], 'guard_docs'));

		$response = $this->controller(['transitionId' => 't-1'])->execute('case-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			[
				'message' => 'This move is blocked by a rule on the case.',
				'error' => 'transition-guard-failed',
				'code' => 'guard_docs',
				'failedGuards' => [['guard' => 'docs']],
			],
			$response->getData()
		);
	}//end testAFailedGuardIsAConflict()

	/**
	 * A refusal answers with its rule, sentence and status, and is logged.
	 *
	 * @return void
	 */
	public function testARefusalAnswersWithItsRule(): void {
		$this->engine->method('execute')->willThrowException(new RefusedException('case-held', 'The case is held.', 423));
		$this->logger->expects($this->once())->method('info')->with(
			'StatusTransitionController: execute refused',
			['rule' => 'case-held', 'status' => 423]
		);

		$response = $this->controller(['transitionId' => 't-1'])->execute('case-1');

		$this->assertSame(423, $response->getStatus());
	}//end testARefusalAnswersWithItsRule()

	/**
	 * The engine's codes for the guarded path.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function executeCodes(): array {
		return [
			'case not found' => ['case_not_found', Http::STATUS_NOT_FOUND],
			'transition not found' => ['transition_not_found', Http::STATUS_NOT_FOUND],
			'admin only' => ['forbidden_admin_only', Http::STATUS_FORBIDDEN],
			'anything else' => ['case_type_not_found', Http::STATUS_BAD_REQUEST],
		];
	}//end executeCodes()

	/**
	 * The engine's codes map to a status, with one log line.
	 *
	 * @param string $code   The engine's code.
	 * @param int    $status The status answered.
	 *
	 * @dataProvider executeCodes
	 *
	 * @return void
	 */
	public function testTheEnginesCodesMapToAStatus(string $code, int $status): void {
		$this->engine->method('execute')->willThrowException(new RuntimeException($code));
		$this->logger->expects($this->once())->method('info')->with('StatusTransitionController: execute rejected', ['code' => $code]);

		$response = $this->controller(['transitionId' => 't-1'])->execute('case-1');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['error' => 'Could not execute transition'], $response->getData());
	}//end testTheEnginesCodesMapToAStatus()

	/**
	 * Any other failure is a logged 500.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureIsALoggedServerError(): void {
		$this->engine->method('execute')->willThrowException(new LogicException('boom'));
		$this->logger->expects($this->once())->method('error')->with(
			'StatusTransitionController: execute failed',
			['exception' => 'boom', 'caseId' => 'case-1', 'transitionId' => 't-1']
		);

		$response = $this->controller(['transitionId' => 't-1'])->execute('case-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not execute transition'], $response->getData());
	}//end testAnyOtherFailureIsALoggedServerError()

	/**
	 * The free-form path's refusal, admin-only code and other failure.
	 *
	 * @return void
	 */
	public function testTheFreeFormFailures(): void {
		$this->engine->method('executeFreeForm')->willReturnOnConsecutiveCalls(
			$this->throwException(new RefusedException('lifecycle-closed', 'The case is closed.')),
			$this->throwException(new RuntimeException('case_type_not_found')),
			$this->throwException(new LogicException('boom'))
		);
		$this->logger->expects($this->once())->method('error')->with(
			'StatusTransitionController: freeform failed',
			['exception' => 'boom', 'caseId' => 'case-1']
		);
		$controller = $this->controller(['toStatusId' => 's-2']);

		$refused = $controller->freeform('case-1');
		$this->assertSame(RefusedException::STATUS_REFUSED, $refused->getStatus());

		$missing = $controller->freeform('case-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Could not execute free-form transition'], $missing->getData());

		$failed = $controller->freeform('case-1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'Could not execute free-form transition'], $failed->getData());
	}//end testTheFreeFormFailures()
}//end class
