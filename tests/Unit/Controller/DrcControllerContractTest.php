<?php

/**
 * DrcController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for three DRC endpoints that had no automated
 * proof of their wire behaviour: `patch()`, `unlock()` and `uploadChunk()`.
 * All three are `@PublicPage` — reachable WITHOUT a Nextcloud session — so the
 * ZGW JWT plus, where the operation demands one, an OAuth-style scope is the
 * entire gate. Those two facts are what these tests pin:
 *
 *  - none of the three runs a line of its body before `validateJwtAuth()` has
 *    answered, and the refusal is returned verbatim;
 *  - `patch()` demands `documenten.bijwerken` on the `drc` component and
 *    refuses with the ZGW-standard 403 `permission_denied` envelope naming that
 *    scope — a copy-paste of `documenten.verwijderen` (the delete scope) or of
 *    another component would be invisible from the response shape;
 *  - `patch()` delegates as a PARTIAL update. It shares its delegate with
 *    `update()` and the only thing separating them is that boolean, so a
 *    PATCH that silently became a full replace would blank every field the
 *    caller did not send;
 *  - `unlock()` is a lock-protected operation: an unlocked document is a 400,
 *    and a WRONG lock id is refused unless the consumer holds the forced
 *    unlocking scope. Both the scope name and the ZGW `incorrect-lock-id`
 *    invalidParams envelope are asserted, because a lock that any caller can
 *    break is not a lock;
 *  - `uploadChunk()` refuses everything it cannot place: no pending chunked
 *    upload, a sequence number outside 1..totalParts, and — the one that
 *    matters for storage — an EMPTY body, which must never be stored as a
 *    zero-byte chunk that then merges into a corrupt file.
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
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Minimal OpenRegister ObjectService stub for the DRC contract tests.
 *
 * The controller calls the store with NAMED arguments, so the stub's parameter
 * names must match the real service's exactly; a \stdClass-based mock cannot be
 * called with named arguments at all.
 */
interface DrcContractObjectServiceStub {
	/**
	 * Find a single object by identifier (real ObjectService::find()).
	 *
	 * @param int|string $id The object UUID.
	 * @param mixed ...$args Remaining find() args (extend/files/register/schema).
	 *
	 * @return mixed The stored object row.
	 */
	public function find(int|string $id, ...$args): mixed;

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
	 * Release OpenRegister's own lock on an object.
	 *
	 * @param string $identifier The object UUID.
	 *
	 * @return mixed The unlocked object row.
	 */
	public function unlockObject(string $identifier): mixed;
}//end interface

/**
 * Wire-contract tests for DrcController::patch/unlock/uploadChunk.
 *
 * @covers \OCA\Procest\Controller\DrcController
 *
 * DrcController extends ZgwController, which composes NormalisesObjectRows, so
 * exercising it necessarily runs code declared on both. CI runs phpunit.xml
 * with beStrictAboutCoverageMetadata="true" and failOnRisky="true", which marks
 * executed-but-unlisted code risky and fails the run.
 *
 * @uses \OCA\Procest\Controller\ZgwController
 * @uses \OCA\Procest\Support\NormalisesObjectRows
 */
class DrcControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The ZgwService mock — JWT gate, scope oracle and store accessor.
	 *
	 * @var ZgwService|MockObject
	 */
	private ZgwService $zgwService;

	/**
	 * The IL10N mock; `t()` echoes its message so the branch can be identified.
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
	 * All three endpoints consult the JWT gate before anything else and return
	 * its refusal verbatim — none of them reaches the store or the scope check.
	 *
	 * @return void
	 */
	public function testAllThreeEndpointsRefuseAnUnauthenticatedCaller(): void {
		$refusal = $this->refuseJwt();
		$this->zgwService->expects($this->never())->method('getObjectService');
		$this->zgwService->expects($this->never())->method('consumerHasScope');
		$this->zgwService->expects($this->never())->method('handleUpdate');

		$responses = [
			'patch' => $this->controller->patch(resource: 'gebruiksrechten', uuid: 'doc-1'),
			'unlock' => $this->controller->unlock(uuid: 'doc-1'),
			'uploadChunk' => $this->controller->uploadChunk(uuid: 'doc-1'),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame($refusal, $response, $endpoint . ' must return the JWT refusal verbatim');
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		}
	}//end testAllThreeEndpointsRefuseAnUnauthenticatedCaller()

	/**
	 * A PATCH demands the DRC write scope `documenten.bijwerken`, and without
	 * it answers the ZGW 403 envelope naming that scope — while writing
	 * nothing.
	 *
	 * @return void
	 */
	public function testPatchDemandsTheDocumentenBijwerkenScopeAndRefusesWithout(): void {
		$this->acceptJwt();

		$this->zgwService->expects($this->once())
			->method('consumerHasScope')
			->with($this->request, 'drc', 'documenten.bijwerken')
			->willReturn(false);
		$this->zgwService->expects($this->never())->method('handleUpdate');

		$response = $this->controller->patch(resource: 'gebruiksrechten', uuid: 'doc-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('permission_denied', $response->getData()['code']);
		$this->assertSame(
			'Scope documenten.bijwerken is required for this operation.',
			$response->getData()['detail'],
			'the refusal must name the scope the caller is missing'
		);
	}//end testPatchDemandsTheDocumentenBijwerkenScopeAndRefusesWithout()

	/**
	 * A scoped PATCH delegates to the documenten API as a PARTIAL update. The
	 * `partial` flag is the only thing separating this route from `update()`,
	 * and a PATCH that ran as a full replace would blank every unsent field.
	 *
	 * @return void
	 */
	public function testPatchDelegatesToTheDocumentenApiAsAPartialUpdate(): void {
		$this->acceptJwt();
		$this->zgwService->method('consumerHasScope')->willReturn(true);
		$expected = new JSONResponse(data: ['url' => 'https://example.test/gebruiksrechten/doc-1']);

		$seen = [];
		$this->zgwService->expects($this->once())
			->method('handleUpdate')
			->willReturnCallback(
				static function (
					IRequest $request,
					string $zgwApi,
					string $resource,
					string $uuid,
					bool $partial = false,
					mixed ...$rest,
				) use (&$seen, $expected): JSONResponse {
					$seen = [
						'zgwApi' => $zgwApi,
						'resource' => $resource,
						'uuid' => $uuid,
						'partial' => $partial,
					];
					return $expected;
				}
			);

		$response = $this->controller->patch(resource: 'gebruiksrechten', uuid: 'doc-1');

		$this->assertSame($expected, $response);
		$this->assertSame(
			['zgwApi' => 'documenten', 'resource' => 'gebruiksrechten', 'uuid' => 'doc-1', 'partial' => true],
			$seen
		);
	}//end testPatchDelegatesToTheDocumentenApiAsAPartialUpdate()

	/**
	 * Unlocking a document that is not locked is a 400, not a silent success —
	 * a client that gets 204 for an unlocked document cannot tell that its own
	 * lock was never held.
	 *
	 * @return void
	 */
	public function testUnlockAnswers400WhenTheDocumentIsNotLocked(): void {
		$this->acceptJwt();
		$objectService = $this->createMock(DrcContractObjectServiceStub::class);
		$objectService->method('find')->willReturn(['fileName' => 'brief.pdf']);
		$objectService->expects($this->never())->method('unlockObject');

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);

		$response = $this->controller->unlock(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'Document is not locked.'], $response->getData());
	}//end testUnlockAnswers400WhenTheDocumentIsNotLocked()

	/**
	 * A wrong lock id is refused unless the consumer holds the forced-unlocking
	 * scope. The scope name and component are asserted, and the ZGW
	 * `incorrect-lock-id` envelope with it — a lock any caller can break is not
	 * a lock.
	 *
	 * @return void
	 */
	public function testUnlockRefusesAWrongLockIdWithoutTheForcedUnlockingScope(): void {
		$this->acceptJwt();
		$objectService = $this->createMock(DrcContractObjectServiceStub::class);
		$objectService->method('find')->willReturn(['lockId' => 'the-real-lock']);
		$objectService->expects($this->never())->method('unlockObject');

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);
		$this->zgwService->method('getRequestBody')->willReturn(['lock' => 'a-guessed-lock']);

		$this->zgwService->expects($this->once())
			->method('consumerHasScope')
			->with($this->request, 'documenten', 'geforceerd-bijwerken')
			->willReturn(false);

		$response = $this->controller->unlock(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			'Lock ID does not match and forced unlocking is not allowed.',
			$response->getData()['detail']
		);
		$this->assertSame('incorrect-lock-id', $response->getData()['invalidParams'][0]['code']);
	}//end testUnlockRefusesAWrongLockIdWithoutTheForcedUnlockingScope()

	/**
	 * The matching lock id unlocks: the store's own lock is released, the
	 * lockId is cleared from the data blob (a stale lockId would keep the
	 * document unwritable for every later caller), and the answer is 204.
	 *
	 * @return void
	 */
	public function testUnlockWithTheMatchingLockIdClearsTheLockAndAnswers204(): void {
		$this->acceptJwt();

		$saved = [];
		$objectService = $this->createMock(DrcContractObjectServiceStub::class);
		$objectService->method('find')->willReturn(['lockId' => 'the-real-lock', 'locked' => true]);
		$objectService->expects($this->once())
			->method('unlockObject')
			->with('doc-1')
			->willReturn(['id' => 'doc-1']);
		$objectService->method('saveObject')->willReturnCallback(
			static function (
				string $register,
				string $schema,
				array $object,
				?string $uuid = null,
			) use (&$saved): array {
				$saved = $object;
				return $object;
			}
		);

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);
		$this->zgwService->method('getRequestBody')->willReturn(['lock' => 'the-real-lock']);
		$this->zgwService->expects($this->never())->method('consumerHasScope');

		$response = $this->controller->unlock(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame('', $saved['lockId'], 'the stored lockId must be cleared');
		$this->assertFalse($saved['locked']);
	}//end testUnlockWithTheMatchingLockIdClearsTheLockAndAnswers204()

	/**
	 * With OpenRegister unwired, unlock answers 503 rather than throwing.
	 *
	 * @return void
	 */
	public function testUnlockAnswers503WhenTheObjectStoreIsUnavailable(): void {
		$this->acceptJwt();
		$this->zgwService->method('getObjectService')->willReturn(null);
		$this->zgwService->method('unavailableResponse')->willReturn(
			new JSONResponse(
				data: ['detail' => 'OpenRegister is not available'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			)
		);

		$response = $this->controller->unlock(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['detail' => 'OpenRegister is not available'], $response->getData());
	}//end testUnlockAnswers503WhenTheObjectStoreIsUnavailable()

	/**
	 * uploadChunk resolves the EIO mapping of the documenten API — a chunk
	 * belongs to an enkelvoudiginformatieobject and to nothing else — and
	 * answers the mapping-not-found response when it is absent.
	 *
	 * @return void
	 */
	public function testUploadChunkResolvesTheEioMappingAndAnswers404WhenAbsent(): void {
		$this->acceptJwt();
		$this->zgwService->method('getObjectService')->willReturn(
			$this->createMock(DrcContractObjectServiceStub::class)
		);

		$this->zgwService->expects($this->once())
			->method('loadMappingConfig')
			->with('documenten', 'enkelvoudiginformatieobjecten')
			->willReturn(null);
		$this->zgwService->method('mappingNotFoundResponse')->willReturn(
			new JSONResponse(data: ['detail' => 'no mapping'], statusCode: Http::STATUS_NOT_FOUND)
		);
		$this->zgwService->expects($this->never())->method('getDocumentService');

		$response = $this->controller->uploadChunk(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUploadChunkResolvesTheEioMappingAndAnswers404WhenAbsent()

	/**
	 * A document with no pending chunked upload cannot receive chunks: 400, and
	 * the document service is never asked to store anything.
	 *
	 * @return void
	 */
	public function testUploadChunkRefusesADocumentWithNoPendingChunkedUpload(): void {
		$this->acceptJwt();
		$objectService = $this->createMock(DrcContractObjectServiceStub::class);
		$objectService->method('find')->willReturn(['fileName' => 'brief.pdf', 'fileParts' => '']);

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);
		$this->zgwService->expects($this->never())->method('getDocumentService');

		$response = $this->controller->uploadChunk(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'This document has no pending chunked upload.'], $response->getData());
	}//end testUploadChunkRefusesADocumentWithNoPendingChunkedUpload()

	/**
	 * A sequence number outside 1..totalParts is refused — accepting part 4 of
	 * a 3-part upload would write a chunk that the merge can never consume.
	 *
	 * @return void
	 */
	public function testUploadChunkRefusesASequenceNumberOutsideTheDeclaredRange(): void {
		$this->acceptJwt();
		$objectService = $this->createMock(DrcContractObjectServiceStub::class);
		$objectService->method('find')->willReturn(
			['fileName' => 'brief.pdf', 'fileParts' => '{"pending":true,"totalParts":3}']
		);
		$this->withRequestParams(['sequenceNumber' => '4']);

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);
		$this->zgwService->expects($this->never())->method('getDocumentService');

		$response = $this->controller->uploadChunk(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'Invalid sequence number. Expected 1-%s.'], $response->getData());
	}//end testUploadChunkRefusesASequenceNumberOutsideTheDeclaredRange()

	/**
	 * An empty body is refused with 400 and NEVER stored: a zero-byte chunk
	 * would count towards the part total and merge into a corrupt file.
	 *
	 * @return void
	 */
	public function testUploadChunkRefusesAnEmptyBodyRatherThanStoringAZeroByteChunk(): void {
		$this->acceptJwt();
		$objectService = $this->createMock(DrcContractObjectServiceStub::class);
		$objectService->method('find')->willReturn(
			['fileName' => 'brief.pdf', 'fileParts' => '{"pending":true,"totalParts":3}']
		);
		$this->withRequestParams(['sequenceNumber' => '1']);

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);
		// The PHPUnit CLI has an empty php://input, which is exactly the
		// "caller sent no bytes" case this branch exists for.
		$this->zgwService->expects($this->never())->method('getDocumentService');
		$objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->uploadChunk(uuid: 'doc-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'No file content received.'], $response->getData());
	}//end testUploadChunkRefusesAnEmptyBodyRatherThanStoringAZeroByteChunk()
}//end class
