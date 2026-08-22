<?php

/**
 * ZaakdossierDownloadController Wire-Contract Tests
 *
 * Contract coverage for the two single-document download routes (gate-25):
 * `GET /api/objects/{register}/{schema}/{objectId}/files/{fileId}/download`
 * and the ZGW DRC-compatible
 * `GET /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download`.
 * Both serve file BYTES out of a zaakdossier, and both are gated per-object on
 * `vertrouwelijkheidaanduiding` by `InformatieobjectReader::loadReadable()`.
 * These tests pin:
 *
 *  - 401 without a session on BOTH, with the reader asserted NEVER entered —
 *    the clearance gate must not even be consulted for an anonymous caller;
 *  - the clearance refusal PASSES THROUGH VERBATIM. `loadReadable()` returns
 *    either the document array or a JSONResponse denial, and the endpoints
 *    must return that denial object unchanged and NOT go on to read content.
 *    This is the whole confidentiality gate, and it is asserted with an
 *    identity check plus a `never()` on `contentFor()`;
 *  - the document is addressed by the URL's `{objectId}` / `{uuid}` segment;
 *  - a resolvable document whose bytes are missing is a 404, not an empty
 *    download a client would silently write to disk as a 0-byte file;
 *  - `downloadZgwDocumenten` honours HTTP Range: a full request is 200 with
 *    `Accept-Ranges: bytes`, and a satisfiable `Range` is 206 with a correct
 *    `Content-Range` and only the requested slice in the body.
 *
 * ⚠️ THE SUCCESSFUL `downloadFile` RESPONSE SHAPE IS NOT ASSERTED, DELIBERATELY.
 * `OCP\AppFramework\Http\DataDownloadResponse` cannot be constructed in this
 * unit-test environment: `DownloadResponse::__construct()` calls
 * `Symfony\Component\HttpFoundation\HeaderUtils`, and symfony/http-foundation
 * is not in dossiq's vendor tree (Nextcloud supplies it at runtime).
 * Constructing one raises an `Error`. The pre-existing
 * `ZaakdossierDownloadControllerGuardTest::testCallerWithCaseAccessStillGetsTheArchive`
 * is red on an untouched checkout for exactly this reason. So `downloadFile`
 * is covered on its refusal branches and on the reader delegation; its
 * `RangeStreamResponse` sibling — a dossiq-owned class with no Symfony
 * dependency — carries the successful-download assertions instead.
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

use OCA\Dossiq\Http\RangeStreamResponse;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\DossierZipExporter;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Controller\ZaakdossierDownloadController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for the ZaakdossierDownloadController file endpoints.
 *
 * @covers \OCA\Dossiq\Controller\ZaakdossierDownloadController
 *
 * @uses \OCA\Dossiq\Http\RangeStreamResponse
 */
class ZaakdossierDownloadControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The clearance-gated document reader.
	 *
	 * @var InformatieobjectReader|MockObject
	 */
	private InformatieobjectReader $reader;

	/**
	 * The ZIP exporter (used by the sibling downloadZip endpoint).
	 *
	 * @var DossierZipExporter|MockObject
	 */
	private DossierZipExporter $zipExporter;

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
	 * The per-case guard (used by the sibling downloadZip endpoint).
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The controller under test.
	 *
	 * @var ZaakdossierDownloadController
	 */
	private ZaakdossierDownloadController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->reader = $this->createMock(InformatieobjectReader::class);
		$this->zipExporter = $this->createMock(DossierZipExporter::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$this->controller = new ZaakdossierDownloadController(
			appName: 'dossiq',
			request: $this->request,
			reader: $this->reader,
			zipExporter: $this->zipExporter,
			userSession: $this->userSession,
			logger: $this->logger,
			caseAccessGuard: $this->caseAccessGuard,
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
	 * Neither download endpoint may reach the clearance reader without a
	 * session.
	 *
	 * @return void
	 */
	public function testBothDownloadEndpointsRefuseASessionLessCallerBeforeTheClearanceReader(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->reader->expects($this->never())->method('loadReadable');
		$this->reader->expects($this->never())->method('contentFor');

		$file = $this->controller->downloadFile(
			register: 'procest',
			schema: 'informatieobject',
			objectId: 'io-1',
			fileId: 42,
		);
		$zgw = $this->controller->downloadZgwDocumenten(uuid: 'io-1');

		$this->assertInstanceOf(JSONResponse::class, $file);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $file->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $file->getData());
		$this->assertInstanceOf(JSONResponse::class, $zgw);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $zgw->getStatus());
	}//end testBothDownloadEndpointsRefuseASessionLessCallerBeforeTheClearanceReader()

	/**
	 * downloadFile addresses the document by the URL's `{objectId}` segment,
	 * and a clearance denial from the reader is returned VERBATIM with no
	 * content read.
	 *
	 * @return void
	 */
	public function testDownloadFileReturnsTheClearanceDenialVerbatimAndReadsNoContent(): void {
		$this->signIn();

		$denial = new JSONResponse(
			['error' => 'Insufficient clearance for this document'],
			Http::STATUS_FORBIDDEN
		);

		$this->reader->expects($this->once())
			->method('loadReadable')
			->with($this->anything(), 'io-vertrouwelijk')
			->willReturn($denial);

		$this->reader->expects($this->never())->method('contentFor');

		$response = $this->controller->downloadFile(
			register: 'procest',
			schema: 'informatieobject',
			objectId: 'io-vertrouwelijk',
			fileId: 42,
		);

		$this->assertSame($denial, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testDownloadFileReturnsTheClearanceDenialVerbatimAndReadsNoContent()

	/**
	 * A cleared document whose bytes are missing is a 404, not a 0-byte
	 * download.
	 *
	 * @return void
	 */
	public function testDownloadFileReturns404WhenTheClearedDocumentHasNoBytes(): void {
		$this->signIn();

		$this->reader->method('loadReadable')
			->willReturn(['fileName' => 'besluit.pdf', 'format' => 'application/pdf']);

		$this->reader->expects($this->once())
			->method('contentFor')
			->with('io-1', 'besluit.pdf')
			->willReturn(null);

		$response = $this->controller->downloadFile(
			register: 'procest',
			schema: 'informatieobject',
			objectId: 'io-1',
			fileId: 42,
		);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'File not found'], $response->getData());
	}//end testDownloadFileReturns404WhenTheClearedDocumentHasNoBytes()

	/**
	 * The ZGW route applies the SAME clearance gate on the `{uuid}` segment
	 * and passes its denial through without reading content.
	 *
	 * @return void
	 */
	public function testDownloadZgwDocumentenAppliesTheSameClearanceGateOnTheUuid(): void {
		$this->signIn();

		$denial = new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);

		$this->reader->expects($this->once())
			->method('loadReadable')
			->with($this->anything(), 'eio-9')
			->willReturn($denial);

		$this->reader->expects($this->never())->method('contentFor');

		$response = $this->controller->downloadZgwDocumenten(uuid: 'eio-9');

		$this->assertSame($denial, $response);
	}//end testDownloadZgwDocumentenAppliesTheSameClearanceGateOnTheUuid()

	/**
	 * The ZGW route also answers 404 when the cleared document has no bytes.
	 *
	 * @return void
	 */
	public function testDownloadZgwDocumentenReturns404WhenTheDocumentHasNoBytes(): void {
		$this->signIn();

		$this->reader->method('loadReadable')->willReturn(['fileName' => 'rapport.pdf']);
		$this->reader->method('contentFor')->willReturn(null);

		$response = $this->controller->downloadZgwDocumenten(uuid: 'eio-9');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testDownloadZgwDocumentenReturns404WhenTheDocumentHasNoBytes()

	/**
	 * Without a Range header the ZGW download is a full 200 carrying the whole
	 * document, its declared MIME type, and `Accept-Ranges: bytes`.
	 *
	 * @return void
	 */
	public function testDownloadZgwDocumentenServesTheWholeDocumentWith200AndAcceptRanges(): void {
		$this->signIn();
		$this->request->method('getHeader')->willReturn('');

		$this->reader->method('loadReadable')
			->willReturn(['fileName' => 'rapport.pdf', 'format' => 'application/pdf']);
		$this->reader->method('contentFor')->willReturn('0123456789');

		$response = $this->controller->downloadZgwDocumenten(uuid: 'eio-9');

		$this->assertInstanceOf(RangeStreamResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$headers = $response->getHeaders();
		$this->assertSame('bytes', $headers['Accept-Ranges']);
		$this->assertSame('application/pdf', $headers['Content-Type']);
		$this->assertSame('10', $headers['Content-Length']);
		$this->assertArrayNotHasKey('Content-Range', $headers);
	}//end testDownloadZgwDocumentenServesTheWholeDocumentWith200AndAcceptRanges()

	/**
	 * A satisfiable Range header yields 206 with only the requested slice and
	 * a matching Content-Range — the resumable-transfer contract ZGW DRC
	 * clients rely on.
	 *
	 * @return void
	 */
	public function testDownloadZgwDocumentenServesASatisfiableRangeAs206PartialContent(): void {
		$this->signIn();

		$this->request->expects($this->once())
			->method('getHeader')
			->with('Range')
			->willReturn('bytes=2-5');

		$this->reader->method('loadReadable')
			->willReturn(['fileName' => 'rapport.pdf', 'format' => 'application/pdf']);
		$this->reader->method('contentFor')->willReturn('0123456789');

		$response = $this->controller->downloadZgwDocumenten(uuid: 'eio-9');
		$headers = $response->getHeaders();

		$this->assertInstanceOf(RangeStreamResponse::class, $response);
		$this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		$this->assertSame('bytes 2-5/10', $headers['Content-Range']);
		$this->assertSame('4', $headers['Content-Length']);
		$this->assertSame('2345', $response->render());
	}//end testDownloadZgwDocumentenServesASatisfiableRangeAs206PartialContent()
}//end class
