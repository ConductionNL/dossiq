<?php

/**
 * MandaatMatrixController Wire-Contract Tests
 *
 * Contract coverage for the seven mandaat-matrix endpoints that had no
 * automated proof of their wire behaviour (gate-25): `probe`, `importPreview`,
 * `importApprove`, `escalateApprove`, `escalateReject`, `auditTrail` and
 * `applicable`.
 *
 * The controller does NOT have one uniform auth posture, and that asymmetry is
 * the thing worth pinning:
 *
 *  - six of the seven call `ensureAuthenticated()`, which answers 403 (NOT 401)
 *    with `{"message":"Not authenticated"}` — a client that branches on 401
 *    would never see it;
 *  - `escalateApprove` deliberately does NOT. It resolves the caller id from
 *    the session and hands it to `MandaatEscalatieService::approveEscalatie()`,
 *    which re-checks that the caller is the resolved mandate holder (see the
 *    class docblock: per-object IDOR guards live in the services). An anonymous
 *    caller therefore reaches the service with an EMPTY user id and is refused
 *    there, surfacing as 403. That is the design, and it is only safe as long
 *    as an empty uid can never match a mandate holder — so the empty uid
 *    reaching the service is asserted explicitly rather than left implied;
 *  - `applicable` fails SOFT: a service failure is logged and answered as an
 *    empty list at 200, because an empty mandate list only hides actions from
 *    the UI. Every other endpoint fails hard with 400/403.
 *
 * Not covered here: the accepted-body paths of `probe`, `importPreview` and
 * `escalateReject`. Those three read their body through the file-local
 * `jsonBody()` helper, which reads `php://input` directly with no injectable
 * seam, so a unit test can only drive them with an EMPTY body. The refusal and
 * the missing-input branch are covered; the accepted-body branch is honestly
 * out of reach at this layer.
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

use OCA\Dossiq\Controller\MandaatMatrixController;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\MandaatEscalatieService;
use OCA\Dossiq\Service\MandaatGebruikService;
use OCA\Dossiq\Service\MandaatImportService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Wire-contract tests for MandaatMatrixController.
 *
 * @covers \OCA\Dossiq\Controller\MandaatMatrixController
 */
class MandaatMatrixControllerContractTest extends TestCase {

	/**
	 * The inbound request mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The session mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The authorization-check service mock.
	 *
	 * @var MandaatCheckService|MockObject
	 */
	private MandaatCheckService $check;

	/**
	 * The escalation service mock — the per-object guard for escalateApprove.
	 *
	 * @var MandaatEscalatieService|MockObject
	 */
	private MandaatEscalatieService $escalation;

	/**
	 * The mandate-usage (audit trail) service mock.
	 *
	 * @var MandaatGebruikService|MockObject
	 */
	private MandaatGebruikService $gebruik;

	/**
	 * The CSV import service mock.
	 *
	 * @var MandaatImportService|MockObject
	 */
	private MandaatImportService $import;

	/**
	 * The settings service mock (OpenRegister access).
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settings;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var MandaatMatrixController
	 */
	private MandaatMatrixController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->check = $this->createMock(MandaatCheckService::class);
		$this->escalation = $this->createMock(MandaatEscalatieService::class);
		$this->gebruik = $this->createMock(MandaatGebruikService::class);
		$this->import = $this->createMock(MandaatImportService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new MandaatMatrixController(
			appName: 'dossiq',
			request: $this->request,
			userSession: $this->userSession,
			check: $this->check,
			escalation: $this->escalation,
			gebruik: $this->gebruik,
			import: $this->import,
			settings: $this->settings,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Mark the session as authenticated for caseworker `alice`.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * Feed the request parameter bag.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
	}//end withParams()

	/**
	 * The six `ensureAuthenticated()` endpoints answer 403 — not 401 — with
	 * `{"message":"Not authenticated"}` for a caller without a session, and no
	 * mandate, escalation, import or audit-trail service is reached.
	 *
	 * @return void
	 */
	public function testTheGuardedEndpointsRefuseAnAnonymousCallerWith403(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams([]);

		$this->check->expects($this->never())->method('isAuthorized');
		$this->check->expects($this->never())->method('getApplicableForUser');
		$this->import->expects($this->never())->method('importFromCsv');
		$this->import->expects($this->never())->method('approveImport');
		$this->escalation->expects($this->never())->method('rejectEscalatie');
		$this->gebruik->expects($this->never())->method('getDecisionAuditTrail');

		$responses = [
			'probe' => $this->controller->probe(),
			'importPreview' => $this->controller->importPreview(),
			'importApprove' => $this->controller->importApprove(importId: 'imp-1'),
			'escalateReject' => $this->controller->escalateReject(id: 'esc-1'),
			'auditTrail' => $this->controller->auditTrail(caseId: 'case-1'),
			'applicable' => $this->controller->applicable(caseId: 'case-1'),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame(
				Http::STATUS_FORBIDDEN,
				$response->getStatus(),
				$endpoint . ' must answer 403 for a caller without a session'
			);
			$this->assertSame(
				['message' => 'Not authenticated'],
				$response->getData(),
				$endpoint . ' must answer the controller-standard unauthenticated body'
			);
		}
	}//end testTheGuardedEndpointsRefuseAnAnonymousCallerWith403()

	/**
	 * probe() refuses a body without `decisionType` and `caseId` with a 400 and
	 * never asks the mandaat matrix — an authorization probe answered on empty
	 * input would be a verdict about no decision at all.
	 *
	 * @return void
	 */
	public function testProbeRejectsAnEmptyBodyWithoutConsultingTheMandateMatrix(): void {
		$this->authenticate();

		$this->check->expects($this->never())->method('isAuthorized');
		$this->settings->expects($this->never())->method('getObjectService');

		$response = $this->controller->probe();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'decisionType and caseId are required'],
			$response->getData()
		);
	}//end testProbeRejectsAnEmptyBodyWithoutConsultingTheMandateMatrix()

	/**
	 * importPreview() refuses an incomplete body with a 400 and imports
	 * nothing. The named fields are the published request contract — the
	 * message enumerates the ENGLISH keys, which is what a new caller must
	 * send.
	 *
	 * @return void
	 */
	public function testImportPreviewRejectsAnEmptyBodyAndImportsNothing(): void {
		$this->authenticate();
		$this->import->expects($this->never())->method('importFromCsv');

		$response = $this->controller->importPreview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'decisionNumber, decisionName and csv are required'],
			$response->getData()
		);
	}//end testImportPreviewRejectsAnEmptyBodyAndImportsNothing()

	/**
	 * escalateReject() refuses a rejection without a reason with a 400 and does
	 * not close the escalation. An escalation may not be rejected silently: the
	 * reason is the record the caseworker later has to justify.
	 *
	 * @return void
	 */
	public function testEscalateRejectRequiresAReasonAndDoesNotCloseTheEscalation(): void {
		$this->authenticate();
		$this->escalation->expects($this->never())->method('rejectEscalatie');

		$response = $this->controller->escalateReject(id: 'esc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'reason is required'], $response->getData());
	}//end testEscalateRejectRequiresAReasonAndDoesNotCloseTheEscalation()

	/**
	 * importApprove() promotes the concept besluit named in the route and
	 * answers 200 with the service record.
	 *
	 * @return void
	 */
	public function testImportApproveApprovesTheRoutedImport(): void {
		$this->authenticate();

		$approved = ['id' => 'imp-1', 'status' => 'vastgesteld', 'mandateCount' => 42];

		$this->import->expects($this->once())
			->method('approveImport')
			->with('imp-1')
			->willReturn($approved);

		$response = $this->controller->importApprove(importId: 'imp-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($approved, $response->getData());
	}//end testImportApproveApprovesTheRoutedImport()

	/**
	 * A refused import approval is a 400 carrying the service reason.
	 *
	 * @return void
	 */
	public function testImportApproveMapsAServiceFailureTo400(): void {
		$this->authenticate();

		$this->import->method('approveImport')
			->willThrowException(new RuntimeException('Besluit already approved'));

		$response = $this->controller->importApprove(importId: 'imp-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Besluit already approved'], $response->getData());
	}//end testImportApproveMapsAServiceFailureTo400()

	/**
	 * escalateApprove() approves as the SESSION user. The uid is what the
	 * service checks the mandate holder against, so a caller-supplied approver
	 * would be an escalation approved by somebody who never saw it.
	 *
	 * @return void
	 */
	public function testEscalateApproveApprovesAsTheSessionUser(): void {
		$this->authenticate();
		$this->withParams(['userId' => 'mallory', 'mandaathouderUserId' => 'mallory']);

		$approved = ['id' => 'esc-1', 'status' => 'approved', 'approvedBy' => 'alice'];

		$this->escalation->expects($this->once())
			->method('approveEscalatie')
			->with('esc-1', 'alice')
			->willReturn($approved);

		$response = $this->controller->escalateApprove(id: 'esc-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($approved, $response->getData());
	}//end testEscalateApproveApprovesAsTheSessionUser()

	/**
	 * escalateApprove() is the one endpoint on this controller with NO
	 * `ensureAuthenticated()` call: an anonymous caller reaches the service
	 * with an EMPTY user id, and the service — which re-checks that the caller
	 * is the resolved mandate holder — is what refuses.
	 *
	 * The empty uid is asserted, not the 403 alone: the design is only safe
	 * while `''` can never match a mandate holder, and this is the test that
	 * documents that load-bearing assumption.
	 *
	 * @return void
	 */
	public function testEscalateApproveHandsAnAnonymousCallerAnEmptyUidToTheServiceGuard(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->escalation->expects($this->once())
			->method('approveEscalatie')
			->with('esc-1', '')
			->willThrowException(new RuntimeException('Not the mandate holder'));

		$response = $this->controller->escalateApprove(id: 'esc-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not the mandate holder'], $response->getData());
	}//end testEscalateApproveHandsAnAnonymousCallerAnEmptyUidToTheServiceGuard()

	/**
	 * auditTrail() returns the decision audit trail for the ROUTED case, at
	 * 200, verbatim.
	 *
	 * @return void
	 */
	public function testAuditTrailReturnsTheTrailForTheRoutedCase(): void {
		$this->authenticate();

		$trail = [
			['decisionType' => 'wmo-toekenning', 'userId' => 'alice', 'outcome' => 'authorized'],
		];

		$this->gebruik->expects($this->once())
			->method('getDecisionAuditTrail')
			->with('case-1')
			->willReturn($trail);

		$response = $this->controller->auditTrail(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($trail, $response->getData());
	}//end testAuditTrailReturnsTheTrailForTheRoutedCase()

	/**
	 * applicable() filters the mandate list to the SESSION user and forwards
	 * the `caseType` / `decisionType` query filters in that order.
	 *
	 * Both filters are strings, so a transposition would type-check and quietly
	 * return the wrong mandates.
	 *
	 * @return void
	 */
	public function testApplicableFiltersToTheSessionUserWithTheQueryFilters(): void {
		$this->authenticate();
		$this->withParams(['caseType' => 'wmo', 'decisionType' => 'toekenning', 'userId' => 'mallory']);

		$rows = [['mandateId' => 'm-1', 'decisionType' => 'toekenning', 'ceiling' => 500000]];

		$this->check->expects($this->once())
			->method('getApplicableForUser')
			->with('alice', 'wmo', 'toekenning')
			->willReturn($rows);

		$response = $this->controller->applicable(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($rows, $response->getData());
	}//end testApplicableFiltersToTheSessionUserWithTheQueryFilters()

	/**
	 * applicable() fails SOFT: a service failure is logged and answered as an
	 * empty list at 200, not as a 400/500.
	 *
	 * This is deliberate — the endpoint only decides which actions the UI
	 * offers, and an empty list withholds actions rather than granting them.
	 * The warning must still be emitted, otherwise a broken mandate register
	 * looks exactly like a user with no mandates.
	 *
	 * @return void
	 */
	public function testApplicableAnswersAnEmptyListAndLogsWhenTheServiceFails(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->check->method('getApplicableForUser')
			->willThrowException(new RuntimeException('register not configured'));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'MandaatMatrixController.applicable failed',
				['caseId' => 'case-1', 'error' => 'register not configured']
			);

		$response = $this->controller->applicable(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}//end testApplicableAnswersAnEmptyListAndLogsWhenTheServiceFails()
}//end class
