<?php

/**
 * SubstitutionController Wire-Contract Tests
 *
 * Contract coverage for `GET /api/substitutions/work` and
 * `GET /api/substitutions/{id}/actions` (gate-25). Both are
 * `#[NoAdminRequired]`, so every guard is written in the controller body:
 *
 *  - both refuse a session-less caller with the guard's real **403** (this
 *    controller's refusal vocabulary is `forbidden()`, not a 401) and neither
 *    reaches its domain service;
 *  - `substitutedWork()` resolves the work list from the SESSION uid. There is
 *    no request parameter that can point it at another handler's queue, and
 *    the `->with()` on the session uid is what proves that — reading a
 *    `userId` parameter here would be a straight IDOR into a colleague's
 *    caseload;
 *  - `actions()` returns 404 for an unknown substitution BEFORE the audit
 *    service is entered, and 403 when `mayView()` refuses — a capacity-stamped
 *    action list names who did what on whose behalf, so the view check must
 *    run before the audit read, not after it;
 *  - the action list is wrapped under `results`.
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

use OCA\Dossiq\Controller\SubstitutionController;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Substitution\SubstitutionAccessGuard;
use OCA\Dossiq\Service\SubstitutionAuditService;
use OCA\Dossiq\Service\SubstitutionService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for SubstitutionController.
 *
 * @covers \OCA\Dossiq\Controller\SubstitutionController
 *
 * @uses \OCA\Dossiq\Service\Substitution\SubstitutionAccessGuard
 */
class SubstitutionControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The substitution domain service.
	 *
	 * @var SubstitutionService|MockObject
	 */
	private SubstitutionService $substitutionService;

	/**
	 * The capacity audit service.
	 *
	 * @var SubstitutionAuditService|MockObject
	 */
	private SubstitutionAuditService $auditService;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->substitutionService = $this->createMock(SubstitutionService::class);
		$this->auditService = $this->createMock(SubstitutionAuditService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller around the supplied guard.
	 *
	 * @param SubstitutionAccessGuard $accessGuard The guard to install.
	 *
	 * @return SubstitutionController
	 */
	private function controller(SubstitutionAccessGuard $accessGuard): SubstitutionController {
		return new SubstitutionController(
			appName: 'dossiq',
			request: $this->request,
			substitutionService: $this->substitutionService,
			auditService: $this->auditService,
			accessGuard: $accessGuard,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * A REAL guard wired to an empty session, so the 403 asserted is the
	 * application's own response rather than a stubbed one.
	 *
	 * @return SubstitutionAccessGuard
	 */
	private function realGuardWithoutSession(): SubstitutionAccessGuard {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return new SubstitutionAccessGuard(
			settingsService: $this->createMock(SettingsService::class),
			userSession: $userSession,
			groupManager: $this->createMock(IGroupManager::class),
		);
	}//end realGuardWithoutSession()

	/**
	 * Both read endpoints refuse a session-less caller with 403 and neither
	 * domain service is entered.
	 *
	 * @return void
	 */
	public function testBothReadEndpointsRefuseASessionLessCallerWith403(): void {
		$this->substitutionService->expects($this->never())->method('getSubstitutedWorkFor');
		$this->auditService->expects($this->never())->method('getActionsForSubstitution');

		$controller = $this->controller($this->realGuardWithoutSession());

		$work = $controller->substitutedWork();
		$actions = $controller->actions(id: 'verv-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $work->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $work->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $actions->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $actions->getData());
	}//end testBothReadEndpointsRefuseASessionLessCallerWith403()

	/**
	 * The substituted-work list is resolved for the SESSION uid.
	 *
	 * @return void
	 */
	public function testSubstitutedWorkResolvesForTheSessionUidAndNotForAnyInput(): void {
		$accessGuard = $this->createMock(SubstitutionAccessGuard::class);
		$accessGuard->method('currentUid')->willReturn('waarnemer');

		$work = [['caseId' => 'zaak-1', 'onBehalfOf' => 'afwezige']];

		$this->substitutionService->expects($this->once())
			->method('getSubstitutedWorkFor')
			->with('waarnemer')
			->willReturn($work);

		$response = $this->controller($accessGuard)->substitutedWork();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($work, $response->getData());
	}//end testSubstitutedWorkResolvesForTheSessionUidAndNotForAnyInput()

	/**
	 * An unknown substitution is a 404, and the audit trail is not read.
	 *
	 * @return void
	 */
	public function testActionsReturns404ForAnUnknownSubstitutionWithoutReadingTheAudit(): void {
		$accessGuard = $this->createMock(SubstitutionAccessGuard::class);
		$accessGuard->method('currentUid')->willReturn('waarnemer');
		$accessGuard->expects($this->once())->method('find')->with('verv-weg')->willReturn(null);

		$this->auditService->expects($this->never())->method('getActionsForSubstitution');

		$response = $this->controller($accessGuard)->actions(id: 'verv-weg');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Substitution not found'], $response->getData());
	}//end testActionsReturns404ForAnUnknownSubstitutionWithoutReadingTheAudit()

	/**
	 * A caller `mayView()` refuses gets the guard's 403, and the audit trail
	 * is not read — the view check runs BEFORE the audit read.
	 *
	 * @return void
	 */
	public function testActionsRefusesAnUnrelatedCallerBeforeReadingTheAuditTrail(): void {
		$row = ['id' => 'verv-1', 'absentee' => 'afwezige', 'substitute' => 'waarnemer'];

		$accessGuard = $this->createMock(SubstitutionAccessGuard::class);
		$accessGuard->method('currentUid')->willReturn('buitenstaander');
		$accessGuard->method('find')->willReturn($row);
		$accessGuard->expects($this->once())
			->method('mayView')
			->with($row, 'buitenstaander')
			->willReturn(false);
		$accessGuard->method('forbidden')
			->willReturn(new \OCP\AppFramework\Http\JSONResponse(
				['error' => 'Not authorised'],
				Http::STATUS_FORBIDDEN
			));

		$this->auditService->expects($this->never())->method('getActionsForSubstitution');

		$response = $this->controller($accessGuard)->actions(id: 'verv-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testActionsRefusesAnUnrelatedCallerBeforeReadingTheAuditTrail()

	/**
	 * An authorised caller gets the capacity-stamped actions under `results`.
	 *
	 * @return void
	 */
	public function testActionsWrapsTheCapacityStampedActionsUnderResults(): void {
		$row = ['id' => 'verv-1'];
		$actions = [['caseId' => 'zaak-1', 'capacity' => 'waarnemer', 'at' => '2026-08-01']];

		$accessGuard = $this->createMock(SubstitutionAccessGuard::class);
		$accessGuard->method('currentUid')->willReturn('waarnemer');
		$accessGuard->method('find')->willReturn($row);
		$accessGuard->method('mayView')->willReturn(true);

		$this->auditService->expects($this->once())
			->method('getActionsForSubstitution')
			->with('verv-1')
			->willReturn($actions);

		$response = $this->controller($accessGuard)->actions(id: 'verv-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $actions], $response->getData());
	}//end testActionsWrapsTheCapacityStampedActionsUnderResults()
}//end class
