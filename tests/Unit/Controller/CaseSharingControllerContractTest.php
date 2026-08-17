<?php

/**
 * CaseSharingController Wire-Contract Tests
 *
 * Contract coverage for the two share-lifecycle endpoints (gate-25).
 * `createShare` MINTS a public "track your case" surface — a token link that
 * anyone holding it can follow without a Nextcloud session — and `revokeShare`
 * is the only way to take one back. Both are `@NoAdminRequired`, so the
 * per-case guard is the entire authorization story. The contract pinned here:
 *
 *  - no session answers 401 and mints nothing;
 *  - a caller not assigned to the case is refused 403 BEFORE any token is
 *    minted, and the guard is asked about the caseId from the request together
 *    with the session UID;
 *  - the partner branch demands a partnerId and reports an upstream failure as
 *    502, distinct from the 400 it uses for its own input validation;
 *  - revoking a token link requires BOTH that the caller may access the case
 *    AND that the token actually belongs to that case — dropping the second
 *    condition is a cross-case IDOR (a handler of case A revoking case B's
 *    link by id), which is exactly the defect this file is here to catch.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\CaseSharingController;
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\CaseTransferService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for CaseSharingController.
 *
 * @covers \OCA\Procest\Controller\CaseSharingController
 */
class CaseSharingControllerContractTest extends TestCase {

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
	 * The CaseTransferService mock.
	 *
	 * @var CaseTransferService|MockObject
	 */
	private CaseTransferService $caseTransferService;

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
		$this->caseTransferService = $this->createMock(CaseTransferService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new CaseSharingController(
			request: $this->request,
			caseSharingService: $this->caseSharingService,
			caseTransferService: $this->caseTransferService,
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
	 * `createShare` refuses an anonymous caller with 401 and mints nothing.
	 *
	 * @return void
	 */
	public function testCreateShareRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->caseSharingService->expects($this->never())->method('createTokenShare');
		$this->caseSharingService->expects($this->never())->method('createPartnerShare');

		$response = $this->controller->createShare();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'Not authenticated'],
			$response->getData()
		);
	}//end testCreateShareRefusesAnUnauthenticatedCallerWith401()

	/**
	 * A caller who is not assigned to the case may not mint a public link for
	 * it: 403, and no token is created.
	 *
	 * @return void
	 */
	public function testCreateShareRefusesACallerNotAssignedToTheCaseWith403(): void {
		$this->signIn(uid: 'mallory');
		$this->withParams(['caseId' => 'case-1']);

		$this->caseSharingService->expects($this->once())
			->method('canUserAccessCase')
			->with('case-1', 'mallory')
			->willReturn(false);
		$this->caseSharingService->expects($this->never())->method('createTokenShare');

		$response = $this->controller->createShare();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			'Access denied: you are not assigned to this case',
			$response->getData()['error']
		);
	}//end testCreateShareRefusesACallerNotAssignedToTheCaseWith403()

	/**
	 * A request without a caseId is a 400, not a share against "no case".
	 *
	 * @return void
	 */
	public function testCreateShareRejectsAMissingCaseIdWith400(): void {
		$this->signIn();
		$this->withParams([]);
		$this->caseSharingService->expects($this->never())->method('createTokenShare');

		$response = $this->controller->createShare();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('caseId is required', $response->getData()['error']);
	}//end testCreateShareRejectsAMissingCaseIdWith400()

	/**
	 * The partner branch demands a partnerId — a partner share with no partner
	 * would otherwise be minted against an empty organisation.
	 *
	 * @return void
	 */
	public function testCreateShareRejectsAPartnerShareWithoutAPartnerIdWith400(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-1', 'shareType' => 'partner']);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->expects($this->never())->method('createPartnerShare');

		$response = $this->controller->createShare();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('partnerId is required for partner shares', $response->getData()['error']);
	}//end testCreateShareRejectsAPartnerShareWithoutAPartnerIdWith400()

	/**
	 * An upstream failure creating a partner share is a 502, not a 400 — the
	 * caller's input was fine; the downstream store was not.
	 *
	 * @return void
	 */
	public function testCreateShareReportsAPartnerStoreFailureAs502(): void {
		$this->signIn(uid: 'alice');
		$this->withParams([
			'caseId' => 'case-1',
			'shareType' => 'partner',
			'partnerId' => 'partner-9',
			'permissionLevel' => 'bewerken',
		]);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->expects($this->once())
			->method('createPartnerShare')
			->with('case-1', 'partner-9', 'bewerken', 'alice')
			->willReturn(['error' => 'OpenRegister unavailable']);

		$response = $this->controller->createShare();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('OpenRegister unavailable', $response->getData()['error']);
	}//end testCreateShareReportsAPartnerStoreFailureAs502()

	/**
	 * The default share type is a token link, minted through the sharing leaf
	 * with the caller's UID as owner and the requested expiry.
	 *
	 * @return void
	 */
	public function testCreateShareDefaultsToATokenShareOwnedByTheCaller(): void {
		$this->signIn(uid: 'alice');
		$this->withParams([
			'caseId' => 'case-1',
			'label' => 'Volg uw zaak',
			'expiresAt' => '2026-12-31',
		]);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->expects($this->once())
			->method('createTokenShare')
			->with('case-1', 'Volg uw zaak', 'alice', '2026-12-31')
			->willReturn(['id' => 'share-1', 'url' => 'https://example.test/s/abc']);

		$response = $this->controller->createShare();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame('share-1', $response->getData()['share']['id']);
	}//end testCreateShareDefaultsToATokenShareOwnedByTheCaller()

	/**
	 * `revokeShare` refuses an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testRevokeShareRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->caseSharingService->expects($this->never())->method('revokeShare');
		$this->caseSharingService->expects($this->never())->method('revokeTokenShare');

		$response = $this->controller->revokeShare(shareId: 'share-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Not authenticated', $response->getData()['error']);
	}//end testRevokeShareRefusesAnUnauthenticatedCallerWith401()

	/**
	 * Revoking a token link refuses a caller with no access to the named case.
	 *
	 * @return void
	 */
	public function testRevokeTokenShareRefusesACallerNotAssignedToTheCaseWith403(): void {
		$this->signIn(uid: 'mallory');
		$this->withParams(['caseId' => 'case-1']);

		$this->caseSharingService->method('canUserAccessCase')
			->with('case-1', 'mallory')
			->willReturn(false);
		$this->caseSharingService->expects($this->never())->method('revokeTokenShare');

		$response = $this->controller->revokeShare(shareId: 'share-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			'Access denied: you are not assigned to this case',
			$response->getData()['error']
		);
	}//end testRevokeTokenShareRefusesACallerNotAssignedToTheCaseWith403()

	/**
	 * Case access alone is NOT enough: the token must also belong to the case
	 * the caller named. Without this second condition a handler of case A can
	 * revoke case B's public link by quoting its id (ADR-005 rule 3 IDOR).
	 *
	 * @return void
	 */
	public function testRevokeTokenShareRefusesATokenBelongingToAnotherCase(): void {
		$this->signIn(uid: 'alice');
		$this->withParams(['caseId' => 'case-A']);

		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->expects($this->once())
			->method('tokenBelongsToCase')
			->with('token-of-case-B', 'case-A')
			->willReturn(false);
		$this->caseSharingService->expects($this->never())->method('revokeTokenShare');

		$response = $this->controller->revokeShare(shareId: 'token-of-case-B');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			'Access denied: you are not assigned to this case',
			$response->getData()['error']
		);
	}//end testRevokeTokenShareRefusesATokenBelongingToAnotherCase()

	/**
	 * A token revoke that the sharing leaf refuses is reported as 502 — the
	 * client must not read a failed revoke as a successful one.
	 *
	 * @return void
	 */
	public function testRevokeTokenShareReportsALeafFailureAs502(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-A']);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->method('tokenBelongsToCase')->willReturn(true);
		$this->caseSharingService->method('revokeTokenShare')->willReturn(false);

		$response = $this->controller->revokeShare(shareId: 'share-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('Could not revoke share link', $response->getData()['error']);
	}//end testRevokeTokenShareReportsALeafFailureAs502()

	/**
	 * A fully authorized token revoke answers 200 with `success: true`.
	 *
	 * @return void
	 */
	public function testRevokeTokenShareReturns200WhenBothGuardsPass(): void {
		$this->signIn();
		$this->withParams(['caseId' => 'case-A']);
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->method('tokenBelongsToCase')->willReturn(true);
		$this->caseSharingService->expects($this->once())
			->method('revokeTokenShare')
			->with('share-1')
			->willReturn(true);

		$response = $this->controller->revokeShare(shareId: 'share-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}//end testRevokeTokenShareReturns200WhenBothGuardsPass()

	/**
	 * The partner-share branch (no caseId in the request) resolves the share's
	 * OWN case and refuses a caller with no access to it.
	 *
	 * @return void
	 */
	public function testRevokePartnerShareResolvesTheSharesCaseAndRefusesWith403(): void {
		$this->signIn(uid: 'mallory');
		$this->withParams([]);

		$this->caseSharingService->expects($this->once())
			->method('getCaseIdForShare')
			->with('share-5')
			->willReturn('case-owned-by-someone-else');
		$this->caseSharingService->expects($this->once())
			->method('canUserAccessCase')
			->with('case-owned-by-someone-else', 'mallory')
			->willReturn(false);
		$this->caseSharingService->expects($this->never())->method('revokeShare');

		$response = $this->controller->revokeShare(shareId: 'share-5');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			'Access denied: you are not assigned to this case',
			$response->getData()['error']
		);
	}//end testRevokePartnerShareResolvesTheSharesCaseAndRefusesWith403()

	/**
	 * An authorized partner revoke answers 200 and carries the revoked share
	 * back, attributed to the revoking user.
	 *
	 * @return void
	 */
	public function testRevokePartnerShareReturnsTheRevokedShareAttributedToTheCaller(): void {
		$this->signIn(uid: 'alice');
		$this->withParams([]);
		$this->caseSharingService->method('getCaseIdForShare')->willReturn('case-1');
		$this->caseSharingService->method('canUserAccessCase')->willReturn(true);
		$this->caseSharingService->expects($this->once())
			->method('revokeShare')
			->with('share-5', 'alice')
			->willReturn(['id' => 'share-5', 'status' => 'revoked']);

		$response = $this->controller->revokeShare(shareId: 'share-5');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame('revoked', $response->getData()['share']['status']);
	}//end testRevokePartnerShareReturnsTheRevokedShareAttributedToTheCaller()
}//end class
