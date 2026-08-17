<?php

/**
 * ZaakdossierController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the three ZaakdossierController endpoints
 * that had no automated proof of their wire behaviour.
 *
 * Every informatieobject in the dossier carries a `vertrouwelijkheidaanduiding`
 * and the clearance gate for it is `InformatieobjectReader::guardReadable()`,
 * which answers a ready-made refusal Response or null. Three properties of the
 * way these endpoints use it are pinned, and each one fails silently:
 *
 *  - `linkExisting()` / `unlinkDocument()` return the reader's OWN response
 *    untouched — the controller must not downgrade a 403 into its own generic
 *    error, and must not run the mutation afterwards;
 *  - `bulkUpdateMetadata()` gates PER ID inside the loop. A document the caller
 *    may not read is reported as a per-id failure and is NOT written, while the
 *    rest of the batch still proceeds — a bulk route that gated only the first
 *    id, or aborted the whole batch, would both look correct from the outside;
 *  - a dossier backend that is not wired up answers 503, distinct from the 403
 *    above, so a client can tell "not allowed" from "store unavailable".
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

use OCA\Procest\Controller\ZaakdossierController;
use OCA\Procest\Service\Zaakdossier\DossierUploadHandler;
use OCA\Procest\Service\Zaakdossier\InformatieobjectReader;
use OCA\Procest\Service\ZaakdossierService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for ZaakdossierController.
 *
 * @covers \OCA\Procest\Controller\ZaakdossierController
 */
class ZaakdossierControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The dossier orchestrator.
	 *
	 * @var ZaakdossierService|MockObject
	 */
	private ZaakdossierService $fileService;

	/**
	 * The clearance-gated document reader.
	 *
	 * @var InformatieobjectReader|MockObject
	 */
	private InformatieobjectReader $reader;

	/**
	 * The upload decoding/screening collaborator (untouched by these routes).
	 *
	 * @var DossierUploadHandler|MockObject
	 */
	private DossierUploadHandler $uploadHandler;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var ZaakdossierController
	 */
	private ZaakdossierController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->fileService = $this->createMock(ZaakdossierService::class);
		$this->reader = $this->createMock(InformatieobjectReader::class);
		$this->uploadHandler = $this->createMock(DossierUploadHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new ZaakdossierController(
			appName: 'procest',
			request: $this->request,
			fileService: $this->fileService,
			reader: $this->reader,
			uploadHandler: $this->uploadHandler,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Put a user in the session.
	 *
	 * @param string $uid The uid the session user reports.
	 *
	 * @return IUser|MockObject The session user.
	 */
	private function signIn(string $uid = 'handler'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * Make `getParam()` behave like the real request.
	 *
	 * @param array<string, mixed> $overrides Parameter values to serve.
	 *
	 * @return void
	 */
	private function withRequestParams(array $overrides): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($overrides): mixed {
				return ($overrides[$key] ?? $default);
			}
		);
	}//end withRequestParams()

	/**
	 * All three endpoints refuse an anonymous caller with 401 and neither read
	 * nor write a single informatieobject.
	 *
	 * @return void
	 */
	public function testAllThreeEndpointsRefuseAnAnonymousCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->reader->expects($this->never())->method('guardReadable');
		$this->fileService->expects($this->never())->method('linkExistingInformatieobject');
		$this->fileService->expects($this->never())->method('unlinkInformatieobject');
		$this->fileService->expects($this->never())->method('updateMetadata');

		$responses = [
			'linkExisting' => $this->controller->linkExisting(caseId: 'case-1', infoObjectId: 'io-1'),
			'unlinkDocument' => $this->controller->unlinkDocument(caseId: 'case-1', infoObjectId: 'io-1'),
			'bulkUpdateMetadata' => $this->controller->bulkUpdateMetadata(),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$endpoint . ' must refuse an anonymous caller'
			);
			$this->assertSame(['error' => 'Not authenticated'], $response->getData());
		}
	}//end testAllThreeEndpointsRefuseAnAnonymousCallerWith401()

	/**
	 * linkExisting hands the clearance gate the document the route names, and
	 * returns the gate's OWN refusal untouched without linking anything.
	 *
	 * @return void
	 */
	public function testLinkExistingReturnsTheClearanceRefusalAndLinksNothing(): void {
		$user = $this->signIn();
		$refusal = new JSONResponse(data: ['error' => 'Insufficient clearance'], statusCode: Http::STATUS_FORBIDDEN);

		$this->reader->expects($this->once())
			->method('guardReadable')
			->with($user, 'io-confidential')
			->willReturn($refusal);
		$this->fileService->expects($this->never())->method('linkExistingInformatieobject');

		$response = $this->controller->linkExisting(caseId: 'case-1', infoObjectId: 'io-confidential');

		$this->assertSame($refusal, $response, 'the reader\'s own refusal must be returned verbatim');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testLinkExistingReturnsTheClearanceRefusalAndLinksNothing()

	/**
	 * A cleared link answers 201 Created — a join is created, so 200 would
	 * misreport it — and carries the join result.
	 *
	 * @return void
	 */
	public function testLinkExistingAnswers201WithTheJoinForAClearedCaller(): void {
		$this->signIn();
		$this->reader->method('guardReadable')->willReturn(null);

		$this->fileService->expects($this->once())
			->method('linkExistingInformatieobject')
			->with('case-1', 'io-1')
			->willReturn(['id' => 'zio-3', 'zaak' => 'case-1']);

		$response = $this->controller->linkExisting(caseId: 'case-1', infoObjectId: 'io-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'zio-3', 'zaak' => 'case-1'], $response->getData());
	}//end testLinkExistingAnswers201WithTheJoinForAClearedCaller()

	/**
	 * An unwired dossier store answers 503 — distinct from the 403 above, so a
	 * client can tell a permission problem from an outage.
	 *
	 * @return void
	 */
	public function testLinkExistingAnswers503WhenTheDossierStoreIsUnavailable(): void {
		$this->signIn();
		$this->reader->method('guardReadable')->willReturn(null);
		$this->fileService->method('linkExistingInformatieobject')
			->willThrowException(new \RuntimeException('OpenRegister is not available'));

		$response = $this->controller->linkExisting(caseId: 'case-1', infoObjectId: 'io-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'OpenRegister is not available'], $response->getData());
	}//end testLinkExistingAnswers503WhenTheDossierStoreIsUnavailable()

	/**
	 * unlinkDocument takes the same clearance gate before mutating, and returns
	 * the gate's refusal without unlinking.
	 *
	 * @return void
	 */
	public function testUnlinkDocumentReturnsTheClearanceRefusalAndUnlinksNothing(): void {
		$user = $this->signIn();
		$refusal = new JSONResponse(data: ['error' => 'Insufficient clearance'], statusCode: Http::STATUS_FORBIDDEN);

		$this->reader->expects($this->once())
			->method('guardReadable')
			->with($user, 'io-confidential')
			->willReturn($refusal);
		$this->fileService->expects($this->never())->method('unlinkInformatieobject');

		$response = $this->controller->unlinkDocument(caseId: 'case-1', infoObjectId: 'io-confidential');

		$this->assertSame($refusal, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testUnlinkDocumentReturnsTheClearanceRefusalAndUnlinksNothing()

	/**
	 * A cleared unlink reports whether a join was actually removed under
	 * `unlinked` — the endpoint detaches the document from the case, it does
	 * not delete it, so the boolean is the whole result.
	 *
	 * @return void
	 */
	public function testUnlinkDocumentReportsWhetherAJoinWasRemoved(): void {
		$this->signIn();
		$this->reader->method('guardReadable')->willReturn(null);

		$this->fileService->expects($this->once())
			->method('unlinkInformatieobject')
			->with('case-1', 'io-1')
			->willReturn(true);

		$response = $this->controller->unlinkDocument(caseId: 'case-1', infoObjectId: 'io-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['unlinked' => true], $response->getData());
	}//end testUnlinkDocumentReportsWhetherAJoinWasRemoved()

	/**
	 * The bulk route gates EVERY id, not just the first: the document the
	 * caller may not read is reported as a per-id failure and never written,
	 * while the readable ones are still updated with the posted metadata.
	 *
	 * @return void
	 */
	public function testBulkUpdateMetadataGatesEveryIdAndStillProcessesTheRest(): void {
		$this->signIn();
		$this->withRequestParams(
			[
				'ids' => ['io-secret', 'io-ok'],
				'metadata' => ['vertrouwelijkheidaanduiding' => 'openbaar'],
			]
		);

		$this->reader->method('guardReadable')->willReturnCallback(
			static function (IUser $user, string $infoObjectId): ?JSONResponse {
				if ($infoObjectId === 'io-secret') {
					return new JSONResponse(data: ['error' => 'denied'], statusCode: Http::STATUS_FORBIDDEN);
				}

				return null;
			}
		);

		$this->fileService->expects($this->once())
			->method('updateMetadata')
			->with('io-ok', ['vertrouwelijkheidaanduiding' => 'openbaar'])
			->willReturn(['id' => 'io-ok']);

		$response = $this->controller->bulkUpdateMetadata();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'results' => [
					['id' => 'io-secret', 'success' => false, 'error' => 'Insufficient clearance'],
					['id' => 'io-ok', 'success' => true],
				],
			],
			$response->getData()
		);
	}//end testBulkUpdateMetadataGatesEveryIdAndStillProcessesTheRest()

	/**
	 * A backend failure on one id is reported against THAT id and does not
	 * abort the batch — the remaining ids are still attempted.
	 *
	 * @return void
	 */
	public function testBulkUpdateMetadataReportsAPerIdFailureWithoutAbortingTheBatch(): void {
		$this->signIn();
		$this->withRequestParams(['ids' => ['io-1', 'io-2'], 'metadata' => ['title' => 'Nieuw']]);
		$this->reader->method('guardReadable')->willReturn(null);

		$this->fileService->method('updateMetadata')->willReturnCallback(
			static function (string $infoObjectId, array $metadata): array {
				if ($infoObjectId === 'io-1') {
					throw new \RuntimeException('Store unavailable');
				}

				return ['id' => $infoObjectId];
			}
		);

		$response = $this->controller->bulkUpdateMetadata();

		$this->assertSame(
			[
				'results' => [
					['id' => 'io-1', 'success' => false, 'error' => 'Store unavailable'],
					['id' => 'io-2', 'success' => true],
				],
			],
			$response->getData()
		);
	}//end testBulkUpdateMetadataReportsAPerIdFailureWithoutAbortingTheBatch()
}//end class
