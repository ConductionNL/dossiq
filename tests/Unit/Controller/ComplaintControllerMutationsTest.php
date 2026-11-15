<?php

/**
 * ComplaintController mutations, characterised.
 *
 * Pins update and transition the way escalate and verdaging are pinned
 * already: a caller without a session is refused with 401, an unknown
 * complaint answers 404, a stranger to the complaint is refused before the
 * body is read, the handler's change reaches the service, and a domain
 * refusal is a 400.
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

use InvalidArgumentException;
use OCA\Dossiq\Controller\ComplaintController;
use OCA\Dossiq\Service\Complaint\ComplaintAccessGuard;
use OCA\Dossiq\Service\ComplaintService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Update and transition, allowed and refused.
 *
 * @covers \OCA\Dossiq\Controller\ComplaintController
 * @covers \OCA\Dossiq\Controller\Support\ComplaintMutationGate
 * @uses \OCA\Dossiq\Service\Complaint\ComplaintAccessGuard
 */
class ComplaintControllerMutationsTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The complaint service.
	 *
	 * @var ComplaintService&MockObject
	 */
	private ComplaintService&MockObject $complaints;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The controller.
	 *
	 * @var ComplaintController
	 */
	private ComplaintController $controller;

	/**
	 * Build the controller over the real access guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->complaints = $this->createMock(ComplaintService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->controller = new ComplaintController(
			appName: 'dossiq',
			request: $this->request,
			complaintService: $this->complaints,
			accessGuard: new ComplaintAccessGuard(request: $this->request, userSession: $this->session, groupManager: $this->groups),
		);
	}//end setUp()

	/**
	 * Sign in as this user, with this body.
	 *
	 * @param string               $uid  The user.
	 * @param array<string, mixed> $body The body.
	 *
	 * @return void
	 */
	private function signIn(string $uid, array $body = []): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn($body);
	}//end signIn()

	/**
	 * The two mutations under test.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function mutations(): array {
		return [
			'update' => ['update', 'updateComplaint'],
			'transition' => ['transition', 'transitionStatus'],
		];
	}//end mutations()

	/**
	 * Without a session the answer is 401 and nothing is read.
	 *
	 * @param string $action  The action.
	 * @param string $service The service method it changes through.
	 *
	 * @dataProvider mutations
	 *
	 * @return void
	 */
	public function testACallerWithoutASessionIs401(string $action, string $service): void {
		$this->complaints->expects($this->never())->method('getComplaint');
		$this->complaints->expects($this->never())->method($service);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->$action(id: 'klacht-1')->getStatus());
	}//end testACallerWithoutASessionIs401()

	/**
	 * An unknown complaint answers 404.
	 *
	 * @param string $action  The action.
	 * @param string $service The service method it changes through.
	 *
	 * @dataProvider mutations
	 *
	 * @return void
	 */
	public function testAnUnknownComplaintIs404(string $action, string $service): void {
		$this->signIn('alice');
		$this->complaints->method('getComplaint')->willReturn(null);
		$this->complaints->expects($this->never())->method($service);

		$response = $this->controller->$action(id: 'klacht-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Complaint not found'], $response->getData());
	}//end testAnUnknownComplaintIs404()

	/**
	 * A stranger to the complaint is refused before anything changes.
	 *
	 * @param string $action  The action.
	 * @param string $service The service method it changes through.
	 *
	 * @dataProvider mutations
	 *
	 * @return void
	 */
	public function testAStrangerIsRefused(string $action, string $service): void {
		$this->signIn('mallory', ['status' => 'closed']);
		$this->complaints->method('getComplaint')->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->complaints->expects($this->never())->method($service);

		$this->expectException(OCSForbiddenException::class);

		$this->controller->$action(id: 'klacht-1');
	}//end testAStrangerIsRefused()

	/**
	 * The handler's update reaches the service and is answered.
	 *
	 * @return void
	 */
	public function testTheHandlersUpdateIsSaved(): void {
		$this->signIn('alice', ['_route' => 'r', 'title' => 'Nieuw']);
		$this->complaints->method('getComplaint')->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->complaints->expects($this->once())->method('updateComplaint')
			->with('klacht-1', $this->callback(fn (array $data): bool => ($data['title'] ?? null) === 'Nieuw'))
			->willReturn(['id' => 'klacht-1', 'title' => 'Nieuw']);

		$response = $this->controller->update(id: 'klacht-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'klacht-1', 'title' => 'Nieuw'], $response->getData());
	}//end testTheHandlersUpdateIsSaved()

	/**
	 * The transition reaches the service with the body's status, '' when absent.
	 *
	 * @return void
	 */
	public function testTheTransitionCarriesTheStatus(): void {
		$this->signIn('alice', ['status' => 'in_behandeling']);
		$this->complaints->method('getComplaint')->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->complaints->expects($this->once())->method('transitionStatus')->with('klacht-1', 'in_behandeling')->willReturn(['status' => 'in_behandeling']);

		$this->assertSame(['status' => 'in_behandeling'], $this->controller->transition(id: 'klacht-1')->getData());
	}//end testTheTransitionCarriesTheStatus()

	/**
	 * A domain refusal of either kind is a 400 with its message.
	 *
	 * @return void
	 */
	public function testADomainRefusalIs400(): void {
		$this->signIn('alice', []);
		$this->complaints->method('getComplaint')->willReturn(['id' => 'klacht-1', 'handler' => 'alice']);
		$this->complaints->method('updateComplaint')->willThrowException(new InvalidArgumentException('title too long'));
		$this->complaints->method('transitionStatus')->with('klacht-1', '')->willThrowException(new RuntimeException('no such status'));

		$this->assertSame(['error' => 'title too long'], $this->controller->update(id: 'klacht-1')->getData());
		$refused = $this->controller->transition(id: 'klacht-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['error' => 'no such status'], $refused->getData());
	}//end testADomainRefusalIs400()
}//end class
