<?php

/**
 * Who may read, change and coordinate complaints.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Complaint
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

namespace OCA\Dossiq\Tests\Unit\Service\Complaint;

use OCA\Dossiq\Service\Complaint\ComplaintAccessGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Complaint\ComplaintAccessGuard
 */
class ComplaintAccessGuardTest extends TestCase {
	/**
	 * A guard over the given request, session user and admin set.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param string|null          $uid    The signed-in user, or none.
	 * @param list<string>         $admins The admin uids.
	 *
	 * @return ComplaintAccessGuard
	 */
	private function guard(array $params = [], ?string $uid = 'alice', array $admins = []): ComplaintAccessGuard {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $admins, true)
		);

		return new ComplaintAccessGuard($request, $session, $groups);
	}//end guard()

	public function testCurrentUidIsTheSignedInUser(): void {
		$this->assertSame('alice', $this->guard()->currentUid());
	}//end testCurrentUidIsTheSignedInUser()

	public function testCurrentUidIsEmptyWhenNobodyIsSignedIn(): void {
		$this->assertSame('', $this->guard(uid: null)->currentUid());
	}//end testCurrentUidIsEmptyWhenNobodyIsSignedIn()

	public function testNotAuthenticatedIsA401WithAFixedMessage(): void {
		$response = $this->guard()->notAuthenticated();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testNotAuthenticatedIsA401WithAFixedMessage()

	public function testParseBodyReturnsTheRequestParams(): void {
		$this->assertSame(['title' => 'Late reply'], $this->guard(['title' => 'Late reply'])->parseBody());
		$this->assertSame([], $this->guard([])->parseBody());
	}//end testParseBodyReturnsTheRequestParams()

	public function testAnyAuthenticatedUserMayRead(): void {
		$this->guard()->authorizeAccess(['handler' => 'bob'], 'alice');
		$this->addToAssertionCount(1);
	}//end testAnyAuthenticatedUserMayRead()

	public function testTheHandlerMayChangeTheirComplaint(): void {
		$this->guard()->authorizeMutation(['handler' => 'alice'], 'alice');
		$this->addToAssertionCount(1);
	}//end testTheHandlerMayChangeTheirComplaint()

	public function testAnAdminMayChangeSomeoneElsesComplaint(): void {
		$this->guard(admins: ['root'])->authorizeMutation(['handler' => 'bob'], 'root');
		$this->addToAssertionCount(1);
	}//end testAnAdminMayChangeSomeoneElsesComplaint()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unassigned(): array {
		return [
			'no handler key' => [[]],
			'null handler' => [['handler' => null]],
			'empty handler' => [['handler' => '']],
		];
	}//end unassigned()

	/**
	 * @dataProvider unassigned
	 *
	 * @param array<string, mixed> $complaint The complaint.
	 */
	public function testAnyCaseWorkerMayChangeAnUnassignedComplaint(array $complaint): void {
		$this->guard()->authorizeMutation($complaint, 'alice');
		$this->addToAssertionCount(1);
	}//end testAnyCaseWorkerMayChangeAnUnassignedComplaint()

	public function testAnotherUserMayNotChangeAnAssignedComplaint(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Not authorized to modify this complaint');
		$this->guard(admins: ['root'])->authorizeMutation(['handler' => 'bob'], 'alice');
	}//end testAnotherUserMayNotChangeAnAssignedComplaint()

	public function testACoordinatorIsAnAdmin(): void {
		$this->guard(admins: ['root'])->requireCoordinator('root');
		$this->addToAssertionCount(1);
	}//end testACoordinatorIsAnAdmin()

	public function testANonAdminIsNoCoordinator(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('This action requires coordinator (admin) privileges');
		$this->guard(admins: ['root'])->requireCoordinator('alice');
	}//end testANonAdminIsNoCoordinator()
}//end class
