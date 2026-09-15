<?php

/**
 * CaseTermsController wire contract.
 *
 * Five endpoints, and the guard matters more than the payload on every one of
 * them. `#[NoAdminRequired]` on its own would let any signed-in user read the
 * four clocks on any case id they can guess, and suspend a statutory term on a
 * case they cannot otherwise touch. So the least privileged caller that should
 * be refused is what drives these cases: a signed-in user the guard says no to.
 * A superuser success would prove almost nothing here.
 *
 * The second subject is the refusal shape. A rule slug is what a surface acts
 * on and a sentence is what a person reads, and neither may be replaced by an
 * exception message on its way out (ADR-050).
 *
 * The third is the failed letter. `requestInformation` answering 200 with
 * `sent: false` is how a request that never went out is read as a successful
 * pause by whatever renders the response next, so the status itself is pinned.
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseTermsController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\OpenWorkloadAgeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The five term endpoints, behind their guards.
 *
 * @covers \OCA\Dossiq\Controller\CaseTermsController
 */
class CaseTermsControllerContractTest extends TestCase {
	/**
	 * The four clocks.
	 *
	 * @var CaseTermsService&MockObject
	 */
	private CaseTermsService $terms;

	/**
	 * Asking and suspending as one act.
	 *
	 * @var InformationRequestService&MockObject
	 */
	private InformationRequestService $requests;

	/**
	 * The age of what is still standing.
	 *
	 * @var OpenWorkloadAgeService&MockObject
	 */
	private OpenWorkloadAgeService $workload;

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
	 * The controller under test.
	 *
	 * @var CaseTermsController
	 */
	private CaseTermsController $controller;

	/**
	 * Wire the controller with an ordinary signed-in caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->terms = $this->createMock(CaseTermsService::class);
		$this->requests = $this->createMock(InformationRequestService::class);
		$this->workload = $this->createMock(OpenWorkloadAgeService::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler1');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new CaseTermsController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			terms: $this->terms,
			requests: $this->requests,
			workload: $this->workload,
			guard: $this->guard,
			userSession: $this->userSession,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A signed-in user without read access on this case is refused, and the
	 * service is never asked.
	 *
	 * @return void
	 */
	public function testAUserWithoutReadAccessIsRefused(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(false);
		$this->terms->expects(self::never())->method('overviewFor');

		$response = $this->controller->index(caseId: 'c1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('case-read-refused', $response->getData()['error']);
	}//end testAUserWithoutReadAccessIsRefused()

	/**
	 * A user who may read the case gets the four clocks and the progress.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheClocksAndTheProgress(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->terms->method('overviewFor')->willReturn(
			[
				'case' => 'c1',
				'terms' => [['kind' => 'statutory', 'daysLeft' => 12]],
				'progress' => ['progress' => 40, 'daysLeft' => 12],
			]
		);

		$response = $this->controller->index(caseId: 'c1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(40, $response->getData()['progress']['progress']);
		self::assertCount(1, $response->getData()['terms']);
	}//end testAReaderGetsTheClocksAndTheProgress()

	/**
	 * The citizen read is guarded too, and answers only what it was given.
	 *
	 * @return void
	 */
	public function testTheCitizenReadIsGuardedAndAnswersWhatTheServiceFiltered(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->terms->method('citizenTermsFor')->willReturn([['kind' => 'statutory']]);

		$response = $this->controller->citizen(caseId: 'c1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['kind' => 'statutory']], $response->getData()['terms']);
	}//end testTheCitizenReadIsGuardedAndAnswersWhatTheServiceFiltered()

	/**
	 * Asking for information is a WRITE, so the mutation right is what is asked.
	 *
	 * @return void
	 */
	public function testAskingForInformationNeedsTheMutationRight(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->requests->expects(self::never())->method('ask');

		$response = $this->controller->requestInformation(caseId: 'c1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('case-change-refused', $response->getData()['error']);
	}//end testAskingForInformationNeedsTheMutationRight()

	/**
	 * A refusal comes back with its own status, its rule slug and its sentence.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsRuleAndItsStatus(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->requests->method('ask')->willThrowException(
			new RefusedException(
				rule: 'suspension-beyond-declared-maximum',
				sentence: 'This case type allows a suspension of at most 28 days, and you asked for 60.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			)
		);

		$response = $this->controller->requestInformation(caseId: 'c1');

		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $response->getStatus());
		self::assertSame('suspension-beyond-declared-maximum', $response->getData()['error']);
		self::assertStringContainsString('28', $response->getData()['message']);
	}//end testARefusalCarriesItsRuleAndItsStatus()

	/**
	 * A letter that did not go out does not answer 200.
	 *
	 * @return void
	 */
	public function testAFailedLetterDoesNotAnswerOk(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->requests->method('ask')->willReturn(
			[
				'sent' => false,
				'suspended' => false,
				'instance' => [],
				'record' => null,
				'error' => 'the transport is unreachable',
			]
		);

		$response = $this->controller->requestInformation(caseId: 'c1');

		self::assertSame(
			Http::STATUS_BAD_GATEWAY,
			$response->getStatus(),
			'A 200 here is read as a successful pause by whatever renders it next.'
		);
		self::assertFalse($response->getData()['suspended']);
	}//end testAFailedLetterDoesNotAnswerOk()

	/**
	 * A request that went out answers 200 with the suspension on it.
	 *
	 * @return void
	 */
	public function testARequestThatWentOutAnswersOk(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->requests->method('ask')->willReturn(
			[
				'sent' => true,
				'suspended' => true,
				'instance' => ['status' => 'paused'],
				'record' => ['type' => 'information-requested'],
				'error' => '',
			]
		);

		$response = $this->controller->requestInformation(caseId: 'c1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['suspended']);
	}//end testARequestThatWentOutAnswersOk()

	/**
	 * Recording the aanvulling needs the mutation right as well.
	 *
	 * @return void
	 */
	public function testRecordingTheAanvullingNeedsTheMutationRight(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->requests->expects(self::never())->method('receive');

		$response = $this->controller->receiveInformation(caseId: 'c1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testRecordingTheAanvullingNeedsTheMutationRight()

	/**
	 * The aanvulling resumes the term and answers what came back.
	 *
	 * @return void
	 */
	public function testTheAanvullingResumesTheTerm(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->requests->method('receive')->willReturn(
			['resumed' => true, 'instance' => ['status' => 'lopend'], 'record' => null]
		);

		$response = $this->controller->receiveInformation(caseId: 'c1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['resumed']);
	}//end testTheAanvullingResumesTheTerm()

	/**
	 * The workload report answers the age per status.
	 *
	 * @return void
	 */
	public function testTheWorkloadReportAnswersAnAgePerStatus(): void {
		$this->workload->method('report')->willReturn(
			[
				'generatedAt' => '2026-09-15',
				'openCases' => 3,
				'truncated' => false,
				'perStatus' => [['status' => 'In behandeling', 'cases' => 3, 'oldestDays' => 30]],
			]
		);

		$response = $this->controller->workloadAge();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(3, $response->getData()['openCases']);
	}//end testTheWorkloadReportAnswersAnAgePerStatus()

	/**
	 * A request carrying no session at all is refused, guard or no guard.
	 *
	 * @return void
	 */
	public function testAnAnonymousRequestIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new CaseTermsController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			terms: $this->terms,
			requests: $this->requests,
			workload: $this->workload,
			guard: $this->guard,
			userSession: $session,
			logger: new NullLogger(),
		);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->index(caseId: 'c1')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->workloadAge()->getStatus());
	}//end testAnAnonymousRequestIsRefused()
}//end class
