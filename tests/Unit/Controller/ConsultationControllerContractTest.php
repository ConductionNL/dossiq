<?php

/**
 * ConsultationController Wire-Contract Tests
 *
 * Contract coverage for `GET /api/consultations/overdue` (gate-25). Unlike its
 * sibling endpoints this one takes NO id: it is a cross-case listing of every
 * adviesaanvraag past its Awb 3:6 deadline, so the authentication branch is
 * the only thing between an anonymous caller and a list of overdue advisory
 * requests across the whole organisation. These tests pin:
 *
 *  - the refusal is the guard's real 401 and it happens BEFORE
 *    ConsultationService is consulted (the guard is instantiated for real, so
 *    a stubbed-away status cannot manufacture the pass);
 *  - the payload is nested under `results`, not returned bare — the sibling
 *    `show()` returns its object bare, so the two shapes are easy to confuse.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ConsultationController;
use OCA\Dossiq\Service\Consultation\ConsultationAccessGuard;
use OCA\Dossiq\Service\Consultation\ExternalConsultationLinkService;
use OCA\Dossiq\Service\ConsultationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for ConsultationController::overdue().
 *
 * @covers \OCA\Dossiq\Controller\ConsultationController
 *
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationAccessGuard
 */
class ConsultationControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The consultation domain service.
	 *
	 * @var ConsultationService|MockObject
	 */
	private ConsultationService $consultationService;

	/**
	 * The user session driving the REAL access guard.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager driving the REAL access guard.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->consultationService = $this->createMock(ConsultationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * Build the controller behind a REAL ConsultationAccessGuard, so the 401
	 * asserted below is the guard's own response and not a test stub.
	 *
	 * @return ConsultationController
	 */
	private function controller(?ExternalConsultationLinkService $externalLinks = null): ConsultationController {
		return new ConsultationController(
			appName: 'dossiq',
			request: $this->request,
			consultationService: $this->consultationService,
			accessGuard: new ConsultationAccessGuard(
				request: $this->request,
				consultationService: $this->consultationService,
				userSession: $this->userSession,
				groupManager: $this->groupManager,
			),
			externalLinks: ($externalLinks ?? $this->createMock(ExternalConsultationLinkService::class)),
		);
	}//end controller()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @param string $uid The UID of the signed-in user.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'adviseur'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * An unauthenticated caller gets 401 and no overdue list is assembled.
	 *
	 * @return void
	 */
	public function testOverdueRefusesAnUnauthenticatedCallerBeforeListingAnyConsultation(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->consultationService->expects($this->never())->method('getOverdueConsultations');

		$response = $this->controller()->overdue();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testOverdueRefusesAnUnauthenticatedCallerBeforeListingAnyConsultation()

	/**
	 * An authenticated caller gets the overdue set wrapped under `results`.
	 *
	 * @return void
	 */
	public function testOverdueWrapsTheOverdueConsultationsUnderResults(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('adviseur');
		$this->userSession->method('getUser')->willReturn($user);

		$overdue = [
			['id' => 'cn-1', 'deadline' => '2026-01-01'],
			['id' => 'cn-2', 'deadline' => '2026-02-01'],
		];

		$this->consultationService->expects($this->once())
			->method('getOverdueConsultations')
			->willReturn($overdue);

		$response = $this->controller()->overdue();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $overdue], $response->getData());
	}//end testOverdueWrapsTheOverdueConsultationsUnderResults()

	/**
	 * An empty overdue set is still the `results` envelope, never a bare array
	 * or a 404 — the frontend reads `.results` unconditionally.
	 *
	 * @return void
	 */
	public function testOverdueReturnsAnEmptyResultsEnvelopeWhenNothingIsOverdue(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('adviseur');
		$this->userSession->method('getUser')->willReturn($user);

		$this->consultationService->method('getOverdueConsultations')->willReturn([]);

		$response = $this->controller()->overdue();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => []], $response->getData());
	}//end testOverdueReturnsAnEmptyResultsEnvelopeWhenNothingIsOverdue()

	/**
	 * `externalLink` refuses an anonymous caller with 401 and publishes
	 * nothing. The case behind a consultation is what this endpoint hands to
	 * somebody with no account, so the session is the whole guard.
	 *
	 * @return void
	 */
	public function testExternalLinkRefusesAnUnauthenticatedCallerBeforePublishingAnything(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$externalLinks = $this->createMock(ExternalConsultationLinkService::class);
		$externalLinks->expects($this->never())->method('invite');

		$response = $this->controller($externalLinks)->externalLink(id: 'cn-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testExternalLinkRefusesAnUnauthenticatedCallerBeforePublishingAnything()

	/**
	 * A caller who is neither the applicant, the assignee nor an admin is
	 * refused 403, and nothing is minted on the way to the refusal.
	 *
	 * @return void
	 */
	public function testExternalLinkRefusesACallerWithNoClaimOnTheConsultation(): void {
		$this->signIn(uid: 'mallory');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->consultationService->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'applicant' => 'anja', 'assignee' => 'bram']
		);

		$externalLinks = $this->createMock(ExternalConsultationLinkService::class);
		$externalLinks->expects($this->never())->method('invite');

		$response = $this->controller($externalLinks)->externalLink(id: 'cn-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testExternalLinkRefusesACallerWithNoClaimOnTheConsultation()

	/**
	 * An authorised invitation answers 201 with the address to send.
	 *
	 * @return void
	 */
	public function testExternalLinkAnswers201WithTheAddressToSend(): void {
		$this->signIn(uid: 'anja');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->consultationService->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'applicant' => 'anja']
		);
		$this->request->method('getParam')->willReturn(null);

		$externalLinks = $this->createMock(ExternalConsultationLinkService::class);
		$externalLinks->expects($this->once())
			->method('invite')
			->willReturn(['url' => 'https://example.test/l/abc']);

		$response = $this->controller($externalLinks)->externalLink(id: 'cn-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('https://example.test/l/abc', $response->getData()['url']);
	}//end testExternalLinkAnswers201WithTheAddressToSend()

	/**
	 * A consultation that cannot carry a link answers 400 with the reason,
	 * never a 500 and never a success with no link in it.
	 *
	 * @return void
	 */
	public function testExternalLinkReportsARefusalAs400(): void {
		$this->signIn(uid: 'anja');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->consultationService->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'applicant' => 'anja']
		);

		$externalLinks = $this->createMock(ExternalConsultationLinkService::class);
		$externalLinks->method('invite')
			->willThrowException(new \RuntimeException('Name the advisory body before you invite it'));

		$response = $this->controller($externalLinks)->externalLink(id: 'cn-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Name the advisory body before you invite it', $response->getData()['error']);
	}//end testExternalLinkReportsARefusalAs400()

	/**
	 * `collectAdvice` refuses an anonymous caller with 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testCollectAdviceRefusesAnUnauthenticatedCallerBeforeReadingAnything(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$externalLinks = $this->createMock(ExternalConsultationLinkService::class);
		$externalLinks->expects($this->never())->method('collect');

		$response = $this->controller($externalLinks)->collectAdvice(id: 'cn-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testCollectAdviceRefusesAnUnauthenticatedCallerBeforeReadingAnything()

	/**
	 * Nothing new to collect is a 200 saying so, not a 404: the handler asked
	 * a question and got an answer, and "the body has not replied yet" is an
	 * answer.
	 *
	 * @return void
	 */
	public function testCollectAdviceAnswers200WhenThereIsNothingNew(): void {
		$this->signIn(uid: 'anja');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->consultationService->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'applicant' => 'anja']
		);

		$externalLinks = $this->createMock(ExternalConsultationLinkService::class);
		$externalLinks->method('collect')->willReturn(['collected' => false]);

		$response = $this->controller($externalLinks)->collectAdvice(id: 'cn-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['collected']);
	}//end testCollectAdviceAnswers200WhenThereIsNothingNew()

	/**
	 * A collected comment answers 200 naming the advisory body it came from.
	 *
	 * @return void
	 */
	public function testCollectAdviceNamesTheAdvisoryBodyItCameFrom(): void {
		$this->signIn(uid: 'anja');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->consultationService->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'applicant' => 'anja']
		);

		$externalLinks = $this->createMock(ExternalConsultationLinkService::class);
		$externalLinks->method('collect')->willReturn(
			['collected' => true, 'advisoryBody' => 'Brandweer', 'noteId' => 31]
		);

		$response = $this->controller($externalLinks)->collectAdvice(id: 'cn-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Brandweer', $response->getData()['advisoryBody']);
	}//end testCollectAdviceNamesTheAdvisoryBodyItCameFrom()

	/**
	 * An outcome the consultation does not recognise is a 400, never a
	 * recorded answer.
	 *
	 * @return void
	 */
	public function testCollectAdviceReportsAnUnknownOutcomeAs400(): void {
		$this->signIn(uid: 'anja');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->consultationService->method('getConsultation')->willReturn(
			['id' => 'cn-1', 'applicant' => 'anja']
		);

		$externalLinks = $this->createMock(ExternalConsultationLinkService::class);
		$externalLinks->method('collect')
			->willThrowException(new \RuntimeException('Invalid advice type: maybe'));

		$response = $this->controller($externalLinks)->collectAdvice(id: 'cn-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid advice type: maybe', $response->getData()['error']);
	}//end testCollectAdviceReportsAnUnknownOutcomeAs400()
}//end class
