<?php

/**
 * ExternalInspectionRunController tests: stored state decides who may assign, before the body is read.
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
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use LogicException;
use OCA\Dossiq\Controller\ExternalInspectionRunController;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\ExternalInspectionRunInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see ExternalInspectionRunController}.
 *
 * @covers \OCA\Dossiq\Controller\ExternalInspectionRunController
 */
final class ExternalInspectionRunControllerTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The case guard.
	 *
	 * @var CaseAccessGuardInterface&MockObject
	 */
	private CaseAccessGuardInterface $guard;

	/**
	 * The external runs.
	 *
	 * @var ExternalInspectionRunInterface&MockObject
	 */
	private ExternalInspectionRunInterface $runs;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->guard = $this->createMock(CaseAccessGuardInterface::class);
		$this->runs = $this->createMock(ExternalInspectionRunInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Nobody signed in is refused outright.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->runs->expects($this->never())->method('assign');

		$this->expectException(OCSForbiddenException::class);
		$this->controller()->assign(id: 'case-1');
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A caller without mutation access gets a 403, and the body is never read.
	 *
	 * @return void
	 */
	public function testACallerWithoutAccessIsForbiddenBeforeTheBodyIsRead(): void {
		$user = $this->signedIn();
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('case-1', $user)->willReturn(false);
		$this->request->expects($this->never())->method('getParams');
		$this->runs->expects($this->never())->method('assign');

		$response = $this->controller()->assign(id: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testACallerWithoutAccessIsForbiddenBeforeTheBodyIsRead()

	/**
	 * Without a checklist or an inspector reference the request is a 400.
	 *
	 * @return void
	 */
	public function testAMissingChecklistOrInspectorIsABadRequest(): void {
		$this->signedIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(['checklistId' => 't-1'], ['inspectorRef' => 'sub-42'], ['checklistId' => ['t-1'], 'inspectorRef' => 'sub-42']);
		$this->runs->expects($this->never())->method('assign');

		for ($i = 0; $i < 3; $i++) {
			$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->assign(id: 'case-1')->getStatus());
		}
	}//end testAMissingChecklistOrInspectorIsABadRequest()

	/**
	 * A permitted caller assigns the run as themselves and gets it back as a 201.
	 *
	 * @return void
	 */
	public function testAPermittedCallerAssignsTheRun(): void {
		$this->signedIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$params = ['checklistId' => 't-1', 'inspectorRef' => 'sub-42', 'dueAt' => '2026-12-01'];
		$this->request->method('getParams')->willReturn($params);
		$this->runs->expects($this->once())->method('assign')
			->with('case-1', 't-1', 'sub-42', $params, 'behandelaar')
			->willReturn(['id' => 'task-9', 'state' => 'active']);

		$response = $this->controller()->assign(id: 'case-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'task-9', 'state' => 'active'], $response->getData());
	}//end testAPermittedCallerAssignsTheRun()

	/**
	 * A refused assignment is a 422 with its message; a fault is a logged 500 without one.
	 *
	 * @return void
	 */
	public function testFailuresAreAnswered(): void {
		$this->signedIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParams')->willReturn(['checklistId' => 't-1', 'inspectorRef' => 'sub-42']);
		$this->runs->method('assign')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('Unknown checklist template: t-1')),
			$this->throwException(new LogicException('boom'))
		);
		$this->logger->expects($this->once())->method('error');

		$refused = $this->controller()->assign(id: 'case-1');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame(['message' => 'Unknown checklist template: t-1'], $refused->getData());

		$fault = $this->controller()->assign(id: 'case-1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $fault->getStatus());
		$this->assertSame(['message' => 'Assigning the run failed'], $fault->getData());
	}//end testFailuresAreAnswered()

	/**
	 * Sign in user `behandelaar`.
	 *
	 * @return IUser The user.
	 */
	private function signedIn(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->session->method('getUser')->willReturn($user);

		return $user;
	}//end signedIn()

	/**
	 * The controller under test.
	 *
	 * @return ExternalInspectionRunController The controller.
	 */
	private function controller(): ExternalInspectionRunController {
		return new ExternalInspectionRunController('dossiq', $this->request, $this->session, $this->logger, $this->guard, $this->runs);
	}//end controller()
}//end class
