<?php

/**
 * CaseAssignmentController wire contract.
 *
 * The refusals come first, because they are the whole point of this
 * controller. A claim is the one gesture a case's own `assignee` cannot
 * authorize, since the person claiming is by definition not the handler yet,
 * so the guard asks whether the case is FREE and answers 409 when it is not.
 * Without that, Claim on a stale page would quietly take a case out of the
 * hands of the colleague who picked it up a second earlier, and nothing on
 * either screen would say so.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseAssignmentController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseAssignmentService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Claim and release behind their guards.
 *
 * @covers \OCA\Dossiq\Controller\CaseAssignmentController
 */
class CaseAssignmentControllerTest extends TestCase {

	/**
	 * Claim and release.
	 *
	 * @var CaseAssignmentService&MockObject
	 */
	private CaseAssignmentService $assignment;

	/**
	 * The per-case guard.
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
	 * Wire a controller with a signed-in handler by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->assignment = $this->createMock(CaseAssignmentService::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return CaseAssignmentController
	 */
	private function controller(): CaseAssignmentController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CaseAssignmentController(
			'dossiq',
			$this->createMock(IRequest::class),
			$this->assignment,
			$this->guard,
			$this->userSession,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * One assignment answer, as the service shapes it.
	 *
	 * @param string $assignee The handler on the case, empty when nobody holds it.
	 * @param string $userId   The signed-in user.
	 *
	 * @return array<string, mixed>
	 */
	private function assignmentState(string $assignee, string $userId = 'behandelaar'): array {
		return [
			'caseId' => 'case-1',
			'assignee' => $assignee,
			'mine' => ($assignee !== '' && $assignee === $userId),
			'claimable' => ($assignee === ''),
			'releasable' => ($assignee !== '' && $assignee === $userId),
		];
	}//end assignmentState()

	/**
	 * A claim on a case somebody else already holds is refused with 409 and
	 * the rule named, and the write never runs.
	 *
	 * @return void
	 */
	public function testClaimOnAnAssignedCaseIsRefusedWithAStatus(): void {
		$this->assignment->method('state')->willReturn($this->assignmentState(assignee: 'collega'));
		$this->assignment->expects($this->never())->method('claim');

		$response = $this->controller()->claim(caseId: 'case-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('already_assigned', $response->getData()['code']);
	}//end testClaimOnAnAssignedCaseIsRefusedWithAStatus()

	/**
	 * A claim on a case you already hold is refused too, and says which rule
	 * refused: "somebody has it" and "you have it" are different answers and a
	 * handler reading the toast needs to know which one they got.
	 *
	 * @return void
	 */
	public function testClaimOnYourOwnCaseIsRefusedAsAlreadyYours(): void {
		$this->assignment->method('state')->willReturn($this->assignmentState(assignee: 'behandelaar'));
		$this->assignment->expects($this->never())->method('claim');

		$response = $this->controller()->claim(caseId: 'case-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('already_yours', $response->getData()['code']);
	}//end testClaimOnYourOwnCaseIsRefusedAsAlreadyYours()

	/**
	 * A case the caller cannot resolve answers 404 rather than an existence
	 * oracle, and the write never runs.
	 *
	 * @return void
	 */
	public function testClaimOnAnUnreadableCaseIsNotFound(): void {
		$this->assignment->method('state')->willThrowException(new RuntimeException('case_not_found'));
		$this->assignment->expects($this->never())->method('claim');

		$response = $this->controller()->claim(caseId: 'case-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('case_not_found', $response->getData()['code']);
	}//end testClaimOnAnUnreadableCaseIsNotFound()

	/**
	 * The guard is asked with the case from the URL and the session user, not
	 * with anything the caller supplied.
	 *
	 * @return void
	 */
	public function testTheClaimGuardReadsTheRoutesCaseAsTheSessionUser(): void {
		$seen = [];
		$this->assignment->method('state')->willReturnCallback(
			function (string $caseId, string $userId) use (&$seen): array {
				$seen = [$caseId, $userId];
				return $this->assignmentState(assignee: 'collega');
			}
		);

		$this->controller()->claim(caseId: 'case-42');

		$this->assertSame(['case-42', 'behandelaar'], $seen);
	}//end testTheClaimGuardReadsTheRoutesCaseAsTheSessionUser()

	/**
	 * A free case is claimed, and the answer says it is now yours.
	 *
	 * @return void
	 */
	public function testClaimOnAFreeCaseRuns(): void {
		$this->assignment->method('state')->willReturn($this->assignmentState(assignee: ''));
		$this->assignment->expects($this->once())
			->method('claim')
			->with('case-1', 'behandelaar')
			->willReturn($this->assignmentState(assignee: 'behandelaar'));

		$response = $this->controller()->claim(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['mine']);
	}//end testClaimOnAFreeCaseRuns()

	/**
	 * A second claimer who slipped past the read is still refused by the
	 * service, and the controller gives that refusal its status.
	 *
	 * @return void
	 */
	public function testAClaimThatLosesTheRaceStillAnswers409(): void {
		$this->assignment->method('state')->willReturn($this->assignmentState(assignee: ''));
		$this->assignment->method('claim')->willThrowException(new RuntimeException('already_assigned'));

		$response = $this->controller()->claim(caseId: 'case-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('already_assigned', $response->getData()['code']);
	}//end testAClaimThatLosesTheRaceStillAnswers409()

	/**
	 * Release consults the per-case guard first: a user who may not mutate the
	 * case is refused with 403 and the gesture never runs.
	 *
	 * @return void
	 */
	public function testReleaseIsRefusedWithoutCaseAccess(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->assignment->expects($this->never())->method('release');

		$response = $this->controller()->release(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testReleaseIsRefusedWithoutCaseAccess()

	/**
	 * Past the guard, releasing a case that is somebody else's is refused with
	 * the rule named. An admin passes the guard and still cannot take a case
	 * out of a colleague's hands this way.
	 *
	 * @return void
	 */
	public function testReleaseOfSomebodyElsesCaseIsRefusedWithAStatus(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->assignment->method('release')->willThrowException(new RuntimeException('not_yours'));

		$response = $this->controller()->release(caseId: 'case-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('not_yours', $response->getData()['code']);
	}//end testReleaseOfSomebodyElsesCaseIsRefusedWithAStatus()

	/**
	 * Releasing your own case runs and answers a case with no handler.
	 *
	 * @return void
	 */
	public function testReleaseOfYourOwnCaseRuns(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->assignment->expects($this->once())
			->method('release')
			->with('case-1', 'behandelaar')
			->willReturn($this->assignmentState(assignee: ''));

		$response = $this->controller()->release(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('', $response->getData()['assignee']);
		$this->assertTrue($response->getData()['claimable']);
	}//end testReleaseOfYourOwnCaseRuns()

	/**
	 * An anonymous caller is 401 on every method, before any guard or gesture.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsUnauthenticated(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->userSession = $session;
		$this->assignment->expects($this->never())->method('state');
		$this->assignment->expects($this->never())->method('claim');
		$this->guard->expects($this->never())->method('hasCaseMutationAccess');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->claim(caseId: 'case-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->release(caseId: 'case-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->state(caseId: 'case-1')->getStatus());
	}//end testAnonymousCallerIsUnauthenticated()

	/**
	 * The read answers who holds the case, for a surface that wants to ask
	 * before it offers anything.
	 *
	 * @return void
	 */
	public function testStateAnswersWhoHoldsTheCase(): void {
		$this->assignment->method('state')->willReturn($this->assignmentState(assignee: 'collega'));

		$response = $this->controller()->state(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('collega', $response->getData()['assignee']);
		$this->assertFalse($response->getData()['mine']);
		$this->assertFalse($response->getData()['claimable']);
	}//end testStateAnswersWhoHoldsTheCase()
}//end class
