<?php

/**
 * `GET /api/cases/{caseId}/sessions` answers a reader of the case and
 * `POST` plans a declared session for someone who may change it; both refuse
 * anyone else before the sessions are read or written.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\CaseSessionController;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\SessionServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-CSS-1 and REQ-CSS-2 behind the case page's Plan a session action.
 *
 * @covers \OCA\Dossiq\Controller\CaseSessionController
 */
class CaseSessionControllerTest extends TestCase {

	/** @var SessionServiceInterface&MockObject */
	private SessionServiceInterface $sessions;

	/** @var CaseAccessGuardInterface&MockObject */
	private CaseAccessGuardInterface $caseAccess;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->sessions   = $this->createMock(SessionServiceInterface::class);
		$this->caseAccess = $this->createMock(CaseAccessGuardInterface::class);
		$this->session    = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * A reader of the case gets the declared sessions and the ones it holds.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheSessions(): void {
		$answer = ['definitions' => [['key' => 'hoorgesprek', 'label' => 'Hoorgesprek']], 'sessions' => []];
		$this->caseAccess->method('hasCaseReadAccess')->with('case-1', $this->anything())->willReturn(true);
		$this->sessions->expects($this->once())->method('sessionsFor')->with('case-1')->willReturn($answer);

		$response = $this->controller()->index(caseId: ' case-1 ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testAReaderGetsTheSessions()

	/**
	 * Someone who may not read the case is refused and nothing is read.
	 *
	 * @return void
	 */
	public function testANonReaderIsRefusedBeforeAnythingIsRead(): void {
		$this->caseAccess->method('hasCaseReadAccess')->willReturn(false);
		$this->sessions->expects($this->never())->method('sessionsFor');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index(caseId: 'case-1')->getStatus());
	}//end testANonReaderIsRefusedBeforeAnythingIsRead()

	/**
	 * A failing read answers 503 with a sentence.
	 *
	 * @return void
	 */
	public function testAFailingReadAnswersUnavailable(): void {
		$this->caseAccess->method('hasCaseReadAccess')->willReturn(true);
		$this->sessions->method('sessionsFor')->willThrowException(new RuntimeException('down'));

		$response = $this->controller()->index(caseId: 'case-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('The sessions of this case could not be read. Try again later.', $response->getData()['message']);
	}//end testAFailingReadAnswersUnavailable()

	/**
	 * A handler plans a declared session; the caller chairs it and the stored session comes back.
	 *
	 * @return void
	 */
	public function testAHandlerPlansASessionAndChairsIt(): void {
		$this->params = [
			'sessionKey'    => ' hoorgesprek ',
			'scheduledDate' => '2026-12-10T10:00:00+01:00',
			'invitees'      => [['role' => 'Klager', 'name' => 'Sanne de Vries']],
			'payload'       => ['location' => 'Stadhuis, kamer 2'],
		];
		$this->caseAccess->method('hasCaseMutationAccess')->with('case-1', $this->anything())->willReturn(true);
		$this->sessions->expects($this->once())->method('schedule')->with(
			'case-1',
			'hoorgesprek',
			'2026-12-10T10:00:00+01:00',
			'pieter',
			[['role' => 'Klager', 'name' => 'Sanne de Vries']],
			['location' => 'Stadhuis, kamer 2'],
		)->willReturn(['id' => 'session-1', 'status' => 'planned']);

		$response = $this->controller()->create(caseId: 'case-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'session-1', 'status' => 'planned'], $response->getData());
	}//end testAHandlerPlansASessionAndChairsIt()

	/**
	 * A chairperson named in the body chairs the session.
	 *
	 * @return void
	 */
	public function testANamedChairpersonChairsTheSession(): void {
		$this->params = ['sessionKey' => 'hoorgesprek', 'scheduledDate' => '2026-12-10T10:00:00+01:00', 'chairpersonId' => 'fatma'];
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(true);
		$this->sessions->expects($this->once())->method('schedule')
			->with('case-1', 'hoorgesprek', '2026-12-10T10:00:00+01:00', 'fatma', [], [])
			->willReturn(['id' => 'session-1']);

		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create(caseId: 'case-1')->getStatus());
	}//end testANamedChairpersonChairsTheSession()

	/**
	 * Someone who may not change the case is refused and nothing is planned.
	 *
	 * @return void
	 */
	public function testANonHandlerIsRefusedBeforeAnythingIsPlanned(): void {
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(false);
		$this->sessions->expects($this->never())->method('schedule');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->create(caseId: 'case-1')->getStatus());
	}//end testANonHandlerIsRefusedBeforeAnythingIsPlanned()

	/**
	 * Without a signed-in user both calls are refused.
	 *
	 * @return void
	 */
	public function testWithoutAUserBothCallsAreRefused(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);
		$this->sessions->expects($this->never())->method('sessionsFor');
		$this->sessions->expects($this->never())->method('schedule');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index(caseId: 'case-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->create(caseId: 'case-1')->getStatus());
	}//end testWithoutAUserBothCallsAreRefused()

	/**
	 * A plan the session's rules refuse answers 400 with the rule's sentence.
	 *
	 * @return void
	 */
	public function testARefusedPlanAnswersItsRule(): void {
		$this->params = ['sessionKey' => 'hoorgesprek', 'scheduledDate' => '2026-11-26T10:00:00+01:00'];
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(true);
		$this->sessions->method('schedule')->willThrowException(new InvalidArgumentException('Hoorgesprek needs at least 1 invitee(s).'));

		$response = $this->controller()->create(caseId: 'case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Hoorgesprek needs at least 1 invitee(s).', $response->getData()['message']);
	}//end testARefusedPlanAnswersItsRule()

	/**
	 * Invitees that are not a list are refused before the service is asked.
	 *
	 * @return void
	 */
	public function testInviteesThatAreNotAListAreRefused(): void {
		$this->params = ['sessionKey' => 'hoorgesprek', 'invitees' => 'Klager'];
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(true);
		$this->sessions->expects($this->never())->method('schedule');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->create(caseId: 'case-1')->getStatus());
	}//end testInviteesThatAreNotAListAreRefused()

	/**
	 * A register that refuses the write answers 503 with a sentence.
	 *
	 * @return void
	 */
	public function testAFailingWriteAnswersUnavailable(): void {
		$this->params = ['sessionKey' => 'hoorgesprek', 'scheduledDate' => '2026-12-10T10:00:00+01:00'];
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(true);
		$this->sessions->method('schedule')->willThrowException(new RuntimeException('down'));

		$response = $this->controller()->create(caseId: 'case-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('The session could not be planned. Try again later.', $response->getData()['message']);
	}//end testAFailingWriteAnswersUnavailable()

	/**
	 * The controller over the doubles, its request answering the test's params.
	 *
	 * @return CaseSessionController
	 */
	private function controller(): CaseSessionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		return new CaseSessionController(
			appName: 'dossiq',
			request: $request,
			sessions: $this->sessions,
			userSession: $this->session,
			caseAccess: $this->caseAccess,
		);
	}//end controller()
}//end class
