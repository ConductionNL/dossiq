<?php

/**
 * ComplaintDispositionController Wire-Contract Tests
 *
 * Contract coverage for `POST /api/complaints/{id}/disposition/letter`
 * (gate-25) — the endpoint that generates the formal Awb chapter 9 response
 * letter a complainant actually receives. It is `@NoAdminRequired` and takes a
 * complaint id straight off the URL, so the ordering of its four guards is the
 * whole contract. These tests pin:
 *
 *  - 401 without a session, from the guard's own response, before either
 *    domain service is touched;
 *  - 404 for an unknown complaint, with NO letter generated;
 *  - the per-object authorization is applied to THE LOADED COMPLAINT and the
 *    SESSION UID, and an `OCSForbiddenException` from it PROPAGATES rather
 *    than being swallowed into a success — a `try` placed one line too early
 *    would turn a refusal into a generated letter;
 *  - a complaint with no disposition yet is a 400 ("submit first"), NOT the
 *    404 used for a missing complaint — the two are different remedies for
 *    the caseworker;
 *  - the letter is generated for the disposition belonging to that complaint,
 *    with the disposition's own id, not the complaint id.
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

use OCA\Procest\Controller\ComplaintDispositionController;
use OCA\Procest\Service\Complaint\ComplaintAccessGuard;
use OCA\Procest\Service\ComplaintService;
use OCA\Procest\Service\DispositionService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for ComplaintDispositionController::generateLetter().
 *
 * @covers \OCA\Procest\Controller\ComplaintDispositionController
 *
 * @uses \OCA\Procest\Service\Complaint\ComplaintAccessGuard
 */
class ComplaintDispositionControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The complaint service.
	 *
	 * @var ComplaintService|MockObject
	 */
	private ComplaintService $complaintService;

	/**
	 * The disposition service.
	 *
	 * @var DispositionService|MockObject
	 */
	private DispositionService $dispositionService;

	/**
	 * The settings service (unused by generateLetter, required by the ctor).
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * Build the shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->complaintService = $this->createMock(ComplaintService::class);
		$this->dispositionService = $this->createMock(DispositionService::class);
		$this->settingsService = $this->createMock(SettingsService::class);
	}//end setUp()

	/**
	 * Build the controller around the supplied access guard.
	 *
	 * @param ComplaintAccessGuard $accessGuard The guard to install.
	 *
	 * @return ComplaintDispositionController
	 */
	private function controller(ComplaintAccessGuard $accessGuard): ComplaintDispositionController {
		return new ComplaintDispositionController(
			appName: 'procest',
			request: $this->request,
			complaintService: $this->complaintService,
			dispositionService: $this->dispositionService,
			settingsService: $this->settingsService,
			accessGuard: $accessGuard,
		);
	}//end controller()

	/**
	 * A REAL guard wired to an empty session, so its 401 is the app's own.
	 *
	 * @return ComplaintAccessGuard
	 */
	private function realGuardWithoutSession(): ComplaintAccessGuard {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return new ComplaintAccessGuard(
			request: $this->request,
			userSession: $userSession,
			groupManager: $this->createMock(IGroupManager::class),
		);
	}//end realGuardWithoutSession()

	/**
	 * A REAL guard wired to a signed-in, non-admin session.
	 *
	 * @param string $uid The signed-in user id.
	 *
	 * @return ComplaintAccessGuard
	 */
	private function realGuardSignedInAs(string $uid): ComplaintAccessGuard {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		return new ComplaintAccessGuard(
			request: $this->request,
			userSession: $userSession,
			groupManager: $groupManager,
		);
	}//end realGuardSignedInAs()

	/**
	 * Without a session the endpoint returns the guard's 401 and neither
	 * domain service is consulted.
	 *
	 * @return void
	 */
	public function testGenerateLetterRefusesAnUnauthenticatedCallerBeforeAnyLookup(): void {
		$this->complaintService->expects($this->never())->method('getComplaint');
		$this->dispositionService->expects($this->never())->method('getDispositionForComplaint');
		$this->dispositionService->expects($this->never())->method('generateResponseLetter');

		$response = $this->controller($this->realGuardWithoutSession())->generateLetter(id: 'klacht-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testGenerateLetterRefusesAnUnauthenticatedCallerBeforeAnyLookup()

	/**
	 * An unknown complaint is a 404 and produces no letter.
	 *
	 * @return void
	 */
	public function testGenerateLetterReturns404ForAnUnknownComplaintAndWritesNoLetter(): void {
		$this->complaintService->expects($this->once())
			->method('getComplaint')
			->with('klacht-onbekend')
			->willReturn(null);

		$this->dispositionService->expects($this->never())->method('generateResponseLetter');

		$response = $this->controller($this->realGuardSignedInAs('behandelaar'))
			->generateLetter(id: 'klacht-onbekend');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Complaint not found'], $response->getData());
	}//end testGenerateLetterReturns404ForAnUnknownComplaintAndWritesNoLetter()

	/**
	 * The per-object guard is applied to the loaded complaint and the session
	 * uid, and its refusal is NOT swallowed into a generated letter.
	 *
	 * @return void
	 */
	public function testGenerateLetterAuthorizesTheLoadedComplaintAndLetsARefusalPropagate(): void {
		$complaint = ['id' => 'klacht-1', 'handler' => 'someone-else'];

		$this->complaintService->method('getComplaint')->willReturn($complaint);

		$accessGuard = $this->createMock(ComplaintAccessGuard::class);
		$accessGuard->method('currentUid')->willReturn('intruder');
		$accessGuard->expects($this->once())
			->method('authorizeMutation')
			->with($complaint, 'intruder')
			->willThrowException(new OCSForbiddenException('Not authorized to modify this complaint'));

		$this->dispositionService->expects($this->never())->method('getDispositionForComplaint');
		$this->dispositionService->expects($this->never())->method('generateResponseLetter');

		$this->expectException(OCSForbiddenException::class);

		$this->controller($accessGuard)->generateLetter(id: 'klacht-1');
	}//end testGenerateLetterAuthorizesTheLoadedComplaintAndLetsARefusalPropagate()

	/**
	 * A complaint whose disposition has not been submitted yet is a 400 with
	 * the "submit first" instruction — a DIFFERENT status from the 404 used
	 * for a missing complaint.
	 *
	 * @return void
	 */
	public function testGenerateLetterReturns400WhenNoDispositionHasBeenSubmittedYet(): void {
		$this->complaintService->method('getComplaint')->willReturn(['id' => 'klacht-1']);
		$this->dispositionService->method('getDispositionForComplaint')->willReturn(null);
		$this->dispositionService->expects($this->never())->method('generateResponseLetter');

		$response = $this->controller($this->realGuardSignedInAs('behandelaar'))
			->generateLetter(id: 'klacht-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'Submit disposition before generating a letter'],
			$response->getData()
		);
	}//end testGenerateLetterReturns400WhenNoDispositionHasBeenSubmittedYet()

	/**
	 * The letter is generated from the complaint id AND the disposition's own
	 * id — passing the complaint id twice would address the wrong record.
	 *
	 * @return void
	 */
	public function testGenerateLetterPairsTheComplaintIdWithTheDispositionsOwnId(): void {
		$this->complaintService->method('getComplaint')->willReturn(['id' => 'klacht-1']);
		$this->dispositionService->method('getDispositionForComplaint')
			->willReturn(['id' => 'afdoening-9', 'uuid' => 'afdoening-9']);

		$letter = ['fileId' => 4711, 'status' => 'generated'];

		$this->dispositionService->expects($this->once())
			->method('generateResponseLetter')
			->with('klacht-1', 'afdoening-9')
			->willReturn($letter);

		$response = $this->controller($this->realGuardSignedInAs('behandelaar'))
			->generateLetter(id: 'klacht-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($letter, $response->getData());
	}//end testGenerateLetterPairsTheComplaintIdWithTheDispositionsOwnId()
}//end class
