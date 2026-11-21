<?php

/**
 * NotesController Unit Tests
 *
 * Covers the note-mention notification endpoint: authentication gate,
 * payload validation, and delegation to MentionNotificationService.
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
 * @spec openspec/changes/ncvue-w2-leaves-adoption/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\NotesController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\MentionNotificationService;
use OCA\Dossiq\Service\Notes\MentionedNote;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NotesController.
 *
 * @covers \OCA\Dossiq\Controller\NotesController
 * @uses \OCA\Dossiq\Service\Notes\MentionedNote
 */
class NotesControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * @var MentionNotificationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private MentionNotificationService $service;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var CaseAccessGuard|\PHPUnit\Framework\MockObject\MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * @var IUserManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserManager $userManager;

	/**
	 * The controller under test.
	 *
	 * @var NotesController
	 */
	private NotesController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(MentionNotificationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->caseAccessGuard = $this->getMockBuilder(CaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCaseMutationAccess', 'hasCaseReadAccess'])
			->getMock();
		$this->userManager = $this->createMock(IUserManager::class);

		$this->controller = new NotesController(
			request: $this->request,
			mentionSvc: $this->service,
			userSession: $this->userSession,
			logger: $this->logger,
			caseAccessGuard: $this->caseAccessGuard,
			userManager: $this->userManager,
		);
	}//end setUp()

	/**
	 * Let the caller read the case and make every mentioned user exist.
	 *
	 * @return void
	 */
	private function withReadableCaseAndKnownUsers(): void {
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->mockUser($uid, $uid));
	}//end withReadableCaseAndKnownUsers()

	/**
	 * Build a mock authenticated user.
	 *
	 * @param string $uid The user id.
	 * @param string $displayName The display name.
	 *
	 * @return IUser|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mockUser(string $uid = 'alice', string $displayName = 'Alice'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);

		return $user;
	}//end mockUser()

	/**
	 * Set the raw request body the controller will decode.
	 *
	 * @param array<string,mixed> $payload The decoded payload to serve.
	 *
	 * @return void
	 */
	private function withBody(array $payload): void {
		$this->request->method('getParams')->willReturn($payload);
	}//end withBody()

	/**
	 * An unauthenticated mention request is rejected with 401.
	 *
	 * @return void
	 */
	public function testMentionRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->withBody(['objectId' => 'case-1', 'mentionedUserIds' => ['bob']]);

		$response = $this->controller->mention();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testMentionRequiresAuthentication()

	/**
	 * A missing objectId yields 400.
	 *
	 * @return void
	 */
	public function testMentionMissingObjectIdIsBadRequest(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser());
		$this->withBody(['mentionedUserIds' => ['bob']]);

		$response = $this->controller->mention();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testMentionMissingObjectIdIsBadRequest()

	/**
	 * An empty mentionedUserIds array yields 400.
	 *
	 * @return void
	 */
	public function testMentionEmptyMentionedUserIdsIsBadRequest(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser());
		$this->withBody(['objectId' => 'case-1', 'mentionedUserIds' => []]);

		$response = $this->controller->mention();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testMentionEmptyMentionedUserIdsIsBadRequest()

	/**
	 * A well-formed request delegates to the service with the actor's uid
	 * and display name plus the forwarded payload fields, and returns 200
	 * with the notified count.
	 *
	 * @return void
	 */
	public function testMentionSuccessDelegatesAndReturns200(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser(uid: 'alice', displayName: 'Alice'));
		$this->withReadableCaseAndKnownUsers();
		$this->withBody(
			[
				'objectId' => 'case-1',
				'register' => 'dossiq',
				'schema' => 'case',
				'noteId' => 'note-9',
				'mentionedUserIds' => ['bob', 'carol'],
			]
		);

		$this->service->expects($this->once())
			->method('notifyMention')
			->with(
				actorUserId: 'alice',
				actorDisplayName: 'Alice',
				note: $this->equalTo(new MentionedNote(objectId: 'case-1', register: 'dossiq', schema: 'case', noteId: 'note-9')),
				mentionedUserIds: ['bob', 'carol'],
			)
			->willReturn(2);

		$response = $this->controller->mention();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['notified' => 2], $response->getData());
	}//end testMentionSuccessDelegatesAndReturns200()

	/**
	 * A service exception is caught and mapped to a 500 with an error body
	 * (never surfaced as an uncaught throwable — the note is already saved
	 * by the time this endpoint runs).
	 *
	 * @return void
	 */
	public function testMentionServiceExceptionMapsTo500(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser());
		$this->withReadableCaseAndKnownUsers();
		$this->withBody(['objectId' => 'case-1', 'mentionedUserIds' => ['bob']]);

		$this->service->method('notifyMention')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->mention();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testMentionServiceExceptionMapsTo500()

	/**
	 * A caller who cannot read the case gets a 403 and nobody is notified.
	 *
	 * @return void
	 */
	public function testAMentionOnACaseTheCallerCannotReadIsRefused(): void {
		$user = $this->mockUser('outsider', 'Outsider');
		$this->userSession->method('getUser')->willReturn($user);
		$this->withBody(['objectId' => 'case-1', 'mentionedUserIds' => ['bob']]);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->mockUser($uid, $uid));
		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseReadAccess')
			->with(caseId: 'case-1', user: $user)
			->willReturn(false);
		$this->service->expects($this->never())->method('notifyMention');

		$response = $this->controller->mention();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $response->getData());
	}//end testAMentionOnACaseTheCallerCannotReadIsRefused()

	/**
	 * Mentioned user ids with no account behind them are not notified.
	 *
	 * @return void
	 */
	public function testMentionSkipsUsersThatDoNotExist(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser());
		$this->withBody(['objectId' => 'case-1', 'mentionedUserIds' => ['bob', 'ghost', 'carol']]);
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->userManager->method('get')
			->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'ghost' ? null : $this->mockUser($uid, $uid)));
		$this->service->expects($this->once())
			->method('notifyMention')
			->with($this->anything(), $this->anything(), $this->anything(), ['bob', 'carol'])
			->willReturn(2);

		$response = $this->controller->mention();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testMentionSkipsUsersThatDoNotExist()

	/**
	 * Mentioned users who may not read the case are not notified.
	 *
	 * @return void
	 */
	public function testMentionSkipsUsersWhoCannotReadTheCase(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser());
		$this->withBody(['objectId' => 'case-1', 'mentionedUserIds' => ['bob', 'outsider', 'carol']]);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->mockUser($uid, $uid));
		$this->caseAccessGuard->method('hasCaseReadAccess')
			->willReturnCallback(static fn (string $caseId, IUser $user): bool => $caseId === 'case-1' && $user->getUID() !== 'outsider');
		$this->service->expects($this->once())
			->method('notifyMention')
			->with($this->anything(), $this->anything(), $this->anything(), ['bob', 'carol'])
			->willReturn(2);

		$response = $this->controller->mention();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['notified' => 2], $response->getData());
	}//end testMentionSkipsUsersWhoCannotReadTheCase()

	/**
	 * When no mentioned user exists, nobody is notified and the answer says so.
	 *
	 * @return void
	 */
	public function testMentionOfOnlyUnknownUsersNotifiesNobody(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser());
		$this->withBody(['objectId' => 'case-1', 'mentionedUserIds' => ['ghost']]);
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->userManager->method('get')->willReturn(null);
		$this->service->expects($this->never())->method('notifyMention');

		$response = $this->controller->mention();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['notified' => 0], $response->getData());
	}//end testMentionOfOnlyUnknownUsersNotifiesNobody()

	/**
	 * A failing mention dispatch is a logged 500 carrying the message.
	 *
	 * @return void
	 */
	public function testAFailingMentionDispatchIsALoggedServerError(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice', 'Alice'));
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->mockUser($uid, $uid));
		$this->request->method('getParams')->willReturn(['objectId' => 'case-1', 'mentionedUserIds' => ['bob']]);
		$this->service->method('notifyMention')->willThrowException(new \RuntimeException('bus down'));
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to dispatch note mention notifications: bus down',
			['app' => 'dossiq']
		);

		$response = $this->controller->mention();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Could not dispatch mention notifications: bus down'], $response->getData());
	}//end testAFailingMentionDispatchIsALoggedServerError()
}//end class
