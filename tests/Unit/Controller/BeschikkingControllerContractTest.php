<?php

/**
 * BeschikkingController Wire-Contract Tests
 *
 * Contract coverage for `GET /api/beschikkingen/{id}/audit-pakket` (gate-25).
 * The endpoint hands back the verifiable audit packet of a formal decision — a
 * ZIP of the decision, its mandaat trail and its signatures — so its refusal
 * branches matter more than its happy path. These tests pin:
 *
 *  - an unauthenticated caller is refused 401 BEFORE the packet is built, so
 *    the export is never performed for a session-less request;
 *  - an authenticated call reaches the exporter with the id from the URL;
 *  - the domain error codes map to DISTINCT statuses — `not_found` is 404 and
 *    `mandaat_insufficient` is 403. Collapsing those two into one status is
 *    the realistic defect in a `match` of eleven arms, and the 403 arm is the
 *    one that keeps an under-mandated official out of the audit trail.
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

use OCA\Dossiq\Controller\BeschikkingController;
use OCA\Dossiq\Service\BeschikkingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for BeschikkingController::auditPakket().
 *
 * @covers \OCA\Dossiq\Controller\BeschikkingController
 */
class BeschikkingControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The beschikking domain service.
	 *
	 * @var BeschikkingService|MockObject
	 */
	private BeschikkingService $decisionService;

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
	 * The controller under test.
	 *
	 * @var BeschikkingController
	 */
	private BeschikkingController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->decisionService = $this->createMock(BeschikkingService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new BeschikkingController(
			appName: 'dossiq',
			request: $this->request,
			decisionService: $this->decisionService,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * An unauthenticated caller is refused before the packet is assembled.
	 *
	 * @return void
	 */
	public function testAuditPakketRefusesAnUnauthenticatedCallerBeforeBuildingThePacket(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->decisionService->expects($this->never())->method('exportAuditPacket');

		$response = $this->controller->auditPakket(id: 'besluit-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testAuditPakketRefusesAnUnauthenticatedCallerBeforeBuildingThePacket()

	/**
	 * An authenticated caller reaches the exporter, and the id exported is the
	 * one from the URL — not a session-derived or default id.
	 *
	 * ⚠️ THE RESPONSE SHAPE IS NOT ASSERTED HERE, DELIBERATELY.
	 * `DataDownloadResponse` cannot be constructed in this unit-test
	 * environment: `OCP\AppFramework\Http\DownloadResponse::__construct()`
	 * calls `Symfony\Component\HttpFoundation\HeaderUtils`, and
	 * `symfony/http-foundation` is not in dossiq's vendor tree (it is supplied
	 * by the Nextcloud server at runtime). Constructing one raises an `Error`
	 * that this controller's `catch (\Throwable)` converts into a 500, so any
	 * assertion on the download headers here would be asserting the
	 * environment, not the contract. The same limitation already makes
	 * `ZaakdossierDownloadControllerGuardTest::testCallerWithCaseAccessStillGetsTheArchive`
	 * red on an untouched checkout.
	 *
	 * What IS asserted — that an authenticated call reaches `exportAuditPacket`
	 * with the path id — is the discriminating half: paired with the `never()`
	 * on the unauthenticated arm above, a controller that refused everything
	 * and a controller that refused nothing both fail.
	 *
	 * @return void
	 */
	public function testAuditPakketExportsThePacketForTheIdInThePath(): void {
		$this->signIn();

		$this->decisionService->expects($this->once())
			->method('exportAuditPacket')
			->with('besluit-42')
			->willReturn('PK-zip-bytes');

		$this->controller->auditPakket(id: 'besluit-42');
	}//end testAuditPakketExportsThePacketForTheIdInThePath()

	/**
	 * The `not_found` domain code is a 404, not a generic 500.
	 *
	 * @return void
	 */
	public function testAuditPakketMapsTheNotFoundDomainCodeToA404(): void {
		$this->signIn();

		$this->decisionService->method('exportAuditPacket')
			->willThrowException(new \RuntimeException('not_found'));

		$response = $this->controller->auditPakket(id: 'missing');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Beschikking not found'], $response->getData());
	}//end testAuditPakketMapsTheNotFoundDomainCodeToA404()

	/**
	 * An insufficient mandaat is a 403, a DIFFERENT status from `not_found`.
	 *
	 * @return void
	 */
	public function testAuditPakketMapsAnInsufficientMandaatToA403DistinctFromNotFound(): void {
		$this->signIn();

		$this->decisionService->method('exportAuditPacket')
			->willThrowException(new \RuntimeException('mandaat_insufficient'));

		$response = $this->controller->auditPakket(id: 'besluit-42');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Insufficient mandaat for this decision'], $response->getData());
	}//end testAuditPakketMapsAnInsufficientMandaatToA403DistinctFromNotFound()

	/**
	 * An unexpected failure is a generic 500 that does not leak the internal
	 * exception message to the caller.
	 *
	 * @return void
	 */
	public function testAuditPakketReturnsAGeneric500WithoutLeakingTheInternalMessage(): void {
		$this->signIn();

		$this->decisionService->method('exportAuditPacket')
			->willThrowException(new \LogicException('ZipArchive::open(/srv/secret/path) failed'));

		$response = $this->controller->auditPakket(id: 'besluit-42');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('/srv/secret/path', json_encode($response->getData()));
	}//end testAuditPakketReturnsAGeneric500WithoutLeakingTheInternalMessage()
}//end class
