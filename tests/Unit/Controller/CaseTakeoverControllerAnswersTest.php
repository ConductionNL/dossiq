<?php

/**
 * CaseTakeoverController unit tests: missing reasons, refusals and the per-case list.
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
 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseTakeoverController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTakeoverRequestInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The branches the access-focused CaseTakeoverControllerTest leaves out.
 *
 * @covers \OCA\Dossiq\Controller\CaseTakeoverController
 * @uses   \OCA\Dossiq\Controller\Support\CaseCaller
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class CaseTakeoverControllerAnswersTest extends TestCase {

	/**
	 * The takeover service double.
	 *
	 * @var CaseTakeoverRequestInterface&MockObject
	 */
	private CaseTakeoverRequestInterface $takeovers;

	/**
	 * The access guard double.
	 *
	 * @var CaseAccessGuardInterface&MockObject
	 */
	private CaseAccessGuardInterface $guard;

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var CaseTakeoverController
	 */
	private CaseTakeoverController $controller;

	/**
	 * A signed-in caller named sofie.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->takeovers = $this->createMock(CaseTakeoverRequestInterface::class);
		$this->guard = $this->createMock(CaseAccessGuardInterface::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sofie');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = new CaseTakeoverController(
			appName: 'dossiq',
			request: $this->request,
			takeovers: $this->takeovers,
			accessGuard: $this->guard,
			userSession: $session,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Without read access a caller may not even ask.
	 *
	 * @return void
	 */
	public function testAskWithoutReadAccessIsForbidden(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(false);
		$this->takeovers->expects($this->never())->method('request');

		$response = $this->controller->ask(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('case-access-denied', $response->getData()['error']);
	}//end testAskWithoutReadAccessIsForbidden()

	/**
	 * A blank reason (whitespace only) is a 400 and no request is made.
	 *
	 * @return void
	 */
	public function testAskWithABlankReasonIsABadRequest(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->request->method('getParam')->with('reason', '')->willReturn('   ');
		$this->takeovers->expects($this->never())->method('request');

		$response = $this->controller->ask(caseId: 'case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'Say why you should have the case.', 'error' => 'takeover-reason-missing'],
			$response->getData()
		);
	}//end testAskWithABlankReasonIsABadRequest()

	/**
	 * A refused ask keeps the refusal's status and rule and is logged with the ask op.
	 *
	 * @return void
	 */
	public function testAskRefusalIsTranslated(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->request->method('getParam')->willReturn('I hold the file now');
		$this->takeovers->expects($this->once())->method('request')
			->with('case-1', 'sofie', 'I hold the file now')
			->willThrowException(new RefusedException(rule: 'takeover-already-open', sentence: ''));
		$this->logger->expects($this->once())->method('info')
			->with('CaseTakeoverController: takeover ask refused', ['rule' => 'takeover-already-open', 'status' => 409]);

		$response = $this->controller->ask(caseId: 'case-1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('takeover-already-open', $response->getData()['error']);
		$this->assertSame('takeover_already_open', $response->getData()['code']);
	}//end testAskRefusalIsTranslated()

	/**
	 * A refused accept keeps the refusal's status.
	 *
	 * @return void
	 */
	public function testAcceptRefusalIsTranslated(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->takeovers->method('accept')
			->willThrowException(new RefusedException(rule: 'takeover-not-open', sentence: '', status: 422));
		$this->logger->expects($this->once())->method('info')
			->with('CaseTakeoverController: takeover accept refused', ['rule' => 'takeover-not-open', 'status' => 422]);

		$response = $this->controller->accept(caseId: 'case-1', takeoverId: 'tk-1');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('takeover-not-open', $response->getData()['error']);
	}//end testAcceptRefusalIsTranslated()

	/**
	 * A refusal of the takeover needs a reason.
	 *
	 * @return void
	 */
	public function testRefuseWithoutAReasonIsABadRequest(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturn('');
		$this->takeovers->expects($this->never())->method('refuse');

		$response = $this->controller->refuse(caseId: 'case-1', takeoverId: 'tk-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'A refusal without a reason is not an answer.', 'error' => 'takeover-reason-missing'],
			$response->getData()
		);
	}//end testRefuseWithoutAReasonIsABadRequest()

	/**
	 * A refused refusal is translated with the refuse op.
	 *
	 * @return void
	 */
	public function testRefuseRefusalIsTranslated(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturn(' Still working on it ');
		$this->takeovers->expects($this->once())->method('refuse')
			->with('tk-1', 'Still working on it', 'sofie', 'case-1')
			->willThrowException(new RefusedException(rule: 'takeover-not-open', sentence: ''));
		$this->logger->expects($this->once())->method('info')
			->with('CaseTakeoverController: takeover refuse refused', ['rule' => 'takeover-not-open', 'status' => 409]);

		$response = $this->controller->refuse(caseId: 'case-1', takeoverId: 'tk-1');

		$this->assertSame(409, $response->getStatus());
	}//end testRefuseRefusalIsTranslated()

	/**
	 * The list on a case needs read access.
	 *
	 * @return void
	 */
	public function testOnCaseWithoutReadAccessIsForbidden(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(false);
		$this->takeovers->expects($this->never())->method('onCase');

		$response = $this->controller->onCase(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testOnCaseWithoutReadAccessIsForbidden()

	/**
	 * The list on a case carries the case id, the requests and their count.
	 *
	 * @return void
	 */
	public function testOnCaseListsTheRequestsWithTheirTotal(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->takeovers->expects($this->once())->method('onCase')->with('case-1')
			->willReturn([['id' => 'tk-1'], ['id' => 'tk-2']]);

		$response = $this->controller->onCase(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['caseId' => 'case-1', 'requests' => [['id' => 'tk-1'], ['id' => 'tk-2']], 'total' => 2],
			$response->getData()
		);
	}//end testOnCaseListsTheRequestsWithTheirTotal()
}//end class
