<?php

/**
 * ZaakdossierController Branch Coverage Tests
 *
 * Pins the branches of ZaakdossierController the wire-contract suites leave
 * open: unauthenticated callers on upload / write / bulk routes, the case
 * upload-access refusal, an empty upload, the metadata update path (request
 * field mapping, legacy `beschrijving` spelling, 409 on a conflict, 503 on an
 * unavailable store), the single status transition's 400 / 503 answers and the
 * bulk transition happy path.
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

use OCA\Dossiq\Controller\ZaakdossierController;
use OCA\Dossiq\Kernel\Contracts\DocumentApprovalClearanceInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentWriteGateInterface;
use OCA\Dossiq\Kernel\Contracts\DossierUploadHandlerInterface;
use OCA\Dossiq\Kernel\Contracts\InformatieobjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\ZaakdossierServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Branch coverage tests for ZaakdossierController.
 *
 * @covers \OCA\Dossiq\Controller\ZaakdossierController
 */
class ZaakdossierControllerCoverageTest extends TestCase {

	/**
	 * The request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The dossier orchestrator.
	 *
	 * @var ZaakdossierServiceInterface&MockObject
	 */
	private ZaakdossierServiceInterface $fileService;

	/**
	 * The clearance-gated reader.
	 *
	 * @var InformatieobjectReaderInterface&MockObject
	 */
	private InformatieobjectReaderInterface $reader;

	/**
	 * The upload handler.
	 *
	 * @var DossierUploadHandlerInterface&MockObject
	 */
	private DossierUploadHandlerInterface $uploadHandler;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The approval clearance reader.
	 *
	 * @var DocumentApprovalClearanceInterface&MockObject
	 */
	private DocumentApprovalClearanceInterface $approvals;

	/**
	 * The write gate.
	 *
	 * @var DocumentWriteGateInterface&MockObject
	 */
	private DocumentWriteGateInterface $writes;

	/**
	 * The signed-in user.
	 *
	 * @var IUser&MockObject
	 */
	private IUser $user;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->fileService = $this->createMock(ZaakdossierServiceInterface::class);
		$this->reader = $this->createMock(InformatieobjectReaderInterface::class);
		$this->uploadHandler = $this->createMock(DossierUploadHandlerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->approvals = $this->createMock(DocumentApprovalClearanceInterface::class);
		$this->writes = $this->createMock(DocumentWriteGateInterface::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
		$this->user->method('getDisplayName')->willReturn('Alice A');
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return ZaakdossierController
	 */
	private function controller(): ZaakdossierController {
		return new ZaakdossierController(
			'dossiq',
			$this->request,
			$this->fileService,
			$this->reader,
			$this->uploadHandler,
			$this->userSession,
			$this->approvals,
			$this->writes,
		);
	}//end controller()

	/**
	 * Sign in the test user.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
	}//end signIn()

	/**
	 * Sign nobody in.
	 *
	 * @return void
	 */
	private function signOut(): void {
		$this->userSession->method('getUser')->willReturn(null);
	}//end signOut()

	/**
	 * Make the write gate and reader let a document write through.
	 *
	 * @param string $id The document id.
	 *
	 * @return void
	 */
	private function allowWrite(string $id): void {
		$this->writes->expects($this->once())->method('refusedWrite')
			->with($this->user, $id)->willReturn(null);
		$this->reader->expects($this->once())->method('guardReadable')
			->with($this->user, $id)->willReturn(null);
	}//end allowWrite()

	/**
	 * Upload without a session answers 401 and touches nothing.
	 *
	 * @return void
	 */
	public function testUploadUnauthenticatedIs401(): void {
		$this->signOut();
		$this->uploadHandler->expects($this->never())->method('hasCaseUploadAccess');

		$response = $this->controller()->uploadDocument('case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testUploadUnauthenticatedIs401()

	/**
	 * Upload into a case the caller may not change answers 403 and uploads nothing.
	 *
	 * @return void
	 */
	public function testUploadWithoutCaseAccessIs403(): void {
		$this->signIn();
		$this->uploadHandler->expects($this->once())->method('hasCaseUploadAccess')
			->with($this->user, 'case-1')->willReturn(false);
		$this->uploadHandler->expects($this->never())->method('uploadOne');

		$response = $this->controller()->uploadDocument('case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $response->getData());
	}//end testUploadWithoutCaseAccessIs403()

	/**
	 * An upload with no files answers 400, after filling in the user as auteur.
	 *
	 * @return void
	 */
	public function testUploadWithNoFilesIs400(): void {
		$this->signIn();
		$this->uploadHandler->method('hasCaseUploadAccess')->willReturn(true);
		$this->request->method('getParam')->with('metadata', '{}')->willReturn('{}');
		$this->request->expects($this->once())->method('getUploadedFile')->with('files')->willReturn(null);
		$this->uploadHandler->expects($this->once())->method('decodeMetadata')->with('{}')->willReturn([]);
		$this->uploadHandler->expects($this->once())->method('normaliseUploadedFiles')->with(null)->willReturn([]);
		$this->uploadHandler->expects($this->never())->method('uploadOne');

		$response = $this->controller()->uploadDocument('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'No files uploaded'], $response->getData());
	}//end testUploadWithNoFilesIs400()

	/**
	 * Metadata update without a session answers 401 and writes nothing.
	 *
	 * @return void
	 */
	public function testUpdateMetadataUnauthenticatedIs401(): void {
		$this->signOut();
		$this->writes->expects($this->never())->method('refusedWrite');
		$this->fileService->expects($this->never())->method('updateMetadata');

		$response = $this->controller()->updateMetadata('doc-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testUpdateMetadataUnauthenticatedIs401()

	/**
	 * The write gate's own refusal is returned untouched.
	 *
	 * @return void
	 */
	public function testUpdateMetadataReturnsWriteGateRefusal(): void {
		$this->signIn();
		$refusal = new JSONResponse(['error' => 'case locked'], Http::STATUS_FORBIDDEN);
		$this->writes->expects($this->once())->method('refusedWrite')->with($this->user, 'doc-1')->willReturn($refusal);
		$this->reader->expects($this->never())->method('guardReadable');
		$this->fileService->expects($this->never())->method('updateMetadata');

		$this->assertSame($refusal, $this->controller()->updateMetadata('doc-1'));
	}//end testUpdateMetadataReturnsWriteGateRefusal()

	/**
	 * Only the fields the request sends are written, and `beschrijving` maps onto description.
	 *
	 * @return void
	 */
	public function testUpdateMetadataMapsRequestFields(): void {
		$this->signIn();
		$this->allowWrite('doc-1');
		$params = [
			'title' => 'New title',
			'beschrijving' => 'Legacy description',
			'vertrouwelijkheidaanduiding' => 'intern',
			'keywords' => ['a', 'b'],
			'recipients' => ['party-2'],
		];
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$this->fileService->expects($this->once())->method('updateMetadata')
			->with('doc-1', [
				'title' => 'New title',
				'description' => 'Legacy description',
				'vertrouwelijkheidaanduiding' => 'intern',
				'keywords' => ['a', 'b'],
				'recipients' => ['party-2'],
			])
			->willReturn(['id' => 'doc-1', 'title' => 'New title']);

		$response = $this->controller()->updateMetadata('doc-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'doc-1', 'title' => 'New title'], $response->getData());
	}//end testUpdateMetadataMapsRequestFields()

	/**
	 * `description` wins over the legacy `beschrijving` spelling.
	 *
	 * @return void
	 */
	public function testUpdateMetadataPrefersDescriptionOverLegacySpelling(): void {
		$this->signIn();
		$this->allowWrite('doc-1');
		$params = [
			'description' => 'Modern',
			'beschrijving' => 'Legacy',
			'informatieobjecttype' => 'type-1',
			'direction' => 'incoming',
			'sender' => 'party-1',
		];
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$this->fileService->expects($this->once())->method('updateMetadata')
			->with('doc-1', [
				'description' => 'Modern',
				'informatieobjecttype' => 'type-1',
				'direction' => 'incoming',
				'sender' => 'party-1',
			])
			->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateMetadata('doc-1')->getStatus());
	}//end testUpdateMetadataPrefersDescriptionOverLegacySpelling()

	/**
	 * A conflict from the store answers 409 with its message.
	 *
	 * @return void
	 */
	public function testUpdateMetadataConflictIs409(): void {
		$this->signIn();
		$this->allowWrite('doc-1');
		$this->request->method('getParam')->willReturn(null);
		$this->fileService->expects($this->once())->method('updateMetadata')
			->with('doc-1', [])->willThrowException(new \DomainException('Document is final'));

		$response = $this->controller()->updateMetadata('doc-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'Document is final'], $response->getData());
	}//end testUpdateMetadataConflictIs409()

	/**
	 * An unavailable store answers 503 with its message.
	 *
	 * @return void
	 */
	public function testUpdateMetadataUnavailableIs503(): void {
		$this->signIn();
		$this->allowWrite('doc-1');
		$this->request->method('getParam')->willReturn(null);
		$this->fileService->method('updateMetadata')->willThrowException(new \RuntimeException('DRC down'));

		$response = $this->controller()->updateMetadata('doc-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'DRC down'], $response->getData());
	}//end testUpdateMetadataUnavailableIs503()

	/**
	 * An invalid status transition answers 400.
	 *
	 * @return void
	 */
	public function testTransitionInvalidIs400(): void {
		$this->signIn();
		$this->allowWrite('doc-1');
		$this->request->method('getParam')->with('status', '')->willReturn('bogus');
		$this->fileService->expects($this->once())->method('transitionStatus')
			->with('doc-1', 'bogus')->willThrowException(new \InvalidArgumentException('Invalid transition'));

		$response = $this->controller()->transitionStatus('doc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Invalid transition'], $response->getData());
	}//end testTransitionInvalidIs400()

	/**
	 * An unavailable store on transition answers 503.
	 *
	 * @return void
	 */
	public function testTransitionUnavailableIs503(): void {
		$this->signIn();
		$this->allowWrite('doc-1');
		$this->request->method('getParam')->willReturn('definitief');
		$this->fileService->method('transitionStatus')->willThrowException(new \RuntimeException('DRC down'));

		$response = $this->controller()->transitionStatus('doc-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'DRC down'], $response->getData());
	}//end testTransitionUnavailableIs503()

	/**
	 * Bulk transition without a session answers 401 and transitions nothing.
	 *
	 * @return void
	 */
	public function testBulkTransitionUnauthenticatedIs401(): void {
		$this->signOut();
		$this->fileService->expects($this->never())->method('bulkTransitionStatus');

		$response = $this->controller()->bulkTransitionStatus();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testBulkTransitionUnauthenticatedIs401()

	/**
	 * Bulk transition past the gate answers the service's per-id results.
	 *
	 * @return void
	 */
	public function testBulkTransitionReturnsResults(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturnMap([
			['ids', [], ['d1', 'd2']],
			['status', '', 'definitief'],
		]);
		$this->writes->expects($this->once())->method('refusedBulkTransition')
			->with($this->user, ['d1', 'd2'])->willReturn(null);
		$results = [['id' => 'd1', 'success' => true], ['id' => 'd2', 'success' => false]];
		$this->fileService->expects($this->once())->method('bulkTransitionStatus')
			->with(['d1', 'd2'], 'definitief')->willReturn($results);

		$response = $this->controller()->bulkTransitionStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $results], $response->getData());
	}//end testBulkTransitionReturnsResults()

}//end class
