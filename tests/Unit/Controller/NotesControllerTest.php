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
use OCA\Dossiq\Service\External\Zgw\NotePush;
use OCA\Dossiq\Service\MentionNotificationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NotesController.
 *
 * @covers \OCA\Dossiq\Controller\NotesController
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
	 * @var NotePush|\PHPUnit\Framework\MockObject\MockObject
	 */
	private NotePush $notePush;

	/**
	 * @var CaseAccessGuard|\PHPUnit\Framework\MockObject\MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

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

		// The two collaborators the note PUSH needs
		// (a-case-note-reaches-the-neighbouring-register). Doubled here rather
		// than left out: the constructor takes them, and the mention arms in
		// this file must keep passing without knowing anything about the push.
		$this->notePush = $this->getMockBuilder(NotePush::class)
			->disableOriginalConstructor()
			->onlyMethods(['push'])
			->getMock();
		$this->caseAccessGuard = $this->getMockBuilder(CaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCaseMutationAccess'])
			->getMock();

		$this->controller = new NotesController(
			request: $this->request,
			mentionSvc: $this->service,
			userSession: $this->userSession,
			logger: $this->logger,
			notePush: $this->notePush,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

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
				objectId: 'case-1',
				register: 'dossiq',
				schema: 'case',
				noteId: 'note-9',
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
		$this->withBody(['objectId' => 'case-1', 'mentionedUserIds' => ['bob']]);

		$this->service->method('notifyMention')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->mention();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testMentionServiceExceptionMapsTo500()
	/**
	 * A caller with no mutation access to the case cannot send its notes.
	 *
	 * Sending a note to another organisation is externally visible and not
	 * undoable, so the guard is mutation access rather than read, and it runs
	 * before the note is even looked at.
	 *
	 * @return void
	 */
	public function testACallerWithoutMutationAccessCannotPushANote(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('outsider', 'Outsider'));
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(false);
		$this->notePush->expects($this->never())->method('push');

		$response = $this->controller->push('someone-elses-case');

		$this->assertSame(403, $response->getStatus());
	}//end testACallerWithoutMutationAccessCannotPushANote()

	/**
	 * A caller who may change the case gets the push outcome back verbatim.
	 *
	 * Without this arm a controller that refused everything would satisfy the
	 * arm above.
	 *
	 * @return void
	 */
	public function testACallerWithMutationAccessGetsTheOutcome(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('handler', 'Handler'));
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParams')->willReturn(
			['note' => ['id' => 1, 'message' => 'Kort', 'visibility' => 'public']]
		);
		$this->notePush->method('push')->willReturn(
			[
				'outcome' => 'failed',
				'reason' => 'the register said no',
				'receiverUrl' => '',
				'caseRecord' => 'lost',
			]
		);

		$response = $this->controller->push('my-own-case');

		// 200 for a FAILED push, on purpose: the caller asked what happened and
		// every answer here is a real answer. A 500 for a refused push would be
		// this app reporting its own failure rather than the note's.
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('failed', $response->getData()['outcome']);
		$this->assertSame('the register said no', $response->getData()['reason']);
		// VERBATIM means the whole answer, including whether the case was told.
		// A controller that forwarded the outcome and dropped this would hand
		// the caller a failure that reads as though the case now carries it.
		$this->assertSame('lost', $response->getData()['caseRecord']);
	}//end testACallerWithMutationAccessGetsTheOutcome()
}//end class
