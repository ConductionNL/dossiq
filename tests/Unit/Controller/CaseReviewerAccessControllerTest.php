<?php

/**
 * Case Reviewer Access Controller Test
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
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-by-an-administrator-req-cra-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseReviewerAccessController;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\CaseReviewerAccessController
 */
class CaseReviewerAccessControllerTest extends TestCase {

	private CaseReviewerAccess&MockObject $access;

	/**
	 * The controller, signed in as the user.
	 *
	 * @param string $uid The caller.
	 *
	 * @return CaseReviewerAccessController
	 */
	private function controller(string $uid): CaseReviewerAccessController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $id === 'admin');

		return new CaseReviewerAccessController(request: $this->createMock(IRequest::class), access: $this->access, userSession: $session, groupManager: $groups);
	}//end controller()

	protected function setUp(): void {
		$this->access = $this->createMock(CaseReviewerAccess::class);
	}//end setUp()

	public function testANonAdminIsRefusedAndNothingRuns(): void {
		$this->access->expects($this->never())->method('reconcileAll');

		$response = $this->controller(uid: 'coordinator')->reconcile();

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'administrator-only'], $response->getData());
	}//end testANonAdminIsRefusedAndNothingRuns()

	public function testItAnswersTheSummedCounts(): void {
		$sum = ['status' => 'reconciled', 'cases' => 2, 'granted' => 5, 'revoked' => 0, 'refused' => 0, 'failed' => []];
		$this->access->method('reconcileAll')->willReturn($sum);

		$response = $this->controller(uid: 'admin')->reconcile();

		self::assertSame(200, $response->getStatus());
		self::assertSame($sum, $response->getData());
	}//end testItAnswersTheSummedCounts()

	public function testUnavailableIsNotASuccess(): void {
		$this->access->method('reconcileAll')->willReturn(['status' => 'unavailable', 'cases' => 0, 'granted' => 0, 'revoked' => 0, 'refused' => 0, 'failed' => []]);

		self::assertSame(503, $this->controller(uid: 'admin')->reconcile()->getStatus());
	}//end testUnavailableIsNotASuccess()
}//end class
