<?php

/**
 * DrcController: the flat-array lists, the audit trail guard, show for other
 * resources, and the lock and unlock fallbacks used when OpenRegister's lock
 * system fails without a user session.
 *
 * @category Test
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
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The OpenRegister ObjectService surface these paths call, without a lock system.
 */
interface DrcFallbackObjectServiceStub {
	public function find(mixed $id, mixed $register=null, mixed $schema=null): mixed;

	public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array;

	public function searchObjectsPaginated(array $query): array;

	public function saveObject(mixed $register, mixed $schema, array $object, ?string $uuid=null): mixed;

	public function lockObject(string $identifier): mixed;

	public function unlockObject(string $identifier): mixed;
}//end interface

/**
 * @covers \OCA\Dossiq\Controller\DrcController
 * @uses   \OCA\Dossiq\Controller\ZgwController
 * @uses   \OCA\Dossiq\Support\NormalisesObjectRows
 */
class DrcFlatListAuditAndLockFallbackTest extends TestCase {

	private const UUID = 'd0000000-1111-2222-3333-444455556666';

	private const MAPPING = ['sourceRegister' => '1', 'sourceSchema' => '15'];

	/**
	 * The ZGW service.
	 *
	 * @var ZgwService&MockObject
	 */
	private $zgw;

	/**
	 * The object service.
	 *
	 * @var DrcFallbackObjectServiceStub&MockObject
	 */
	private $objects;

	/**
	 * The logger the controller reports to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Objects saved, as [object, uuid].
	 *
	 * @var list<array{0: array, 1: ?string}>
	 */
	private array $saved = [];

	/**
	 * Set up a ZGW service with an authorised caller and the EIO mapping.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(DrcFallbackObjectServiceStub::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (mixed $register, mixed $schema, array $object, ?string $uuid=null): array {
				$this->saved[] = [$object, $uuid];
				return $object;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->zgw = $this->createMock(ZgwService::class);
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->method('getLogger')->willReturn($this->logger);
		$this->zgw->method('getObjectService')->willReturn($this->objects);
		$this->zgw->method('loadMappingConfig')->willReturn(self::MAPPING);
	}//end setUp()

	/**
	 * The controller over the service, with the request parameters given.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return DrcController
	 */
	private function controller(array $params=[]): DrcController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DrcController(appName: 'dossiq', request: $request, zgwService: $this->zgw, l10n: $l10n);
	}//end controller()

	/**
	 * Gebruiksrechten list as a plain array: translated filters, at most 100, each row mapped outbound.
	 *
	 * @return void
	 */
	public function testGebruiksrechtenListAsAPlainMappedArray(): void {
		$this->zgw->expects($this->never())->method('handleIndex');
		$this->zgw->method('translateQueryParams')->willReturnCallback(
			static fn (array $params, array $mappingConfig): array => ['informatieobject' => $params['informatieobject']]
		);
		$this->objects->expects($this->once())->method('buildSearchQuery')
			->with(['informatieobject' => 'eio-1', '_limit' => 100], '1', '15')
			->willReturn(['q' => 'built']);
		$this->objects->method('searchObjectsPaginated')->with(['q' => 'built'])->willReturn(
			['results' => [['id' => 'g-1', 'omschrijving' => 'Eén'], ['id' => 'g-2', 'omschrijving' => 'Twee']]]
		);
		$this->zgw->method('buildBaseUrl')->willReturn('https://zaken.example/drc/gebruiksrechten');
		$this->zgw->method('createOutboundMapping')->willReturn(new \stdClass());
		$this->zgw->method('applyOutboundMapping')->willReturnCallback(
			static fn (array $objectData, object $mapping, array $mappingConfig, string $baseUrl): array => ['url' => $baseUrl.'/'.$objectData['id'], 'omschrijving' => $objectData['omschrijving']]
		);

		$response = $this->controller(params: ['informatieobject' => 'eio-1'])->index(resource: 'gebruiksrechten');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				['url' => 'https://zaken.example/drc/gebruiksrechten/g-1', 'omschrijving' => 'Eén'],
				['url' => 'https://zaken.example/drc/gebruiksrechten/g-2', 'omschrijving' => 'Twee'],
			],
			$response->getData()
		);
	}//end testGebruiksrechtenListAsAPlainMappedArray()

	/**
	 * A failing search answers 500 and is logged; other resources go to the generic list.
	 *
	 * @return void
	 */
	public function testAFailingFlatListIs500AndOtherResourcesUseTheGenericList(): void {
		$this->zgw->method('translateQueryParams')->willReturn([]);
		$this->objects->method('buildSearchQuery')->willThrowException(new RuntimeException('database down'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('DRC list objectinformatieobjecten error: database down'));

		$failed = $this->controller()->index(resource: 'objectinformatieobjecten');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['detail' => 'Internal server error'], $failed->getData());

		$generic = new JSONResponse(data: ['count' => 0, 'results' => []]);
		$this->zgw->expects($this->once())->method('handleIndex')->with($this->anything(), 'documenten', 'enkelvoudiginformatieobjecten')->willReturn($generic);
		$this->assertSame($generic, $this->controller()->index(resource: 'enkelvoudiginformatieobjecten'));
	}//end testAFailingFlatListIs500AndOtherResourcesUseTheGenericList()

	/**
	 * Without OpenRegister or a mapping the flat list answers what the ZGW service says.
	 *
	 * @return void
	 */
	public function testTheFlatListWithoutObjectServiceOrMapping(): void {
		$unavailable = new JSONResponse(data: ['detail' => 'unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		$notMapped = new JSONResponse(data: ['detail' => 'no mapping'], statusCode: Http::STATUS_NOT_FOUND);

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturn(null);
		$zgw->method('getObjectService')->willReturnOnConsecutiveCalls(null, $this->objects);
		$zgw->method('unavailableResponse')->willReturn($unavailable);
		$zgw->method('loadMappingConfig')->willReturn(null);
		$zgw->method('mappingNotFoundResponse')->with('documenten', 'gebruiksrechten')->willReturn($notMapped);
		$this->zgw = $zgw;

		$this->assertSame($unavailable, $this->controller()->index(resource: 'gebruiksrechten'));
		$this->assertSame($notMapped, $this->controller()->index(resource: 'gebruiksrechten'));
	}//end testTheFlatListWithoutObjectServiceOrMapping()

	/**
	 * A refused caller gets the auth answer before anything is read.
	 *
	 * @return void
	 */
	public function testARefusedCallerReadsNothing(): void {
		$refused = new JSONResponse(data: ['detail' => 'no token'], statusCode: Http::STATUS_FORBIDDEN);
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturn($refused);
		$zgw->expects($this->never())->method('getObjectService');
		$zgw->expects($this->never())->method('handleAudittrailShow');
		$this->zgw = $zgw;

		$this->assertSame($refused, $this->controller()->index(resource: 'gebruiksrechten'));
		$this->assertSame($refused, $this->controller()->show(resource: 'gebruiksrechten', uuid: self::UUID));
		$this->assertSame($refused, $this->controller()->audittrailIndex(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID));
		$this->assertSame($refused, $this->controller()->audittrailShow(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID, auditUuid: 'a-1'));
		$this->assertSame($refused, $this->controller()->lock(uuid: self::UUID));
	}//end testARefusedCallerReadsNothing()

	/**
	 * The audit trail of a document that no longer exists is 404; an existing one goes to the ZGW service.
	 *
	 * @return void
	 */
	public function testTheAuditTrailOfAGoneDocumentIs404(): void {
		$trail = new JSONResponse(data: [['uuid' => 'a-1']]);
		$this->objects->method('find')->willReturnCallback(
			static function (mixed $id): array {
				if ($id === 'gone') {
					throw new RuntimeException('not found');
				}

				return ['id' => $id];
			}
		);
		$this->zgw->expects($this->once())->method('handleAudittrailIndex')->with($this->anything(), 'documenten', 'enkelvoudiginformatieobjecten', self::UUID)->willReturn($trail);
		$this->zgw->expects($this->once())->method('handleAudittrailShow')->with($this->anything(), 'documenten', 'enkelvoudiginformatieobjecten', self::UUID, 'a-1')->willReturn($trail);

		$gone = $this->controller()->audittrailIndex(resource: 'enkelvoudiginformatieobjecten', uuid: 'gone');
		$this->assertSame(Http::STATUS_NOT_FOUND, $gone->getStatus());
		$this->assertSame(['detail' => 'Not found.'], $gone->getData());

		$this->assertSame($trail, $this->controller()->audittrailIndex(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID));
		$this->assertSame($trail, $this->controller()->audittrailShow(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID, auditUuid: 'a-1'));
	}//end testTheAuditTrailOfAGoneDocumentIs404()

	/**
	 * Show of a resource other than the document is the ZGW answer as it is.
	 *
	 * @return void
	 */
	public function testShowOfAnotherResourcePassesThrough(): void {
		$shown = new JSONResponse(data: ['url' => 'x']);
		$this->zgw->expects($this->once())->method('handleShow')->with($this->anything(), 'documenten', 'gebruiksrechten', self::UUID)->willReturn($shown);
		$this->objects->expects($this->never())->method('find');

		$this->assertSame($shown, $this->controller()->show(resource: 'gebruiksrechten', uuid: self::UUID));
	}//end testShowOfAnotherResourcePassesThrough()

	/**
	 * When OpenRegister's lock fails, the lock is written into the document itself.
	 *
	 * @return void
	 */
	public function testTheLockFallbackWritesTheLockIntoTheDocument(): void {
		$this->objects->method('find')->willReturn(['@self' => ['id' => self::UUID], 'id' => self::UUID, 'organisation' => 'org', 'titel' => 'Brief', 'locked' => false]);
		$this->objects->method('lockObject')->willThrowException(new RuntimeException('no user session'));

		$response = $this->controller()->lock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$lockId = $response->getData()['lock'];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $lockId);
		$this->assertSame([[['titel' => 'Brief', 'locked' => true, 'lockId' => $lockId], self::UUID]], $this->saved);
	}//end testTheLockFallbackWritesTheLockIntoTheDocument()

	/**
	 * A fallback that cannot read the document answers 400 with the reason, and logs it.
	 *
	 * @return void
	 */
	public function testALockFallbackThatCannotReadTheDocumentIs400(): void {
		$calls = 0;
		$this->objects->method('find')->willReturnCallback(
			static function () use (&$calls): array {
				$calls++;
				if ($calls > 1) {
					throw new RuntimeException('document vanished');
				}

				return ['titel' => 'Brief'];
			}
		);
		$this->objects->method('lockObject')->willThrowException(new RuntimeException('no user session'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('DRC lock fallback error: document vanished'));

		$response = $this->controller()->lock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'document vanished'], $response->getData());
		$this->assertSame([], $this->saved);
	}//end testALockFallbackThatCannotReadTheDocumentIs400()

	/**
	 * When OpenRegister's unlock fails, the lock is cleared in the document and empty fields are dropped.
	 *
	 * @return void
	 */
	public function testTheUnlockFallbackClearsTheLockInTheDocument(): void {
		$this->objects->method('find')->willReturn(['@self' => [], 'id' => self::UUID, 'organisation' => 'org', 'titel' => 'Brief', 'locked' => true, 'lockId' => 'abc', 'beschrijving' => null]);
		$this->objects->method('unlockObject')->willThrowException(new RuntimeException('no user session'));
		$this->zgw->method('getRequestBody')->willReturn(['lock' => 'abc']);

		$response = $this->controller()->unlock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame([[['titel' => 'Brief', 'locked' => false, 'lockId' => ''], self::UUID]], $this->saved);
	}//end testTheUnlockFallbackClearsTheLockInTheDocument()

	/**
	 * Unlocking with the wrong lock and no forcing scope is refused; a document that is not locked too.
	 *
	 * @return void
	 */
	public function testAnUnforcedUnlockWithTheWrongLockIsRefused(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(['locked' => true, 'lockId' => 'abc'], ['titel' => 'Brief']);
		$this->objects->expects($this->never())->method('unlockObject');
		$this->zgw->method('getRequestBody')->willReturn(['lock' => 'xyz']);
		$this->zgw->method('consumerHasScope')->willReturn(false);

		$wrong = $this->controller()->unlock(uuid: self::UUID);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $wrong->getStatus());
		$this->assertSame('incorrect-lock-id', $wrong->getData()['invalidParams'][0]['code']);
		$this->assertSame('Lock ID does not match and forced unlocking is not allowed.', $wrong->getData()['detail']);

		$notLocked = $this->controller()->unlock(uuid: self::UUID);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $notLocked->getStatus());
		$this->assertSame(['detail' => 'Document is not locked.'], $notLocked->getData());
	}//end testAnUnforcedUnlockWithTheWrongLockIsRefused()
}//end class
