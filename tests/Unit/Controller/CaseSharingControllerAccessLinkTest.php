<?php

/**
 * CaseSharingController access-link wire-contract tests (gate-25).
 *
 * Three endpoints arrived with case-sharing-mints-access-links, and all three
 * are `@NoAdminRequired`, so the per-case guard is the entire authorization
 * story: `GET /api/shares/case/{caseId}`, `PUT /api/shares/{shareId}` and
 * `GET /api/shares/{shareId}/preview`.
 *
 * Two guards, not one. Case access alone lets a handler of case A act on case
 * B's link by quoting its id, which is the cross-case IDOR of ADR-005 rule 3.
 * Every test below that asserts a refusal also asserts that nothing was read
 * or written, because a refusal returned after the act is not a refusal.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseSharingController;
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\CaseTransferService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for the three access-link endpoints.
 *
 * @covers \OCA\Dossiq\Controller\CaseSharingController
 */
class CaseSharingControllerAccessLinkTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The CaseSharingService mock.
	 *
	 * @var CaseSharingService|MockObject
	 */
	private CaseSharingService $caseSharingService;

	/**
	 * The IUserSession mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var CaseSharingController
	 */
	private CaseSharingController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->caseSharingService = $this->createMock(CaseSharingService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new CaseSharingController(
			request: $this->request,
			caseSharingService: $this->caseSharingService,
			caseTransferService: $this->createMock(CaseTransferService::class),
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @param string $uid The UID of the signed-in user.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Answer request parameters from the supplied map.
	 *
	 * @param array<string, mixed> $params The parameter map.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withParams()

	/**
	 * A caller with no access to the case may not publish it, and no link is
	 * minted before the refusal.
	 *
	 * @return void
	 */
	public function testCreateShareRefusesAnUnrelatedCase(): void {
		$this->signIn(uid: 'mallory');
		$this->withParams(['caseId' => 'case-1', 'capabilities' => 'read,comment']);

		$this->caseSharingService->method('canUserAccessCase')->willReturn(false);
		$this->caseSharingService->expects($this->never())->method('createTokenShare');

		$response = $this->controller->createShare();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCreateShareRefusesAnUnrelatedCase()

	/**
	 * Listing the links on a case refuses an anonymous caller, and reads
	 * nothing.
	 *
	 * @return void
	 */
	public function testListLinksRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->caseSharingService->expects($this->never())->method('listLinkShares');

		$response = $this->controller->listLinks(caseId: 'case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testListLinksRefusesAnUnauthenticatedCallerWith401()

	/**
	 * Listing the links on somebody else's case is 403, and reads nothing.
	 *
	 * @return void
	 */
	public function testListLinksRefusesACaseTheCallerCannotOpen(): void {
		$this->signIn(uid: 'mallory');
		$this->caseSharingService->expects($this->once())
			->method('canUserAccessCase')
			->with('case-1', 'mallory')
			->willReturn(false);
		$this->caseSharingService->expects($this->never())->method('listLinkShares');

		$response = $this->controller->listLinks(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testListLinksRefusesACaseTheCallerCannotOpen()

	/**
	 * An authorised list answers the case's links under `results`.
	 *
	 * @return void
	 */
	public function testListLinksAnswersTheCasesLinks(): void {
		$this->signIn();
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->expects($this->once())
			->method('listLinkShares')
			->with('case-1')
			->willReturn([['accessLinkId' => 7, 'state' => 'live']]);

		$response = $this->controller->listLinks(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['accessLinkId' => 7, 'state' => 'live']], $response->getData()['results']);
	}//end testListLinksAnswersTheCasesLinks()

	/**
	 * Pausing without naming a case is a 400, not a pause against "no case":
	 * the caseId is half of the guard, so its absence cannot be defaulted.
	 *
	 * @return void
	 */
	public function testPauseLinkWithoutACaseIdIs400(): void {
		$this->signIn();
		$this->withParams([]);
		$this->caseSharingService->expects($this->never())->method('pauseTokenShare');

		$response = $this->controller->pauseLink(shareId: '7');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testPauseLinkWithoutACaseIdIs400()

	/**
	 * A pause on a link the named case did not mint is 403, and nothing is
	 * switched off.
	 *
	 * @return void
	 */
	public function testPauseLinkRefusesALinkOnAnotherCase(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-A', 'disabled' => true]);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->expects($this->once())
			->method('linkBelongsToCase')
			->with(42, 'case-A')
			->willReturn(false);
		$this->caseSharingService->expects($this->never())->method('pauseTokenShare');

		$response = $this->controller->pauseLink(shareId: '42');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testPauseLinkRefusesALinkOnAnotherCase()

	/**
	 * A refused pause is a 502, never a success: OpenRegister allows it only
	 * for the colleague who minted the link.
	 *
	 * @return void
	 */
	public function testPauseLinkReportsARefusalAs502(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-A', 'disabled' => true]);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->method('linkBelongsToCase')->willReturn(true);
		$this->caseSharingService->method('pauseTokenShare')->willReturn(null);

		$response = $this->controller->pauseLink(shareId: '7');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}//end testPauseLinkReportsARefusalAs502()

	/**
	 * A preview of a link belonging to another case is 403, and the preview
	 * is never assembled.
	 *
	 * @return void
	 */
	public function testPreviewRefusesALinkOnAnotherCase(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-A']);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->expects($this->once())
			->method('linkBelongsToCase')
			->with(42, 'case-A')
			->willReturn(false);
		$this->caseSharingService->expects($this->never())->method('holderPreview');

		$response = $this->controller->previewLink(shareId: '42');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testPreviewRefusesALinkOnAnotherCase()

	/**
	 * An authorised preview answers what the holder reads.
	 *
	 * @return void
	 */
	public function testPreviewAnswersWhatTheHolderReads(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-A']);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->method('linkBelongsToCase')->willReturn(true);
		$this->caseSharingService->expects($this->once())
			->method('holderPreview')
			->with(7, 'case-A')
			->willReturn(['subject' => ['title' => 'Vergunning']]);

		$response = $this->controller->previewLink(shareId: '7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Vergunning', $response->getData()['preview']['subject']['title']);
	}//end testPreviewAnswersWhatTheHolderReads()

	/**
	 * A link that no longer opens anything is a 404, not an empty preview the
	 * handler would read as "this link publishes nothing".
	 *
	 * @return void
	 */
	public function testPreviewOfADeadLinkIs404(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-A']);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->method('linkBelongsToCase')->willReturn(true);
		$this->caseSharingService->method('holderPreview')->willReturn(null);

		$response = $this->controller->previewLink(shareId: '7');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testPreviewOfADeadLinkIs404()
}//end class
