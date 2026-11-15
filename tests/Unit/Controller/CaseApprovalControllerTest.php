<?php

/**
 * CaseApprovalController Unit Tests.
 *
 * Pins the order of the guards (signed in, then case mutation access), the
 * approval raised as the signed-in user, and the answers for a refusal and an
 * unexpected failure.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseApprovalController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Cases\ApprovalRequest;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for CaseApprovalController.
 *
 * @covers \OCA\Dossiq\Controller\CaseApprovalController
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseApprovalControllerTest extends TestCase {

	/**
	 * The approval request double.
	 *
	 * @var ApprovalRequest&MockObject
	 */
	private ApprovalRequest&MockObject $approvals;

	/**
	 * The case access guard double.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard&MockObject $guard;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->approvals = $this->createMock(ApprovalRequest::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller with the given signed-in user.
	 *
	 * @param IUser|null $user The signed-in user, or null.
	 *
	 * @return CaseApprovalController
	 */
	private function controller(?IUser $user): CaseApprovalController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new CaseApprovalController($this->createMock(IRequest::class), $this->approvals, $this->guard, $session, $this->logger);
	}//end controller()

	/**
	 * A user with the given UID.
	 *
	 * @param string $uid The UID.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Without a user the answer is 401 and the case is not even checked.
	 *
	 * @return void
	 */
	public function testRaiseRequiresAUser(): void {
		$this->guard->expects($this->never())->method('hasCaseMutationAccess');
		$this->approvals->expects($this->never())->method('raise');

		$response = $this->controller(user: null)->raise('case-1', 'besluit');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Sign in first.', 'error' => 'not-authenticated'], $response->getData());
	}//end testRaiseRequiresAUser()

	/**
	 * A user who does not work on the case gets 403 and no approval is raised.
	 *
	 * @return void
	 */
	public function testRaiseRequiresCaseMutationAccess(): void {
		$user = $this->user('bob');
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('case-1', $user)->willReturn(false);
		$this->approvals->expects($this->never())->method('raise');

		$response = $this->controller(user: $user)->raise('case-1', 'besluit');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'You do not work on this case.', 'error' => 'not-authorized'], $response->getData());
	}//end testRaiseRequiresCaseMutationAccess()

	/**
	 * With access, the approval is raised as the signed-in user.
	 *
	 * @return void
	 */
	public function testRaiseAsksForTheApprovalAsTheUser(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->approvals->expects($this->once())->method('raise')->with('case-1', 'besluit', 'alice')->willReturn(['id' => 'appr-1']);

		$response = $this->controller(user: $this->user('alice'))->raise('case-1', 'besluit');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'appr-1'], $response->getData());
	}//end testRaiseAsksForTheApprovalAsTheUser()

	/**
	 * A refusal answers with its own status, rule and code, logged as info.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsRuleAndStatus(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->approvals->method('raise')->willThrowException(new RefusedException('already-pending', '', RefusedException::STATUS_REFUSED));
		$this->logger->expects($this->once())->method('info')->with(
			'CaseApprovalController: raise approval refused',
			['rule' => 'already-pending', 'status' => 409]
		);

		$response = $this->controller(user: $this->user('alice'))->raise('case-1', 'besluit');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['message' => '', 'error' => 'already-pending', 'code' => 'already_pending'], $response->getData());
	}//end testARefusalCarriesItsRuleAndStatus()

	/**
	 * An unexpected failure is logged and answered with 500.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIs500(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->approvals->method('raise')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'CaseApprovalController: the approval could not be asked for',
			['caseId' => 'case-1', 'act' => 'besluit', 'exception' => 'db down']
		);

		$response = $this->controller(user: $this->user('alice'))->raise('case-1', 'besluit');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'The approval was not asked for.', 'error' => 'approval-request-failed'], $response->getData());
	}//end testAnUnexpectedFailureIs500()
}//end class
