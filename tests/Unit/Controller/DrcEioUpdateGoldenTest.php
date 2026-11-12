<?php

/**
 * DrcController document update golden tests.
 *
 * Pins what PUT and PATCH on an enkelvoudiginformatieobject answer: the store
 * and mapping gates, every lock refusal (not locked, no lock id with its PUT
 * and PATCH envelopes, a wrong lock id), the lock read from the lock system,
 * the data blob and the locked flag, the business-rule refusal, the save that
 * keeps the stored lock, the content store, the notification and the error
 * answer.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DrcController;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An object service that also answers the lock system.
 */
interface DrcEioUpdateObjectServiceStub {
	/**
	 * Find a single object.
	 *
	 * @param int|string $id      The object UUID.
	 * @param mixed      ...$args The remaining find() args.
	 *
	 * @return mixed The stored row.
	 */
	public function find(int|string $id, ...$args): mixed;

	/**
	 * Save an object.
	 *
	 * @param mixed ...$args The saveObject() args.
	 *
	 * @return mixed The saved row.
	 */
	public function saveObject(...$args): mixed;

	/**
	 * The lock system's information on an object.
	 *
	 * @param string $uuid The object UUID.
	 *
	 * @return array<string, mixed> The lock info.
	 */
	public function getLockInfo(string $uuid): array;
}

/**
 * Golden tests for the document update of DrcController.
 *
 * @covers \OCA\Dossiq\Controller\DrcController
 *
 * @uses \OCA\Dossiq\Controller\ZgwController
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 */
class DrcEioUpdateGoldenTest extends TestCase {

	private const EIO = 'enkelvoudiginformatieobjecten';

	private const MAPPING = ['sourceRegister' => 'zgw', 'sourceSchema' => 'eio'];

	/**
	 * @var ZgwService&MockObject
	 */
	private $zgwService;

	/**
	 * @var DrcEioUpdateObjectServiceStub&MockObject
	 */
	private $objectService;

	/**
	 * @var ZgwBusinessRulesService&MockObject
	 */
	private $rules;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * @var ZgwDocumentService&MockObject
	 */
	private $documents;

	/**
	 * The calls the doubles saw, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The controller under test.
	 *
	 * @var DrcController
	 */
	private DrcController $controller;

	/**
	 * Build the controller over an authenticated, scoped caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$this->zgwService = $this->createMock(ZgwService::class);
		$this->objectService = $this->createMock(DrcEioUpdateObjectServiceStub::class);
		$this->rules = $this->createMock(ZgwBusinessRulesService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->documents = $this->createMock(ZgwDocumentService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->zgwService->method('validateJwtAuth')->willReturn(null);
		$this->zgwService->method('consumerHasScope')->willReturn(true);
		$this->zgwService->method('getBusinessRulesService')->willReturn($this->rules);
		$this->zgwService->method('getLogger')->willReturn($this->logger);
		$this->zgwService->method('getDocumentService')->willReturn($this->documents);
		$this->zgwService->method('buildBaseUrl')->willReturn('https://zgw.test/documenten/api/v1/' . self::EIO);
		$this->zgwService->method('createInboundMapping')->willReturn(new \stdClass());
		$this->zgwService->method('createOutboundMapping')->willReturn(new \stdClass());
		$this->zgwService->method('applyInboundMapping')->willReturnCallback(
			static fn (array $body): array => ['title' => ($body['titel'] ?? ''), 'content' => 'raw', 'locked' => 'from-body']
		);
		$this->zgwService->method('applyOutboundMapping')->willReturnCallback(
			static fn (array $objectData, object $mapping, array $mappingConfig, string $baseUrl): array => ['url' => $baseUrl . '/' . $objectData['id'], 'titel' => $objectData['title'] ?? '']
		);
		$this->zgwService->method('publishNotification')->willReturnCallback(
			function (...$args): void {
				$this->calls[] = ['notify', ...$args];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args): array {
				$this->calls[] = ['save', $args];
				return $args['object'] + ['id' => 'doc-1'];
			}
		);

		$this->controller = new DrcController(appName: 'dossiq', request: $request, zgwService: $this->zgwService, l10n: $l10n);
	}//end setUp()

	/**
	 * The store is wired, the mapping found, and the body is this.
	 *
	 * @param array<string, mixed> $body The request body.
	 *
	 * @return void
	 */
	private function wired(array $body): void {
		$this->zgwService->method('getObjectService')->willReturn($this->objectService);
		$this->zgwService->method('loadMappingConfig')->willReturnCallback(
			static fn (string $api, string $resource): ?array => ($api === 'documenten' && $resource === self::EIO ? self::MAPPING : null)
		);
		$this->zgwService->method('getRequestBody')->willReturn($body);
	}//end wired()

	/**
	 * The stored document carries this lock state; the lock system knows none.
	 *
	 * @param array<string, mixed> $stored The stored document.
	 *
	 * @return void
	 */
	private function stored(array $stored): void {
		$this->objectService->method('getLockInfo')->willReturn([]);
		$this->objectService->method('find')->willReturn($stored + ['id' => 'doc-1']);
	}//end stored()

	/**
	 * The business rules accept the body, enriched.
	 *
	 * @return array<int, array<string, mixed>> The validate() calls seen.
	 */
	private function rulesAccept(): array {
		$seen = [];
		$this->rules->method('validate')->willReturnCallback(
			function (string $zgwApi, string $resource, string $action, array $body, ...$rest) use (&$seen): array {
				$this->calls[] = ['validate', $zgwApi, $resource, $action, $body, $rest];
				return ['valid' => true, 'enrichedBody' => $body + ['enriched' => true]];
			}
		);

		return $seen;
	}//end rulesAccept()

	/**
	 * The lock refusal envelope.
	 *
	 * @param string $detail The detail.
	 * @param string $name   The invalid param name.
	 * @param string $code   The invalid param code.
	 * @param string $reason The invalid param reason.
	 *
	 * @return array<string, mixed>
	 */
	private static function refusal(string $detail, string $name, string $code, string $reason): array {
		return ['detail' => $detail, 'invalidParams' => [['name' => $name, 'code' => $code, 'reason' => $reason]]];
	}//end refusal()

	/**
	 * Without the store, or without a mapping, nothing is read.
	 *
	 * @return void
	 */
	public function testTheStoreAndMappingGates(): void {
		$unavailable = new JSONResponse(['detail' => 'unavailable'], 503);
		$this->zgwService->method('unavailableResponse')->willReturn($unavailable);
		$this->zgwService->method('getObjectService')->willReturnOnConsecutiveCalls(null, $this->objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(null);
		$missing = new JSONResponse(['detail' => 'no mapping'], 404);
		$this->zgwService->expects($this->once())->method('mappingNotFoundResponse')->with('documenten', self::EIO)->willReturn($missing);
		$this->zgwService->expects($this->never())->method('getRequestBody');

		$this->assertSame($unavailable, $this->controller->update(self::EIO, 'doc-1'));
		$this->assertSame($missing, $this->controller->patch(self::EIO, 'doc-1'));
	}//end testTheStoreAndMappingGates()

	/**
	 * An unlocked document is refused for PUT and PATCH alike, before the rules run.
	 *
	 * @return void
	 */
	public function testAnUnlockedDocumentIsRefused(): void {
		$this->wired(['lock' => 'any']);
		$this->stored(['locked' => false]);
		$this->rules->expects($this->never())->method('validate');
		$expected = self::refusal('Only locked documents may be edited.', 'nonFieldErrors', 'unlocked', 'The document is not locked. Lock the document first.');

		foreach ([$this->controller->update(self::EIO, 'doc-1'), $this->controller->patch(self::EIO, 'doc-1')] as $response) {
			$this->assertSame(400, $response->getStatus());
			$this->assertSame($expected, $response->getData());
		}
	}//end testAnUnlockedDocumentIsRefused()

	/**
	 * A missing lock id names the lock field on PUT and a non-field error on PATCH.
	 *
	 * @return void
	 */
	public function testAMissingLockIdIsRefused(): void {
		$this->wired(['titel' => 'Nota']);
		$this->stored(['lockId' => 'L-1']);
		$this->rules->expects($this->never())->method('validate');

		$put = $this->controller->update(self::EIO, 'doc-1');
		$patch = $this->controller->patch(self::EIO, 'doc-1');

		$this->assertSame(400, $put->getStatus());
		$this->assertSame(self::refusal('Lock ID is required for editing a locked document.', 'lock', 'required', 'Lock ID is missing from the request.'), $put->getData());
		$this->assertSame(400, $patch->getStatus());
		$this->assertSame(self::refusal('Lock ID is required for editing a locked document.', 'nonFieldErrors', 'missing-lock-id', 'Lock ID is missing from the request.'), $patch->getData());
	}//end testAMissingLockIdIsRefused()

	/**
	 * A wrong lock id is refused, also against the lock system's own lock.
	 *
	 * @return void
	 */
	public function testAWrongLockIdIsRefused(): void {
		$this->wired(['lock' => 'L-1']);
		$this->objectService->method('getLockInfo')->willReturn(['lock_id' => 'L-system']);
		$this->objectService->expects($this->never())->method('find');
		$this->rules->expects($this->never())->method('validate');

		$response = $this->controller->patch(self::EIO, 'doc-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(self::refusal('Lock ID does not match.', 'nonFieldErrors', 'incorrect-lock-id', 'Lock ID does not match the stored lock.'), $response->getData());
	}//end testAWrongLockIdIsRefused()

	/**
	 * A locked flag without an id is the entity lock, which its own id opens.
	 *
	 * @return void
	 */
	public function testTheEntityLockIsMatchedByItsId(): void {
		$this->wired(['lock' => 'entity-lock', 'titel' => 'Nota']);
		$this->stored(['locked' => 'true']);
		$this->rulesAccept();

		$response = $this->controller->update(self::EIO, 'doc-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('update', $this->calls[0][3]);
	}//end testTheEntityLockIsMatchedByItsId()

	/**
	 * The rules' refusal is answered with its own status, and nothing is saved.
	 *
	 * @return void
	 */
	public function testABusinessRuleRefusalIsAnswered(): void {
		$this->wired(['lock' => 'L-1']);
		$this->stored(['lockId' => 'L-1']);
		$ruleResult = ['valid' => false, 'status' => 409, 'detail' => 'conflict'];
		$this->rules->method('validate')->willReturn($ruleResult);
		$this->zgwService->method('buildValidationError')->with($ruleResult)->willReturn(['detail' => 'conflict built']);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->patch(self::EIO, 'doc-1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['detail' => 'conflict built'], $response->getData());
	}//end testABusinessRuleRefusalIsAnswered()

	/**
	 * A matching PATCH is validated as a partial update, saved with the stored
	 * lock, its content stored, notified and answered mapped.
	 *
	 * @return void
	 */
	public function testAMatchingUpdateIsSavedStoredAndNotified(): void {
		$this->wired(['lock' => 'L-1', 'titel' => 'Nota', 'inhoud' => 'aGFsbG8=']);
		$this->stored(['lockId' => 'L-1', 'locked' => true, 'fileName' => 'nota.pdf']);
		$this->rulesAccept();
		$this->documents->expects($this->once())->method('storeBase64')->with('doc-1', 'document', 'aGFsbG8=')->willReturn(5);
		$this->documents->method('getFileId')->willReturn(77);

		$response = $this->controller->patch(self::EIO, 'doc-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['url' => 'https://zgw.test/documenten/api/v1/' . self::EIO . '/doc-1', 'titel' => 'Nota'], $response->getData());

		[$validate, $save, $storeSave, $notify] = $this->calls;
		$this->assertSame(['validate', 'documenten', self::EIO, 'partial_update', ['lock' => 'L-1', 'titel' => 'Nota', 'inhoud' => 'aGFsbG8=']], array_slice($validate, 0, 5));
		$this->assertSame([null, $this->objectService, self::MAPPING], array_slice($validate[5], 0, 3));
		$this->assertSame(['register' => 'zgw', 'schema' => 'eio', 'object' => ['title' => 'Nota', 'locked' => true, 'lockId' => 'L-1'], 'uuid' => 'doc-1'], $save[1]);
		$this->assertSame(['title' => 'Nota', 'locked' => true, 'lockId' => 'L-1', 'id' => 'doc-1', 'fileSize' => 5, 'fileId' => 77, 'uuid' => 'doc-1'], $storeSave[1]['object']);
		$this->assertSame(['notify', 'documenten', self::EIO, 'https://zgw.test/documenten/api/v1/' . self::EIO . '/doc-1', 'update'], $notify);
	}//end testAMatchingUpdateIsSavedStoredAndNotified()

	/**
	 * A failure after the lock check is logged and answered as a 400 with its message.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndAnswered(): void {
		$this->wired(['lock' => 'L-1']);
		$this->stored(['lockId' => 'L-1']);
		$this->rules->method('validate')->willThrowException(new RuntimeException('rules down'));
		$this->logger->expects($this->once())->method('error')->with('DRC update error: rules down', $this->callback(static fn (array $c): bool => $c['exception'] instanceof RuntimeException));

		$response = $this->controller->update(self::EIO, 'doc-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['detail' => 'rules down'], $response->getData());
	}//end testAFailureIsLoggedAndAnswered()
}//end class
