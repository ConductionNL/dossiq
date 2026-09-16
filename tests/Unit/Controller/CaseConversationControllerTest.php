<?php

/**
 * The two doors the live-conversation change opened.
 *
 * 🔴 EVERY ASSERTION HERE IS ABOUT THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD
 * BE REFUSED. Both endpoints are `#[NoAdminRequired]` and both take a case id
 * straight off the URL, so the first question asked of each is whether a caller
 * the per-case guard refuses reaches the service at all. A guard that is
 * present but never consulted looks exactly like one that works.
 *
 * The work behind the doors is asserted in the services' own tests; here the
 * question is only whether the refusal reaches the caller intact, whether the
 * act is passed on unchanged, and whether a service refusal keeps its own
 * status code instead of collapsing to 400.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseConversationController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Conversation\CaseCaptureService;
use OCA\Dossiq\Service\Conversation\CaseConversationService;
use OCA\Dossiq\Service\Conversation\MajorCaseDeclaration;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Ending a conversation and attaching a capture, each behind the case guard.
 *
 * @covers \OCA\Dossiq\Controller\CaseConversationController
 * @uses   \OCA\Dossiq\Service\Conversation\CaseCaptureService
 * @uses   \OCA\Dossiq\Service\Conversation\CaseConversationService
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class CaseConversationControllerTest extends TestCase {

	/**
	 * Conversations and the record they leave.
	 *
	 * @var CaseConversationService&MockObject
	 */
	private CaseConversationService $conversations;

	/**
	 * Voice notes and screen captures.
	 *
	 * @var CaseCaptureService&MockObject
	 */
	private CaseCaptureService $captures;

	/**
	 * Declaring a case major. Not exercised here, but the controller needs one.
	 *
	 * @var MajorCaseDeclaration&MockObject
	 */
	private MajorCaseDeclaration $major;

	/**
	 * The per-case guard, which fails closed.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * A signed-in handler and a guard that allows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->conversations = $this->createMock(originalClassName: CaseConversationService::class);
		$this->captures = $this->createMock(originalClassName: CaseCaptureService::class);
		$this->major = $this->createMock(originalClassName: MajorCaseDeclaration::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);

		$this->signedInAs(uid: 'jan');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
	}//end setUp()

	/**
	 * A caller the per-case guard refuses never ends anybody's conversation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testACallerWithoutCaseAccessCannotEndTheConversation(): void {
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->conversations->expects(self::never())->method('recordConversationEnd');

		$response = $this->controller()->end(caseId: 'case-1', roomId: 'room-1');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: 'access_denied', actual: $response->getData()['reason']);
	}//end testACallerWithoutCaseAccessCannotEndTheConversation()

	/**
	 * An anonymous caller is refused before the case id is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testAnAnonymousCallerCannotEndTheConversation(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->conversations->expects(self::never())->method('recordConversationEnd');

		$response = $this->controller()->end(caseId: 'case-1', roomId: 'room-1');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAnAnonymousCallerCannotEndTheConversation()

	/**
	 * What the caller posted reaches the service unchanged, and the record comes back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testTheEndedConversationIsRecordedAsPosted(): void {
		$this->conversations->expects(self::once())
			->method('recordConversationEnd')
			->with('case-1', 'room-1', ['jan', 'ayse'], 420)
			->willReturn(['ok' => true, 'id' => 'conv-1']);

		$response = $this->controller()->end(
			caseId: 'case-1',
			roomId: 'room-1',
			participants: ['jan', 'ayse'],
			durationSeconds: 420,
		);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'conv-1', actual: $response->getData()['id']);
	}//end testTheEndedConversationIsRecordedAsPosted()

	/**
	 * A refusal keeps the status its reason declares, rather than collapsing to 400.
	 *
	 * An unknown room and an absent case are both 404s, and a caller that reads
	 * 400 for either is told the wrong thing about what to do next.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnknownRoomIsNotFoundRatherThanABadRequest(): void {
		$this->conversations->method('recordConversationEnd')->willReturn(
			['ok' => false, 'reason' => CaseConversationService::REASON_UNKNOWN_ROOM]
		);

		$response = $this->controller()->end(caseId: 'case-1', roomId: 'nope');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testAnUnknownRoomIsNotFoundRatherThanABadRequest()

	/**
	 * A caller the per-case guard refuses cannot attach a capture either.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testACallerWithoutCaseAccessCannotAttachACapture(): void {
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->captures->expects(self::never())->method('attachCapture');

		$response = $this->controller()->capture(
			caseId: 'case-1',
			fileName: 'note.ogg',
			mimeType: 'audio/ogg',
			content: 'AAAA',
		);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: 'access_denied', actual: $response->getData()['reason']);
	}//end testACallerWithoutCaseAccessCannotAttachACapture()

	/**
	 * An anonymous caller cannot attach a capture.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testAnAnonymousCallerCannotAttachACapture(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->captures->expects(self::never())->method('attachCapture');

		$response = $this->controller()->capture(caseId: 'case-1', fileName: 'note.ogg');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAnAnonymousCallerCannotAttachACapture()

	/**
	 * The capture is passed on as posted, with the signed-in caller as its author.
	 *
	 * The author is NOT taken from the body. A capture that named its own author
	 * would let any caller attribute a recording to somebody else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testTheCaptureCarriesTheSignedInCallerAsItsAuthor(): void {
		$this->captures->expects(self::once())
			->method('attachCapture')
			->with('case-1', 'note.ogg', 'audio/ogg', 'AAAA', 'task-9', 'jan')
			->willReturn(['ok' => true, 'id' => 'cap-1']);

		$response = $this->controller()->capture(
			caseId: 'case-1',
			fileName: 'note.ogg',
			mimeType: 'audio/ogg',
			content: 'AAAA',
			taskId: 'task-9',
		);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'cap-1', actual: $response->getData()['id']);
	}//end testTheCaptureCarriesTheSignedInCallerAsItsAuthor()

	/**
	 * A file that is not a capture is a bad request, not a server problem.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testAFileThatIsNotACaptureIsRefusedAsABadRequest(): void {
		$this->captures->method('attachCapture')->willReturn(
			['ok' => false, 'reason' => CaseCaptureService::REASON_NOT_A_CAPTURE]
		);

		$response = $this->controller()->capture(
			caseId: 'case-1',
			fileName: 'begroting.xlsx',
			mimeType: 'application/vnd.ms-excel',
			content: 'AAAA',
		);

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testAFileThatIsNotACaptureIsRefusedAsABadRequest()

	/**
	 * Sign the session in as one user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signedInAs(string $uid): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signedInAs()

	/**
	 * The controller under test, built from the current doubles.
	 *
	 * @return CaseConversationController The controller.
	 */
	private function controller(): CaseConversationController {
		return new CaseConversationController(
			request: $this->createMock(originalClassName: IRequest::class),
			conversations: $this->conversations,
			major: $this->major,
			captures: $this->captures,
			caseAccessGuard: $this->guard,
			userSession: $this->userSession,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()
}//end class
