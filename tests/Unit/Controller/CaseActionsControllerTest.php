<?php

/**
 * CaseActionsController wire contract.
 *
 * The refusals come first, because they are the whole point of this
 * controller. Four `#[NoAdminRequired]` endpoints that take a case uuid from
 * the URL are four ways for any signed-in user to act on any case unless every
 * one of them consults the per-case guard BEFORE the service. The excluded
 * e2e scenario "a reader cannot copy" is asserted here: Playwright runs as
 * admin and cannot take a lesser role.
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

use OCA\Dossiq\Controller\CaseActionsController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseCopyService;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Copy, Start and Plan follow-up behind their guards.
 *
 * @covers \OCA\Dossiq\Controller\CaseActionsController
 */
class CaseActionsControllerTest extends TestCase {

	/**
	 * The copy service.
	 *
	 * @var CaseCopyService&MockObject
	 */
	private CaseCopyService $copyService;

	/**
	 * The flow gestures.
	 *
	 * @var CaseFlowActions&MockObject
	 */
	private CaseFlowActions $flowActions;

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
	 * The request, carrying the posted body.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The parameters the mocked request answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Wire a controller with a signed-in user by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->copyService = $this->createMock(CaseCopyService::class);
		$this->flowActions = $this->createMock(CaseFlowActions::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);

		$this->params = [
			'title' => 'Copy of Dakkapel',
			'documents' => true,
			'caseType' => 'type-controle',
			'date' => '2026-10-15',
		];

		// A NON-static closure reading `$this->params`, deliberately: an arrow
		// function captures by VALUE, so it would answer with the parameters as
		// they stood in setUp() and a test that changes one afterwards would
		// silently assert the old value.
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null): mixed {
				return ($this->params[$key] ?? $default);
			}
		);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return CaseActionsController The controller.
	 */
	private function controller(): CaseActionsController {
		return new CaseActionsController(
			'dossiq',
			$this->request,
			$this->copyService,
			$this->flowActions,
			$this->guard,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A reader who may not mutate the case is refused with 403, and no case
	 * is created.
	 *
	 * @return void
	 */
	public function testCopyIsRefusedWithoutMutationAccess(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->copyService->expects($this->never())->method('copy');

		$response = $this->controller()->copy(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCopyIsRefusedWithoutMutationAccess()

	/**
	 * Planning is a write, so it takes the mutation guard too.
	 *
	 * @return void
	 */
	public function testPlanIsRefusedWithoutMutationAccess(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->flowActions->expects($this->never())->method('plan');

		$response = $this->controller()->plan(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testPlanIsRefusedWithoutMutationAccess()

	/**
	 * The two reads take the read guard, and refuse without it.
	 *
	 * @return void
	 */
	public function testTheReadsAreRefusedWithoutReadAccess(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(false);
		$this->flowActions->expects($this->never())->method('startableFlows');
		$this->flowActions->expects($this->never())->method('planned');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->startableFlows(caseId: 'case-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->planned(caseId: 'case-1')->getStatus());
	}//end testTheReadsAreRefusedWithoutReadAccess()

	/**
	 * With no session there is no identity to guard against, so the answer is
	 * 401 rather than a guard call that would have to invent one.
	 *
	 * @return void
	 */
	public function testEveryGestureNeedsASession(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->guard->expects($this->never())->method('hasCaseMutationAccess');

		$controller = new CaseActionsController(
			'dossiq',
			$this->request,
			$this->copyService,
			$this->flowActions,
			$this->guard,
			$session,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->copy(caseId: 'case-1')->getStatus());
	}//end testEveryGestureNeedsASession()

	/**
	 * The guard is asked with the case from the URL and the session user,
	 * never with anything the caller supplied.
	 *
	 * @return void
	 */
	public function testTheGuardIsAskedWithTheRoutesCaseAndTheSessionUser(): void {
		$seen = [];
		$this->guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId, IUser $user) use (&$seen): bool {
				$seen = [$caseId, $user->getUID()];

				return false;
			}
		);

		$this->controller()->copy(caseId: 'case-42');

		$this->assertSame(['case-42', 'behandelaar'], $seen);
	}//end testTheGuardIsAskedWithTheRoutesCaseAndTheSessionUser()

	/**
	 * An allowed copy passes the title and the documents flag through.
	 *
	 * @return void
	 */
	public function testCopyForwardsTheTitleAndTheDocumentsFlag(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$seen = [];
		$this->copyService->method('copy')->willReturnCallback(
			function (string $caseId, array $options) use (&$seen): array {
				$seen = [$caseId, $options];

				return ['id' => 'case-2'];
			}
		);

		$response = $this->controller()->copy(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['case-1', ['title' => 'Copy of Dakkapel', 'documents' => true]], $seen);
	}//end testCopyForwardsTheTitleAndTheDocumentsFlag()

	/**
	 * `documents` arriving as the STRING "false" means no.
	 *
	 * A bare `(bool)` cast reads "false" as true, which on this endpoint means
	 * linking documents onto a case nobody asked to link them to.
	 *
	 * @return void
	 */
	public function testTheDocumentsFlagReadsAStringFalseAsFalse(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->params['documents'] = 'false';
		$seen = false;
		$this->copyService->method('copy')->willReturnCallback(
			function (string $caseId, array $options) use (&$seen): array {
				$seen = $options['documents'];

				return ['id' => 'case-2'];
			}
		);

		$this->controller()->copy(caseId: 'case-1');

		$this->assertFalse($seen);
	}//end testTheDocumentsFlagReadsAStringFalseAsFalse()

	/**
	 * Plan hands the service the session user as the identity its run will
	 * act as, not one the caller named.
	 *
	 * @return void
	 */
	public function testPlanRunsAsTheSessionUser(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$seen = '';
		$this->flowActions->method('plan')->willReturnCallback(
			function (string $caseId, string $caseTypeId, string $date, string $title, string $uid) use (&$seen): array {
				$seen = $uid;

				return ['id' => 'flow-1', 'title' => $title, 'date' => $date, 'caseType' => $caseTypeId];
			}
		);

		$this->controller()->plan(caseId: 'case-1');

		$this->assertSame('behandelaar', $seen);
	}//end testPlanRunsAsTheSessionUser()

	/**
	 * A service refusal becomes its own status and a short static code, not
	 * the exception's message.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsCodeAndStatus(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->copyService->method('copy')->willThrowException(new RuntimeException('case_not_found'));

		$response = $this->controller()->copy(caseId: 'case-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('case_not_found', $response->getData()['code']);
	}//end testARefusalCarriesItsCodeAndStatus()

	/**
	 * An invalid date is a 400 with its code, so the dialog can say which
	 * field is wrong.
	 *
	 * @return void
	 */
	public function testAnInvalidPlanDateIsABadRequest(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->flowActions->method('plan')->willThrowException(new RuntimeException('invalid_date'));

		$response = $this->controller()->plan(caseId: 'case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_date', $response->getData()['code']);
	}//end testAnInvalidPlanDateIsABadRequest()
}//end class
