<?php

/**
 * StatusTransitionController Wire-Contract Tests
 *
 * Contract coverage for `POST /api/case/{caseId}/transition-freeform`
 * (gate-25) — the escape hatch that moves a case to ANY status, bypassing the
 * modelled transition graph. Its authorization is unusual and worth pinning
 * precisely:
 *
 *  - the session check answers **403**, not the 401 its siblings `available()`
 *    and `history()` use. That asymmetry is real, and a well-meaning
 *    "consistency" fix would silently change the wire contract, so the test
 *    asserts 403 and says why;
 *  - the admin-only rule is NOT enforced in the controller — it lives in
 *    `StatusTransitionService::executeFreeForm()` and surfaces as the
 *    `forbidden_admin_only` domain code. The test asserts that code maps to
 *    403, which is the only place that rule is observable from the wire;
 *  - a missing `toStatusId` is a 400 raised BEFORE the engine is entered;
 *  - `case_not_found` maps to 404, a DIFFERENT status from the 400 default
 *    arm of the same `match`;
 *  - none of the domain codes leak into the response body.
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

use OCA\Dossiq\Controller\StatusTransitionController;
use OCA\Dossiq\Service\BulkStatusTransitionService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\StatusTransitionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for StatusTransitionController::freeform().
 *
 * @covers \OCA\Dossiq\Controller\StatusTransitionController
 */
class StatusTransitionControllerContractTest extends TestCase {

	/**
	 * The IRequest mock — freeform() reads its body via getParams().
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The transition engine.
	 *
	 * @var StatusTransitionService|MockObject
	 */
	private StatusTransitionService $transitionEngine;

	/**
	 * The bulk engine (unused by freeform, required by the constructor).
	 *
	 * @var BulkStatusTransitionService|MockObject
	 */
	private BulkStatusTransitionService $bulkEngine;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The per-case guard (used by the sibling endpoints).
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The controller under test.
	 *
	 * @var StatusTransitionController
	 */
	private StatusTransitionController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->transitionEngine = $this->createMock(StatusTransitionService::class);
		$this->bulkEngine = $this->createMock(BulkStatusTransitionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$this->controller = new StatusTransitionController(
			appName: 'dossiq',
			request: $this->request,
			transitionEngine: $this->transitionEngine,
			bulkEngine: $this->bulkEngine,
			userSession: $this->userSession,
			logger: $this->logger,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session and pin the decoded body.
	 *
	 * @param array<string,mixed> $body The request params freeform() will read.
	 *
	 * @return void
	 */
	private function signInWithBody(array $body): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn($body);
	}//end signInWithBody()

	/**
	 * A session-less caller is refused with 403 — NOT the 401 the sibling
	 * read endpoints on this controller use.
	 *
	 * @return void
	 */
	public function testFreeformRefusesASessionLessCallerWith403NotTheSiblings401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->transitionEngine->expects($this->never())->method('executeFreeForm');

		$response = $this->controller->freeform(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertNotSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testFreeformRefusesASessionLessCallerWith403NotTheSiblings401()

	/**
	 * A body without `toStatusId` is a 400 and the engine is never entered.
	 *
	 * @return void
	 */
	public function testFreeformRejectsAMissingTargetStatusBeforeEnteringTheEngine(): void {
		$this->signInWithBody(['comment' => 'zonder doelstatus']);
		$this->transitionEngine->expects($this->never())->method('executeFreeForm');

		$response = $this->controller->freeform(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'toStatusId is required'], $response->getData());
	}//end testFreeformRejectsAMissingTargetStatusBeforeEnteringTheEngine()

	/**
	 * The case id comes from the URL and the target status plus comment come
	 * from the body; the engine's result is returned unwrapped.
	 *
	 * @return void
	 */
	public function testFreeformDelegatesTheUrlCaseIdWithTheBodyStatusAndComment(): void {
		$this->signInWithBody(['toStatusId' => 'afgehandeld', 'comment' => 'ambtshalve gesloten']);
		$result = ['caseId' => 'zaak-1', 'status' => 'afgehandeld'];

		$this->transitionEngine->expects($this->once())
			->method('executeFreeForm')
			->with('zaak-1', 'afgehandeld', 'ambtshalve gesloten')
			->willReturn($result);

		$response = $this->controller->freeform(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}//end testFreeformDelegatesTheUrlCaseIdWithTheBodyStatusAndComment()

	/**
	 * An absent comment is forwarded as null, not as an empty string — the
	 * engine records a comment field and '' would write an empty audit note.
	 *
	 * @return void
	 */
	public function testFreeformForwardsAnAbsentCommentAsNull(): void {
		$this->signInWithBody(['toStatusId' => 'afgehandeld']);

		$this->transitionEngine->expects($this->once())
			->method('executeFreeForm')
			->with('zaak-1', 'afgehandeld', $this->identicalTo(null))
			->willReturn([]);

		$this->controller->freeform(caseId: 'zaak-1');
	}//end testFreeformForwardsAnAbsentCommentAsNull()

	/**
	 * The engine's admin-only refusal reaches the wire as 403 — this is the
	 * ONLY place the admin rule of a free-form transition is observable.
	 *
	 * @return void
	 */
	public function testFreeformSurfacesTheEnginesAdminOnlyRefusalAs403(): void {
		$this->signInWithBody(['toStatusId' => 'afgehandeld']);

		$this->transitionEngine->method('executeFreeForm')
			->willThrowException(new \RuntimeException('forbidden_admin_only'));

		$response = $this->controller->freeform(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Could not execute free-form transition'], $response->getData());
	}//end testFreeformSurfacesTheEnginesAdminOnlyRefusalAs403()

	/**
	 * `case_not_found` is a 404, distinct from the 400 default arm.
	 *
	 * @return void
	 */
	public function testFreeformMapsAnUnknownCaseTo404AndAnUnknownCodeTo400(): void {
		$this->signInWithBody(['toStatusId' => 'afgehandeld']);

		$this->transitionEngine->method('executeFreeForm')
			->willReturnCallback(
				static function (string $caseId): array {
					throw new \RuntimeException($caseId === 'zaak-weg' ? 'case_not_found' : 'iets_anders');
				}
			);

		$notFound = $this->controller->freeform(caseId: 'zaak-weg');
		$other = $this->controller->freeform(caseId: 'zaak-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $notFound->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $other->getStatus());
	}//end testFreeformMapsAnUnknownCaseTo404AndAnUnknownCodeTo400()
}//end class
