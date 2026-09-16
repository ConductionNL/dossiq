<?php

/**
 * The door to the internal handover.
 *
 * 🔴 EVERY ASSERTION HERE IS ABOUT THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD
 * BE REFUSED, not about the administrator who would succeed at anything. An
 * endpoint that hands somebody else's case to a team they cannot reach is a
 * good way to lose the case, and `#[NoAdminRequired]` with no per-object check
 * is exactly the IDOR shape gate-6 exists for. So the first question asked of
 * each of the four endpoints is: does a caller the guard refuses reach the
 * service at all.
 *
 * The work behind the door is asserted in
 * `tests/Unit/Service/InternalCaseHandoverTest.php`; here the question is only
 * whether the refusal reaches the caller intact and whether the act is passed
 * on unchanged.
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
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseHandoverController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseTransferService;
use OCA\Dossiq\Service\Transfer\TeamDirectory;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Hand, accept, refuse and the outstanding list, each behind its own guard.
 *
 * @covers \OCA\Dossiq\Controller\CaseHandoverController
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */
class CaseHandoverControllerTest extends TestCase {

	/**
	 * The one door to every case move.
	 *
	 * @var CaseTransferService&MockObject
	 */
	private CaseTransferService $transfers;

	/**
	 * The per-case guard, which fails closed.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The teams the caller belongs to.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groups;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The request, answering the posted body.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The posted body, per test.
	 *
	 * @var array<string, mixed>
	 */
	private array $body = [];

	/**
	 * A signed-in handler, a guard that allows, and an empty body.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->transfers = $this->createMock(originalClassName: CaseTransferService::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->groups = $this->createMock(originalClassName: IGroupManager::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);

		$this->signedInAs(uid: 'jan');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->body[$key] ?? $default)
		);
	}//end setUp()

	/**
	 * A caller the per-case guard refuses never reaches the service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	public function testACallerWithoutCaseAccessCannotHandTheCaseOn(): void {
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->transfers->expects(self::never())->method('handToTeam');
		$this->body = ['team' => 'toezicht', 'reason' => 'handhaving'];

		$response = $this->controller()->hand(caseId: 'case-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('case-access-denied', $response->getData()['error']);
	}//end testACallerWithoutCaseAccessCannotHandTheCaseOn()

	/**
	 * An anonymous caller is refused before anything else is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->transfers->expects(self::never())->method('handToTeam');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->hand(caseId: 'case-1')->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A handover with no reason is refused before the service is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	public function testAHandoverWithoutAReasonIsRefused(): void {
		$this->transfers->expects(self::never())->method('handToTeam');
		$this->body = ['team' => 'toezicht'];

		$response = $this->controller()->hand(caseId: 'case-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('handover-incomplete', $response->getData()['error']);
	}//end testAHandoverWithoutAReasonIsRefused()

	/**
	 * The act is passed on with the declaration the handler made, not a default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-doorzending-tells-the-applicant-where-the-case-went-req-hand-03
	 */
	public function testTheDoorzendingDeclarationReachesTheService(): void {
		$this->transfers->expects(self::once())
			->method('handToTeam')
			->with('case-1', 'toezicht', 'Awb 2:3', 'jan', true)
			->willReturn(['status' => 'pending']);
		$this->body = ['team' => 'toezicht', 'reason' => 'Awb 2:3', 'doorzending' => true];

		self::assertSame(Http::STATUS_OK, $this->controller()->hand(caseId: 'case-1')->getStatus());
	}//end testTheDoorzendingDeclarationReachesTheService()

	/**
	 * A refusal from the service reaches the caller with its own rule and status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	public function testARefusalKeepsItsRuleAndItsStatus(): void {
		$this->transfers->method('handToTeam')->willThrowException(
			new RefusedException(
				rule: TeamDirectory::UNRESOLVABLE,
				sentence: 'That team could not be found, so the case stays where it is.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			)
		);
		$this->body = ['team' => 'nergens', 'reason' => 'handhaving'];

		$response = $this->controller()->hand(caseId: 'case-1');

		// ADR-050: the rule in `error`, the author's sentence in `message`.
		// A generic 500 here is how "that team could not be found" became
		// "could not complete the request" everywhere it was not caught.
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(TeamDirectory::UNRESOLVABLE, $response->getData()['error']);
		self::assertStringContainsString('team', $response->getData()['message']);
	}//end testARefusalKeepsItsRuleAndItsStatus()

	/**
	 * Accepting a handover names the caller as the one who accepted it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function testAcceptingNamesTheCallerWhoAcceptedIt(): void {
		$this->transfers->expects(self::once())
			->method('acceptHandover')
			->with('transfer-1', 'jan')
			->willReturn(['status' => 'accepted']);

		self::assertSame(
			Http::STATUS_OK,
			$this->controller()->accept(caseId: 'case-1', transferId: 'transfer-1')->getStatus(),
		);
	}//end testAcceptingNamesTheCallerWhoAcceptedIt()

	/**
	 * A refusal back without a reason is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function testSendingItBackNeedsAReason(): void {
		$this->transfers->expects(self::never())->method('refuseHandover');

		$response = $this->controller()->refuse(caseId: 'case-1', transferId: 'transfer-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('refusal-needs-a-reason', $response->getData()['error']);
	}//end testSendingItBackNeedsAReason()

	/**
	 * Sending it back passes the reason and the caller on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function testSendingItBackPassesTheReasonOn(): void {
		$this->transfers->expects(self::once())
			->method('refuseHandover')
			->with('transfer-1', 'Dit is wel onze zaak', 'jan')
			->willReturn(['status' => 'rejected']);
		$this->body = ['reason' => 'Dit is wel onze zaak'];

		self::assertSame(
			Http::STATUS_OK,
			$this->controller()->refuse(caseId: 'case-1', transferId: 'transfer-1')->getStatus(),
		);
	}//end testSendingItBackPassesTheReasonOn()

	/**
	 * The outstanding list is scoped to a team the caller is actually in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function testTheOutstandingListRefusesATeamTheCallerIsNotIn(): void {
		$this->groups->method('getUserGroupIds')->willReturn(['vergunningen']);
		$this->transfers->expects(self::never())->method('outstandingHandovers');

		$response = $this->controller()->outstanding(team: 'toezicht');

		// An unscoped read would hand any signed-in user the number and title
		// of every case any team ever passed on.
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('not-in-the-team', $response->getData()['error']);
	}//end testTheOutstandingListRefusesATeamTheCallerIsNotIn()

	/**
	 * A member of the sending team sees what it handed on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	public function testAMemberOfTheSendingTeamSeesTheOutstandingHandovers(): void {
		$this->groups->method('getUserGroupIds')->willReturn(['vergunningen']);
		$this->transfers->expects(self::once())
			->method('outstandingHandovers')
			->with('vergunningen')
			->willReturn([['caseId' => 'case-1']]);

		$response = $this->controller()->outstanding(team: 'vergunningen');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['caseId' => 'case-1']], $response->getData()['results']);
	}//end testAMemberOfTheSendingTeamSeesTheOutstandingHandovers()

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
	 * The controller under test, built from the doubles as they now stand.
	 *
	 * @return CaseHandoverController The controller.
	 */
	private function controller(): CaseHandoverController {
		return new CaseHandoverController(
			appName: 'dossiq',
			request: $this->request,
			transfers: $this->transfers,
			accessGuard: $this->guard,
			groups: $this->groups,
			userSession: $this->userSession,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()
}//end class
