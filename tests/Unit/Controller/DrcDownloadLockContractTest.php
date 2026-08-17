<?php

/**
 * DrcController download/lock Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the two remaining DRC endpoints with no
 * executed proof of their wire behaviour: `download()` — which streams the
 * BYTES of an enkelvoudiginformatieobject — and `lock()` — which MUTATES one by
 * taking a ZGW lock on it. Both are `@PublicPage`, i.e. reachable without a
 * Nextcloud session, so the ZGW JWT is the whole gate.
 *
 * Scope of these tests, deliberately: the REFUSAL and ERROR branches, which are
 * the security-relevant half of the contract and are all JSONResponse-returning.
 * `download()`'s success path constructs a `DataDownloadResponse`, whose
 * constructor reaches `Symfony\Component\HttpFoundation\HeaderUtils` — a class
 * that is NOT installed in the unit-test container. An assertion on that branch
 * would fail locally for a reason that has nothing to do with the controller and
 * could not be verified here, so it is not written. What IS pinned:
 *
 *  - neither endpoint runs a line of its body before `validateJwtAuth()` has
 *    answered, and the refusal is returned verbatim;
 *  - with OpenRegister unwired both answer 503 rather than throwing;
 *  - `download()` resolves the `enkelvoudiginformatieobject` mapping — the
 *    singular MAPPING KEY, which is a different string from the plural resource
 *    name `lock()` uses — and answers 404 when it is absent;
 *  - `download()` answers 404 and reads NO content when the stored file is
 *    missing, so a dangling row can never stream another document's bytes;
 *  - `lock()` refuses to re-lock an already-locked document (400) without
 *    touching the store's lock, and on success stores the generated lockId in
 *    the data blob — a lock handed to a client but never persisted would be
 *    unverifiable at unlock time and the document permanently stuck;
 *  - and one TRIPWIRE (see its docblock) on the absent scope check.
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

use OCA\Procest\Controller\DrcController;
use OCA\Procest\Service\ZgwDocumentService;
use OCA\Procest\Service\ZgwMappingService;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal OpenRegister ObjectService stub for the download/lock contract tests.
 *
 * The controller calls the store with NAMED arguments, so the parameter names
 * must match the real service's exactly. `getLockInfo()` is deliberately NOT
 * declared: the controller probes for it with `method_exists()` and falls back
 * to the data blob when it is absent, which is the path an OpenRegister without
 * the dedicated lock table takes.
 */
interface DrcDownloadLockObjectServiceStub {
	/**
	 * Find a single object by identifier (real ObjectService::find()).
	 *
	 * @param int|string $id The object UUID.
	 * @param string|null $register The register slug.
	 * @param string|null $schema The schema slug.
	 *
	 * @return mixed The stored object row.
	 */
	public function find(int|string $id = '', ?string $register = null, ?string $schema = null): mixed;

	/**
	 * Save or update an object.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $object The object data.
	 * @param string|null $uuid The object UUID to overwrite.
	 *
	 * @return mixed The saved object row.
	 */
	public function saveObject(string $register, string $schema, array $object, ?string $uuid = null): mixed;

	/**
	 * Take OpenRegister's own lock on an object.
	 *
	 * @param string $identifier The object UUID.
	 *
	 * @return mixed The locked object row.
	 */
	public function lockObject(string $identifier): mixed;
}//end interface

/**
 * Wire-contract tests for DrcController::download() and ::lock().
 *
 * @covers \OCA\Procest\Controller\DrcController
 */
class DrcDownloadLockContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The ZgwService mock — JWT gate, store accessor and mapping oracle.
	 *
	 * @var ZgwService|MockObject
	 */
	private ZgwService $zgwService;

	/**
	 * The IL10N mock; `t()` echoes its message so the branch is identifiable.
	 *
	 * @var IL10N|MockObject
	 */
	private IL10N $l10n;

	/**
	 * The controller under test.
	 *
	 * @var DrcController
	 */
	private DrcController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->zgwService = $this->createMock(ZgwService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->zgwService->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));

		$this->controller = new DrcController(
			appName: 'procest',
			request: $this->request,
			zgwService: $this->zgwService,
			l10n: $this->l10n,
		);
	}//end setUp()

	/**
	 * Configure the JWT gate to REFUSE.
	 *
	 * @return JSONResponse The refusal the gate answers with.
	 */
	private function refuseJwt(): JSONResponse {
		$refusal = new JSONResponse(
			data: ['code' => 'not_authenticated'],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
		$this->zgwService->method('validateJwtAuth')->willReturn($refusal);

		return $refusal;
	}//end refuseJwt()

	/**
	 * Configure the JWT gate to ACCEPT.
	 *
	 * @return void
	 */
	private function acceptJwt(): void {
		$this->zgwService->method('validateJwtAuth')->willReturn(null);
	}//end acceptJwt()

	/**
	 * Stub the 503 response the controller returns when the store is unwired.
	 *
	 * @return void
	 */
	private function withUnavailableResponse(): void {
		$this->zgwService->method('unavailableResponse')->willReturn(
			new JSONResponse(
				data: ['detail' => 'OpenRegister is not available'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			)
		);
	}//end withUnavailableResponse()

	/**
	 * Both endpoints consult the JWT gate first and return its refusal verbatim
	 * — neither reaches the store, and `lock()` takes no lock.
	 *
	 * @return void
	 */
	public function testDownloadAndLockRefuseAnUnauthenticatedCallerBeforeTouchingTheStore(): void {
		$refusal = $this->refuseJwt();
		$this->zgwService->expects($this->never())->method('getObjectService');
		$this->zgwService->expects($this->never())->method('getZgwMappingService');
		$this->zgwService->expects($this->never())->method('getDocumentService');

		$download = $this->controller->download(uuid: 'doc-1');
		$lock = $this->controller->lock(uuid: 'doc-1');

		$this->assertSame($refusal, $download, 'download must return the JWT refusal verbatim');
		$this->assertSame($refusal, $lock, 'lock must return the JWT refusal verbatim');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $download->getStatus());
	}//end testDownloadAndLockRefuseAnUnauthenticatedCallerBeforeTouchingTheStore()

	/**
	 * With OpenRegister unwired both endpoints answer 503 rather than throwing
	 * — a 500 stack trace on a `@PublicPage` route is itself a disclosure.
	 *
	 * @return void
	 */
	public function testDownloadAndLockAnswer503WhenTheObjectStoreIsUnavailable(): void {
		$this->acceptJwt();
		$this->withUnavailableResponse();
		$this->zgwService->method('getObjectService')->willReturn(null);

		$download = $this->controller->download(uuid: 'doc-1');
		$lock = $this->controller->lock(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $download->getStatus());
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $lock->getStatus());
		$this->assertSame(['detail' => 'OpenRegister is not available'], $download->getData());
	}//end testDownloadAndLockAnswer503WhenTheObjectStoreIsUnavailable()

	/**
	 * `download()` resolves the SINGULAR `enkelvoudiginformatieobject` mapping
	 * key and answers 404 when it is not configured. The key matters: the
	 * neighbouring endpoints in this controller address the PLURAL resource
	 * `enkelvoudiginformatieobjecten`, and a mapping lookup under the wrong
	 * string returns null for every deployment.
	 *
	 * @return void
	 */
	public function testDownloadResolvesTheSingularEioMappingKeyAndAnswers404WhenAbsent(): void {
		$this->acceptJwt();
		$this->zgwService->method('getObjectService')->willReturn(
			$this->createMock(DrcDownloadLockObjectServiceStub::class)
		);

		$mappingService = $this->createMock(ZgwMappingService::class);
		$mappingService->expects($this->once())
			->method('getMapping')
			->with('enkelvoudiginformatieobject')
			->willReturn(null);
		$this->zgwService->method('getZgwMappingService')->willReturn($mappingService);
		$this->zgwService->expects($this->never())->method('getDocumentService');

		$response = $this->controller->download(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['detail' => 'Document mapping not configured'], $response->getData());
	}//end testDownloadResolvesTheSingularEioMappingKeyAndAnswers404WhenAbsent()

	/**
	 * When the stored file is missing, `download()` answers 404 and never reads
	 * content: a row whose file has gone must not be able to stream whatever the
	 * storage layer happens to return for that name.
	 *
	 * The existence probe is made with the file name recorded ON THE OBJECT, not
	 * with anything the caller supplied.
	 *
	 * @return void
	 */
	public function testDownloadAnswers404OnAMissingFileAndReadsNoContent(): void {
		$this->acceptJwt();

		$objectService = $this->createMock(DrcDownloadLockObjectServiceStub::class);
		$objectService->method('find')->willReturn(
			['fileName' => 'besluit.pdf', 'format' => 'application/pdf']
		);
		$this->zgwService->method('getObjectService')->willReturn($objectService);

		$mappingService = $this->createMock(ZgwMappingService::class);
		$mappingService->method('getMapping')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);
		$this->zgwService->method('getZgwMappingService')->willReturn($mappingService);

		$probed = [];
		$documentService = $this->createMock(ZgwDocumentService::class);
		$documentService->expects($this->once())
			->method('fileExists')
			->willReturnCallback(
				static function (string $uuid, string $fileName) use (&$probed): bool {
					$probed = ['uuid' => $uuid, 'fileName' => $fileName];
					return false;
				}
			);
		$documentService->expects($this->never())->method('getContent');
		$this->zgwService->method('getDocumentService')->willReturn($documentService);

		$response = $this->controller->download(uuid: 'doc-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['detail' => 'File not found.'], $response->getData());
		$this->assertSame(['uuid' => 'doc-1', 'fileName' => 'besluit.pdf'], $probed);
	}//end testDownloadAnswers404OnAMissingFileAndReadsNoContent()

	/**
	 * `lock()` refuses to re-lock an already-locked document with 400 and never
	 * takes a second lock — issuing a fresh lockId over an existing one would
	 * silently steal the lock from whoever holds it.
	 *
	 * @return void
	 */
	public function testLockRefusesAnAlreadyLockedDocumentWithoutTakingASecondLock(): void {
		$this->acceptJwt();

		$objectService = $this->createMock(DrcDownloadLockObjectServiceStub::class);
		$objectService->method('find')->willReturn(
			['fileName' => 'besluit.pdf', 'lockId' => 'held-by-someone-else']
		);
		$objectService->expects($this->never())->method('lockObject');
		$objectService->expects($this->never())->method('saveObject');
		$this->zgwService->method('getObjectService')->willReturn($objectService);

		$this->zgwService->expects($this->once())
			->method('loadMappingConfig')
			->with('documenten', 'enkelvoudiginformatieobjecten')
			->willReturn(['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']);

		$response = $this->controller->lock(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'Document is already locked.'], $response->getData());
	}//end testLockRefusesAnAlreadyLockedDocumentWithoutTakingASecondLock()

	/**
	 * A successful lock takes the store's lock AND persists the generated ZGW
	 * lockId in the data blob. The lockId handed back is the one stored: an
	 * unpersisted lockId could never be verified at unlock time, leaving the
	 * document locked forever.
	 *
	 * @return void
	 */
	public function testLockTakesTheLockAndPersistsTheGeneratedLockIdItReturns(): void {
		$this->acceptJwt();

		$saved = [];
		$objectService = $this->createMock(DrcDownloadLockObjectServiceStub::class);
		$objectService->method('find')->willReturn(['fileName' => 'besluit.pdf']);
		$objectService->expects($this->once())
			->method('lockObject')
			->willReturnCallback(
				static function (string $identifier): array {
					return ['id' => $identifier];
				}
			);
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				static function (
					string $register,
					string $schema,
					array $object,
					?string $uuid = null,
				) use (&$saved): array {
					$saved = ['object' => $object, 'uuid' => $uuid];
					return $object;
				}
			);
		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);

		$response = $this->controller->lock(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{32}$/',
			(string)$response->getData()['lock'],
			'the lock handed to the client must be a freshly generated 128-bit identifier'
		);
		$this->assertSame(
			$response->getData()['lock'],
			$saved['object']['lockId'],
			'the returned lockId must be the one persisted, or unlock can never verify it'
		);
		$this->assertTrue($saved['object']['locked']);
		$this->assertSame('doc-1', $saved['uuid']);
	}//end testLockTakesTheLockAndPersistsTheGeneratedLockIdItReturns()

	/**
	 * TRIPWIRE — pins CURRENT behaviour, which is NOT asserted to be correct.
	 *
	 * Neither `download()` nor `lock()` consults `ZgwService::consumerHasScope()`.
	 * Their siblings on this same controller do: `patch()` demands
	 * `documenten.bijwerken` and `unlock()` demands `geforceerd-bijwerken` before
	 * breaking a lock. So on these two routes a JWT that carries NO document
	 * scope at all can still read a document's bytes (`download`) and take a
	 * write lock on it (`lock`) — the ZGW standard assigns `documenten.lezen`
	 * and `documenten.bijwerken` to exactly those operations.
	 *
	 * The test asserts the scope oracle is NEVER called. If it goes RED, a scope
	 * check was ADDED — that is the fix, not a regression: replace this test
	 * with a `->with($this->request, 'drc', '<scope>')` refusal assertion in the
	 * shape used by DrcControllerContractTest. Do not weaken the controller.
	 *
	 * @return void
	 */
	public function testTripwireNeitherDownloadNorLockDemandsAnyDocumentScope(): void {
		$this->acceptJwt();
		$this->zgwService->expects($this->never())->method('consumerHasScope');

		$objectService = $this->createMock(DrcDownloadLockObjectServiceStub::class);
		$objectService->method('find')->willReturn(['fileName' => 'besluit.pdf']);
		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);

		$mappingService = $this->createMock(ZgwMappingService::class);
		$mappingService->method('getMapping')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);
		$this->zgwService->method('getZgwMappingService')->willReturn($mappingService);

		$documentService = $this->createMock(ZgwDocumentService::class);
		$documentService->method('fileExists')->willReturn(false);
		$this->zgwService->method('getDocumentService')->willReturn($documentService);

		$download = $this->controller->download(uuid: 'doc-1');
		$lock = $this->controller->lock(uuid: 'doc-1');

		// A scope-less JWT reaches the document logic on both routes: download
		// gets as far as looking for the file, lock gets as far as locking.
		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$download->getStatus(),
			'current behaviour: no scope is demanded, so the caller reaches the file lookup'
		);
		$this->assertSame(
			Http::STATUS_OK,
			$lock->getStatus(),
			'current behaviour: a scope-less JWT successfully locks the document'
		);
	}//end testTripwireNeitherDownloadNorLockDemandsAnyDocumentScope()
}//end class
