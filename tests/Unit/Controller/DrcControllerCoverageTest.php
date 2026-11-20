<?php

/**
 * DrcController coverage tests.
 *
 * Covers the branches the contract and golden tests leave out: the scope
 * refusals of every write, the generic create / update for non-EIO resources,
 * indicatieGebruiksrecht maintenance on gebruiksrecht create and delete, the
 * failure paths of create, download, lock and unlock, bestandsdelen
 * enrichment on show, and the chunked upload from refusal to merge.
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
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\UnlockRequest;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The OpenRegister ObjectService surface DrcController calls, without a lock system.
 *
 * Parameter names match the real service: the controller passes named arguments.
 */
interface DrcCoverageObjectServiceStub {
	public function find(mixed $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed;

	public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array;

	public function searchObjectsPaginated(array $query): array;

	public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): mixed;

	public function deleteObject(ObjectDeletion $deletion): mixed;

	public function lockObject(LockRequest $request, bool $advisory = false): mixed;

	public function unlockObject(UnlockRequest $request): mixed;
}//end interface

/**
 * The same surface with OpenRegister's dedicated lock table.
 */
interface DrcCoverageLockInfoObjectServiceStub extends DrcCoverageObjectServiceStub {
	public function getLockInfo(string $uuid): array;
}//end interface

/**
 * A `php://` stream wrapper that serves a fixed request body for `php://input`.
 *
 * Registered only for the duration of one controller call, so the chunk
 * upload can read a raw body under the CLI SAPI.
 */
final class DrcCoverageInputStream {
	/**
	 * The body `php://input` returns.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * The stream context PHP assigns.
	 *
	 * @var mixed
	 */
	public $context;

	/**
	 * The read position.
	 *
	 * @var int
	 */
	private int $position = 0;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		return $path === 'php://input';
	}

	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}

	public function stream_stat(): array {
		return [];
	}
}//end class

/**
 * Coverage tests for DrcController.
 *
 * @covers \OCA\Dossiq\Controller\DrcController
 * @uses   \OCA\Dossiq\Controller\ZgwController
 * @uses   \OCA\Dossiq\Support\NormalisesObjectRows
 * @uses   \OCA\Dossiq\Controller\EioDocument
 * @uses   \OCA\Dossiq\Controller\EioRelationLookup
 * @uses   \OCA\Dossiq\Service\Zgw\ZgwSearchScope
 */
class DrcControllerCoverageTest extends TestCase {

	private const UUID = 'd0000000-1111-2222-3333-444455556666';

	private const GR_UUID = 'a0000000-1111-2222-3333-444455556666';

	private const MAPPING = ['sourceRegister' => '1', 'sourceSchema' => '15'];

	private const CHUNK = 10485760;

	/**
	 * The logger the ZGW service hands out.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * The document (file store) service.
	 *
	 * @var ZgwDocumentService&MockObject
	 */
	private $docs;

	/**
	 * The business rules service.
	 *
	 * @var ZgwBusinessRulesService&MockObject
	 */
	private $rules;

	/**
	 * Set up the shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->docs = $this->createMock(ZgwDocumentService::class);
		$this->rules = $this->createMock(ZgwBusinessRulesService::class);
	}//end setUp()

	/**
	 * A ZGW service with an authorised caller.
	 *
	 * @param object|null         $objects The object service, or null when OpenRegister is unwired.
	 * @param array|callable|null $mapping The mapping every resource loads, or a callback per (api, resource).
	 * @param bool                $scoped  Whether the consumer holds the scope asked for.
	 *
	 * @return ZgwService&MockObject
	 */
	private function zgw(?object $objects, array|callable|null $mapping = self::MAPPING, bool $scoped = true): ZgwService {
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturn(null);
		$zgw->method('consumerHasScope')->willReturn($scoped);
		$zgw->method('getLogger')->willReturn($this->logger);
		$zgw->method('getObjectService')->willReturn($objects);
		$zgw->method('getDocumentService')->willReturn($this->docs);
		$zgw->method('getBusinessRulesService')->willReturn($this->rules);
		if (is_callable($mapping) === true) {
			$zgw->method('loadMappingConfig')->willReturnCallback($mapping);
		} else {
			$zgw->method('loadMappingConfig')->willReturn($mapping);
		}

		return $zgw;
	}//end zgw()

	/**
	 * The controller over a ZGW service.
	 *
	 * @param ZgwService           $zgw    The ZGW service.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return DrcController
	 */
	private function controller(ZgwService $zgw, array $params = []): DrcController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new DrcController(appName: 'dossiq', request: $request, zgwService: $zgw, l10n: $l10n);
	}//end controller()

	/**
	 * An object service stub.
	 *
	 * @param bool $withLockInfo Whether it has OpenRegister's lock table.
	 *
	 * @return DrcCoverageObjectServiceStub&MockObject
	 */
	private function objects(bool $withLockInfo = false): DrcCoverageObjectServiceStub {
		if ($withLockInfo === true) {
			return $this->createMock(DrcCoverageLockInfoObjectServiceStub::class);
		}

		return $this->createMock(DrcCoverageObjectServiceStub::class);
	}//end objects()

	/**
	 * Run a call while `php://input` serves the given body.
	 *
	 * @param string   $body The raw request body.
	 * @param callable $call The call.
	 *
	 * @return mixed What the call returns.
	 */
	private function withInput(string $body, callable $call): mixed {
		DrcCoverageInputStream::$body = $body;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', DrcCoverageInputStream::class);
		try {
			return $call();
		} finally {
			stream_wrapper_restore('php');
		}
	}//end withInput()

	// ------------------------------------------------------------------
	// Scopes and generic resources
	// ------------------------------------------------------------------

	/**
	 * Create, update and destroy answer 403 naming their scope, and reach no handler.
	 *
	 * @return void
	 */
	public function testEveryWriteWithoutItsScopeIsRefused(): void {
		$zgw = $this->zgw(objects: $this->objects(), scoped: false);
		$zgw->expects($this->never())->method('handleCreate');
		$zgw->expects($this->never())->method('handleUpdate');
		$zgw->expects($this->never())->method('handleDestroy');
		$controller = $this->controller(zgw: $zgw);

		$cases = [
			'documenten.aanmaken' => $controller->create(resource: 'enkelvoudiginformatieobjecten'),
			'documenten.bijwerken' => $controller->update(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID),
			'documenten.verwijderen' => $controller->destroy(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID),
		];

		foreach ($cases as $scope => $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('Scope ' . $scope . ' is required for this operation.', $response->getData()['detail']);
		}
	}//end testEveryWriteWithoutItsScopeIsRefused()

	/**
	 * Other resources go to the generic create and full update unchanged.
	 *
	 * @return void
	 */
	public function testOtherResourcesUseTheGenericCreateAndUpdate(): void {
		$zgw = $this->zgw(objects: $this->objects());
		$created = new JSONResponse(data: ['url' => 'x'], statusCode: Http::STATUS_CREATED);
		$updated = new JSONResponse(data: ['url' => 'y']);
		$zgw->expects($this->once())->method('handleCreate')
			->with($this->isInstanceOf(IRequest::class), 'documenten', 'objectinformatieobjecten')
			->willReturn($created);
		$zgw->expects($this->once())->method('handleUpdate')
			->with($this->isInstanceOf(IRequest::class), 'documenten', 'verzendingen', self::UUID, false)
			->willReturn($updated);
		$controller = $this->controller(zgw: $zgw);

		$this->assertSame($created, $controller->create(resource: 'objectinformatieobjecten'));
		$this->assertSame($updated, $controller->update(resource: 'verzendingen', uuid: self::UUID));
	}//end testOtherResourcesUseTheGenericCreateAndUpdate()

	// ------------------------------------------------------------------
	// Gebruiksrechten: indicatieGebruiksrecht
	// ------------------------------------------------------------------

	/**
	 * A created gebruiksrecht sets indicatieGebruiksrecht on its document, saving the whole object.
	 *
	 * @return void
	 */
	public function testCreatingAGebruiksrechtMarksItsDocument(): void {
		$objects = $this->objects();
		$objects->expects($this->once())->method('find')
			->with(self::UUID, $this->callback(static fn (ObjectFindOptions $o): bool => $o->register === '1' && $o->schema === '15'))
			->willReturn(['id' => self::UUID, '@self' => ['id' => self::UUID], 'organisation' => 'org', 'title' => 'Besluit']);
		$objects->expects($this->once())->method('saveObject')
			->with(
				['title' => 'Besluit', 'usageRightsIndication' => true],
				$this->callback(static fn (ObjectSaveOptions $o): bool => $o->uuid === self::UUID && $o->target->schema === '15')
			)
			->willReturnArgument(0);
		$zgw = $this->zgw(objects: $objects);
		$created = new JSONResponse(
			data: ['informatieobject' => 'https://drc.example/enkelvoudiginformatieobjecten/' . self::UUID],
			statusCode: Http::STATUS_CREATED
		);
		$zgw->expects($this->once())->method('handleCreate')
			->with($this->isInstanceOf(IRequest::class), 'documenten', 'gebruiksrechten')
			->willReturn($created);

		$this->assertSame($created, $this->controller(zgw: $zgw)->create(resource: 'gebruiksrechten'));
	}//end testCreatingAGebruiksrechtMarksItsDocument()

	/**
	 * Data provider: created gebruiksrechten whose document cannot be marked.
	 *
	 * @return array<string, array{0: mixed, 1: bool, 2: bool}>
	 */
	public static function unmarkableGebruiksrechtProvider(): array {
		$url = 'https://drc.example/enkelvoudiginformatieobjecten/' . self::UUID;
		return [
			'non-array body' => ['created', true, true],
			'no informatieobject' => [['omschrijving' => 'x'], true, true],
			'url without a uuid' => [['informatieobject' => 'https://drc.example/eio/none'], true, true],
			'OpenRegister unwired' => [['informatieobject' => $url], false, true],
			'no EIO mapping' => [['informatieobject' => $url], true, false],
		];
	}//end unmarkableGebruiksrechtProvider()

	/**
	 * A gebruiksrecht create that cannot reach its document still answers the create, writing nothing.
	 *
	 * @param mixed $data        The created response body.
	 * @param bool  $wired       Whether OpenRegister is wired.
	 * @param bool  $withMapping Whether the EIO mapping exists.
	 *
	 * @return void
	 *
	 * @dataProvider unmarkableGebruiksrechtProvider
	 */
	public function testAGebruiksrechtCreateThatCannotMarkItsDocumentWritesNothing(mixed $data, bool $wired, bool $withMapping): void {
		$objects = $this->objects();
		$objects->expects($this->never())->method('find');
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: ($wired === true ? $objects : null), mapping: ($withMapping === true ? self::MAPPING : null));
		$created = new JSONResponse(data: $data, statusCode: Http::STATUS_CREATED);
		$zgw->method('handleCreate')->willReturn($created);

		$this->assertSame($created, $this->controller(zgw: $zgw)->create(resource: 'gebruiksrechten'));
	}//end testAGebruiksrechtCreateThatCannotMarkItsDocumentWritesNothing()

	/**
	 * A failed write of the indication is logged as a warning and the create still answers 201.
	 *
	 * @return void
	 */
	public function testAFailedIndicationWriteOnCreateIsAWarning(): void {
		$objects = $this->objects();
		$objects->method('find')->willThrowException(new RuntimeException('gone'));
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: $objects);
		$zgw->method('handleCreate')->willReturn(
			new JSONResponse(data: ['informatieobject' => 'https://drc.example/eio/' . self::UUID], statusCode: Http::STATUS_CREATED)
		);
		$this->logger->expects($this->once())->method('warning')->with('drc-006: Failed to set indicatieGebruiksrecht: gone');

		$response = $this->controller(zgw: $zgw)->create(resource: 'gebruiksrechten');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testAFailedIndicationWriteOnCreateIsAWarning()

	/**
	 * Data provider: gebruiksrecht deletes whose document cannot be resolved.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: mixed}>
	 */
	public static function unresolvableGebruiksrechtProvider(): array {
		return [
			'OpenRegister unwired' => [false, true, null],
			'no gebruiksrechten mapping' => [true, false, null],
			'gebruiksrecht not found' => [true, true, new RuntimeException('not found')],
			'document reference without uuid' => [true, true, ['document' => 'https://drc.example/eio/none']],
		];
	}//end unresolvableGebruiksrechtProvider()

	/**
	 * Deleting a gebruiksrecht whose document cannot be resolved deletes it and touches nothing else.
	 *
	 * @param bool  $wired       Whether OpenRegister is wired.
	 * @param bool  $withMapping Whether the gebruiksrechten mapping exists.
	 * @param mixed $found       What find() answers, or the exception it throws.
	 *
	 * @return void
	 *
	 * @dataProvider unresolvableGebruiksrechtProvider
	 */
	public function testDeletingAGebruiksrechtWithoutAResolvableDocumentOnlyDeletes(bool $wired, bool $withMapping, mixed $found): void {
		$objects = $this->objects();
		if ($found instanceof \Throwable) {
			$objects->method('find')->willThrowException($found);
		} else {
			$objects->method('find')->willReturn($found);
		}

		$objects->expects($this->never())->method('buildSearchQuery');
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: ($wired === true ? $objects : null), mapping: ($withMapping === true ? self::MAPPING : null));
		$deleted = new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		$zgw->expects($this->once())->method('handleDestroy')
			->with($this->isInstanceOf(IRequest::class), 'documenten', 'gebruiksrechten', self::GR_UUID)
			->willReturn($deleted);

		$this->assertSame($deleted, $this->controller(zgw: $zgw)->destroy(resource: 'gebruiksrechten', uuid: self::GR_UUID));
	}//end testDeletingAGebruiksrechtWithoutAResolvableDocumentOnlyDeletes()

	/**
	 * When OpenRegister or the mapping disappears between resolving and counting, nothing is cleared.
	 *
	 * @return void
	 */
	public function testTheIndicationCheckStopsWithoutObjectServiceOrMapping(): void {
		// OpenRegister answers the lookup, then is gone for the count.
		$objects = $this->objects();
		$objects->method('find')->willReturn(['document' => 'https://drc.example/eio/' . self::UUID]);
		$objects->expects($this->never())->method('buildSearchQuery');
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturn(null);
		$zgw->method('consumerHasScope')->willReturn(true);
		$zgw->method('getObjectService')->willReturnOnConsecutiveCalls($objects, null);
		$zgw->method('loadMappingConfig')->willReturn(self::MAPPING);
		$zgw->method('handleDestroy')->willReturn(new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT));
		$this->assertSame(Http::STATUS_NO_CONTENT, $this->controller(zgw: $zgw)->destroy(resource: 'gebruiksrechten', uuid: self::GR_UUID)->getStatus());

		// The gebruiksrechten mapping answers the lookup, then is gone for the count.
		$calls = 0;
		$zgw = $this->zgw(
			objects: $objects,
			mapping: static function () use (&$calls): ?array {
				$calls++;
				return $calls === 1 ? self::MAPPING : null;
			}
		);
		$zgw->method('handleDestroy')->willReturn(new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT));
		$this->assertSame(Http::STATUS_NO_CONTENT, $this->controller(zgw: $zgw)->destroy(resource: 'gebruiksrechten', uuid: self::GR_UUID)->getStatus());
		$this->assertSame(2, $calls);
	}//end testTheIndicationCheckStopsWithoutObjectServiceOrMapping()

	/**
	 * A failing count of the remaining gebruiksrechten is a warning and clears nothing.
	 *
	 * @return void
	 */
	public function testAFailingCountOfRemainingGebruiksrechtenIsAWarning(): void {
		$objects = $this->objects();
		$objects->method('find')->willReturn(['informatieobject' => 'https://drc.example/eio/' . self::UUID]);
		$objects->method('buildSearchQuery')->willThrowException(new RuntimeException('index down'));
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: $objects);
		$zgw->method('handleDestroy')->willReturn(new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT));
		$this->logger->expects($this->once())->method('warning')
			->with('drc-006: Failed to check remaining gebruiksrechten: index down');

		$response = $this->controller(zgw: $zgw)->destroy(resource: 'gebruiksrechten', uuid: self::GR_UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testAFailingCountOfRemainingGebruiksrechtenIsAWarning()

	/**
	 * When the last gebruiksrecht went but its document cannot be read, the failed clear is a warning.
	 *
	 * @return void
	 */
	public function testAFailedClearOfTheIndicationIsAWarning(): void {
		$objects = $this->objects();
		$objects->method('find')->willReturnCallback(
			static function (mixed $id): array {
				if ($id === self::GR_UUID) {
					return ['document' => 'https://drc.example/eio/' . self::UUID];
				}

				throw new RuntimeException('document unreadable');
			}
		);
		$objects->expects($this->once())->method('buildSearchQuery')
			->with(['document' => self::UUID, '_limit' => 1], 1, 15)
			->willReturn(['q' => 1]);
		$objects->method('searchObjectsPaginated')->willReturn(['total' => 0, 'results' => []]);
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: $objects);
		$zgw->method('handleDestroy')->willReturn(new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT));
		$this->logger->expects($this->once())->method('warning')
			->with('drc-006: Failed to clear indicatieGebruiksrecht: document unreadable');

		$response = $this->controller(zgw: $zgw)->destroy(resource: 'gebruiksrechten', uuid: self::GR_UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testAFailedClearOfTheIndicationIsAWarning()

	// ------------------------------------------------------------------
	// EIO delete
	// ------------------------------------------------------------------

	/**
	 * Without OpenRegister the EIO delete runs the generic delete and skips relations, cascade and files.
	 *
	 * @return void
	 */
	public function testDeletingAnEioWithoutOpenRegisterOnlyDeletes(): void {
		$zgw = $this->zgw(objects: null);
		$deleted = new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		$zgw->expects($this->once())->method('handleDestroy')
			->with($this->isInstanceOf(IRequest::class), 'documenten', 'enkelvoudiginformatieobjecten', self::UUID)
			->willReturn($deleted);
		$zgw->expects($this->never())->method('getDocumentService');

		$this->assertSame($deleted, $this->controller(zgw: $zgw)->destroy(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID));
	}//end testDeletingAnEioWithoutOpenRegisterOnlyDeletes()

	/**
	 * A failed file cleanup after a delete is a warning, and the delete still answers 204.
	 *
	 * @return void
	 */
	public function testAFailedFileCleanupAfterDeleteIsAWarning(): void {
		$objects = $this->objects();
		$objects->method('find')->willReturn(['id' => self::UUID]);
		$objects->expects($this->never())->method('buildSearchQuery');
		$zgw = $this->zgw(
			objects: $objects,
			mapping: static fn (string $api, string $resource): ?array => $resource === 'enkelvoudiginformatieobjecten' ? self::MAPPING : null
		);
		$zgw->method('handleDestroy')->willReturn(new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT));
		$failure = new RuntimeException('disk full');
		$this->docs->expects($this->once())->method('deleteFiles')->with(self::UUID)->willThrowException($failure);
		$this->logger->expects($this->once())->method('warning')
			->with('DRC file cleanup failed: disk full', ['exception' => $failure]);

		$response = $this->controller(zgw: $zgw)->destroy(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testAFailedFileCleanupAfterDeleteIsAWarning()

	/**
	 * A gebruiksrechten mapping with no searchable register leaves orphans, and says so.
	 *
	 * @return void
	 */
	public function testAnUnsearchableGebruiksrechtenMappingIsLoggedOnCascade(): void {
		$objects = $this->objects();
		$objects->method('find')->willThrowException(new RuntimeException('no file'));
		$objects->expects($this->never())->method('buildSearchQuery');
		$zgw = $this->zgw(
			objects: $objects,
			mapping: static fn (string $api, string $resource): ?array => match ($resource) {
				'enkelvoudiginformatieobjecten' => self::MAPPING,
				'gebruiksrechten' => ['sourceRegister' => 'documenten', 'sourceSchema' => ''],
				default => null,
			}
		);
		$zgw->method('handleDestroy')->willReturn(new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT));
		$this->docs->expects($this->never())->method('deleteFiles');
		$this->logger->expects($this->once())->method('error')->with(
			'drc-008: gebruiksrechten mapping has no searchable register/schema, so the gebruiksrechten of '
			. self::UUID . ' are being left behind as orphans'
		);

		$response = $this->controller(zgw: $zgw)->destroy(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testAnUnsearchableGebruiksrechtenMappingIsLoggedOnCascade()

	/**
	 * A failing cascade search is a warning, and the delete still answers 204.
	 *
	 * @return void
	 */
	public function testAFailingCascadeIsAWarning(): void {
		$objects = $this->objects();
		$objects->method('find')->willThrowException(new RuntimeException('no file'));
		$objects->expects($this->once())->method('buildSearchQuery')
			->with(['document' => '%' . self::UUID . '%', '_limit' => 100], 1, 15)
			->willThrowException(new RuntimeException('search down'));
		$objects->expects($this->never())->method('deleteObject');
		$zgw = $this->zgw(
			objects: $objects,
			mapping: static fn (string $api, string $resource): ?array => in_array($resource, ['enkelvoudiginformatieobjecten', 'gebruiksrechten'], true) ? self::MAPPING : null
		);
		$zgw->method('handleDestroy')->willReturn(new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT));
		$this->logger->expects($this->once())->method('warning')
			->with('drc-008: Failed to cascade delete gebruiksrechten for EIO ' . self::UUID . ': search down');

		$response = $this->controller(zgw: $zgw)->destroy(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testAFailingCascadeIsAWarning()

	// ------------------------------------------------------------------
	// EIO create
	// ------------------------------------------------------------------

	/**
	 * The EIO create answers the service's 503 without OpenRegister and its 404 without a mapping.
	 *
	 * @return void
	 */
	public function testEioCreateWithoutObjectServiceOrMapping(): void {
		$unavailable = new JSONResponse(data: ['detail' => 'unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		$zgw = $this->zgw(objects: null);
		$zgw->method('unavailableResponse')->willReturn($unavailable);
		$this->assertSame($unavailable, $this->controller(zgw: $zgw)->create(resource: 'enkelvoudiginformatieobjecten'));

		$notMapped = new JSONResponse(data: ['detail' => 'no mapping'], statusCode: Http::STATUS_NOT_FOUND);
		$zgw = $this->zgw(objects: $this->objects(), mapping: null);
		$zgw->expects($this->once())->method('mappingNotFoundResponse')
			->with('documenten', 'enkelvoudiginformatieobjecten')
			->willReturn($notMapped);
		$this->assertSame($notMapped, $this->controller(zgw: $zgw)->create(resource: 'enkelvoudiginformatieobjecten'));
	}//end testEioCreateWithoutObjectServiceOrMapping()

	/**
	 * A body that breaks the create rules answers the validation error with the rule's status, saving nothing.
	 *
	 * @return void
	 */
	public function testEioCreateRefusedByTheRulesAnswersTheValidationError(): void {
		$objects = $this->objects();
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: $objects);
		$zgw->method('getRequestBody')->willReturn(['titel' => '']);
		$ruleResult = ['valid' => false, 'status' => Http::STATUS_BAD_REQUEST, 'detail' => 'titel'];
		$this->rules->expects($this->once())->method('validate')
			->with('documenten', 'enkelvoudiginformatieobjecten', 'create', ['titel' => ''], null, $objects, self::MAPPING)
			->willReturn($ruleResult);
		$zgw->expects($this->once())->method('buildValidationError')->with($ruleResult)
			->willReturn(['invalidParams' => [['name' => 'titel']]]);

		$response = $this->controller(zgw: $zgw)->create(resource: 'enkelvoudiginformatieobjecten');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['invalidParams' => [['name' => 'titel']]], $response->getData());
	}//end testEioCreateRefusedByTheRulesAnswersTheValidationError()

	/**
	 * A failure while creating is logged and answered 400 with its message.
	 *
	 * @return void
	 */
	public function testEioCreateFailureIsALogged400(): void {
		$zgw = $this->zgw(objects: $this->objects());
		$failure = new RuntimeException('body unreadable');
		$zgw->method('getRequestBody')->willThrowException($failure);
		$this->logger->expects($this->once())->method('error')
			->with('DRC create error: body unreadable', ['exception' => $failure]);

		$response = $this->controller(zgw: $zgw)->create(resource: 'enkelvoudiginformatieobjecten');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'body unreadable'], $response->getData());
	}//end testEioCreateFailureIsALogged400()

	/**
	 * A document saved with an empty file name stores its inhoud as `document`.
	 *
	 * @return void
	 */
	public function testEioCreateStoresInhoudUnderTheDefaultNameWhenTheFileNameIsEmpty(): void {
		$objects = $this->objects();
		$objects->expects($this->once())->method('saveObject')
			->with(['title' => 'Brief'])
			->willReturn(['id' => self::UUID, 'fileName' => '', 'fileSize' => 5, 'fileId' => 7]);
		$zgw = $this->zgw(objects: $objects);
		$zgw->method('getRequestBody')->willReturn(['titel' => 'Brief', 'inhoud' => 'aGVsbG8=']);
		$this->rules->method('validate')->willReturn(['valid' => true, 'enrichedBody' => ['titel' => 'Brief', 'inhoud' => 'aGVsbG8=']]);
		$zgw->method('applyInboundMapping')->willReturn(['title' => 'Brief', 'content' => 'aGVsbG8=']);
		$zgw->method('buildBaseUrl')->willReturn('https://drc.example/eio');
		$zgw->method('applyOutboundMapping')->willReturn(['titel' => 'Brief']);
		$this->docs->expects($this->once())->method('storeBase64')->with(self::UUID, 'document', 'aGVsbG8=')->willReturn(5);
		$this->docs->expects($this->once())->method('getFileId')->with(self::UUID, 'document')->willReturn(7);
		$zgw->expects($this->once())->method('publishNotification')
			->with('documenten', 'enkelvoudiginformatieobjecten', 'https://drc.example/eio/' . self::UUID, 'create');

		$response = $this->controller(zgw: $zgw)->create(resource: 'enkelvoudiginformatieobjecten');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['titel' => 'Brief', 'bestandsdelen' => []], $response->getData());
	}//end testEioCreateStoresInhoudUnderTheDefaultNameWhenTheFileNameIsEmpty()

	// ------------------------------------------------------------------
	// Show: bestandsdelen enrichment
	// ------------------------------------------------------------------

	/**
	 * Data provider: stored fileParts and the bestandsdelen show adds.
	 *
	 * @return array<string, array{0: mixed, 1: list<array<string, mixed>>}>
	 */
	public static function filePartsProvider(): array {
		$base = 'https://drc.example/bestandsdelen/' . self::UUID . '?volgnummer=';
		$two = [
			['url' => $base . '1', 'sequenceNumber' => 1, 'size' => self::CHUNK, 'lock' => ''],
			['url' => $base . '2', 'sequenceNumber' => 2, 'size' => 100, 'lock' => ''],
		];
		return [
			'pending as JSON' => [json_encode(['pending' => true, 'totalParts' => 2, 'fileSize' => self::CHUNK + 100]), $two],
			'pending as array' => [['pending' => true, 'totalParts' => 2, 'fileSize' => self::CHUNK + 100], $two],
			'not pending' => [['pending' => false, 'totalParts' => 2], []],
			'unparseable JSON' => ['{not json', []],
			'neither string nor array' => [42, []],
		];
	}//end filePartsProvider()

	/**
	 * Show adds the bestandsdelen of a pending chunked upload, and an empty list otherwise.
	 *
	 * @param mixed                            $fileParts The stored fileParts.
	 * @param list<array<string, mixed>> $expected  The bestandsdelen.
	 *
	 * @return void
	 *
	 * @dataProvider filePartsProvider
	 */
	public function testShowAddsTheBestandsdelenOfAPendingUpload(mixed $fileParts, array $expected): void {
		$objects = $this->objects();
		$objects->expects($this->once())->method('find')
			->with(self::UUID, $this->isInstanceOf(ObjectFindOptions::class))
			->willReturn(['id' => self::UUID, 'fileParts' => $fileParts]);
		$zgw = $this->zgw(objects: $objects);
		$zgw->method('handleShow')->willReturn(new JSONResponse(data: ['titel' => 'Brief']));
		$zgw->method('buildBaseUrl')->with($this->anything(), 'documenten', 'bestandsdelen')->willReturn('https://drc.example/bestandsdelen');

		$response = $this->controller(zgw: $zgw)->show(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['titel' => 'Brief', 'bestandsdelen' => $expected], $response->getData());
	}//end testShowAddsTheBestandsdelenOfAPendingUpload()

	/**
	 * Without a mapping, or with a non-array body, show answers the response untouched.
	 *
	 * @return void
	 */
	public function testShowLeavesTheResponseWithoutMappingOrArrayBody(): void {
		$objects = $this->objects();
		$objects->expects($this->never())->method('find');
		$zgw = $this->zgw(objects: $objects, mapping: null);
		$zgw->method('handleShow')->willReturn(new JSONResponse(data: ['titel' => 'Brief']));
		$response = $this->controller(zgw: $zgw)->show(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID);
		$this->assertSame(['titel' => 'Brief'], $response->getData());

		$objects = $this->objects();
		$objects->expects($this->once())->method('find')->willReturn(['fileParts' => '']);
		$zgw = $this->zgw(objects: $objects);
		$zgw->method('handleShow')->willReturn(new JSONResponse(data: 'plain'));
		$response = $this->controller(zgw: $zgw)->show(resource: 'enkelvoudiginformatieobjecten', uuid: self::UUID);
		$this->assertSame('plain', $response->getData());
	}//end testShowLeavesTheResponseWithoutMappingOrArrayBody()

	// ------------------------------------------------------------------
	// Download
	// ------------------------------------------------------------------

	/**
	 * A stored file downloads with its name and format.
	 *
	 * @return void
	 */
	public function testDownloadStreamsTheStoredFile(): void {
		$objects = $this->objects();
		$objects->method('find')->willReturn(['id' => self::UUID, 'fileName' => 'brief.pdf', 'format' => 'application/pdf']);
		$zgw = $this->zgw(objects: $objects);
		$mappings = $this->createMock(ZgwMappingService::class);
		$mappings->method('getMapping')->with('enkelvoudiginformatieobject')->willReturn(self::MAPPING);
		$zgw->method('getZgwMappingService')->willReturn($mappings);
		$this->docs->method('fileExists')->with(self::UUID, 'brief.pdf')->willReturn(true);
		$this->docs->expects($this->once())->method('getContent')->with(self::UUID, 'brief.pdf')->willReturn('%PDF-1.7');

		$response = $this->controller(zgw: $zgw)->download(uuid: self::UUID);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('%PDF-1.7', $response->render());
		$this->assertSame('application/pdf', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('brief.pdf', $response->getHeaders()['Content-Disposition']);
	}//end testDownloadStreamsTheStoredFile()

	/**
	 * A failing lookup answers 404 Not found and is logged, never leaking the cause.
	 *
	 * @return void
	 */
	public function testAFailingDownloadIsALogged404(): void {
		$objects = $this->objects();
		$failure = new RuntimeException('db secret');
		$objects->method('find')->willThrowException($failure);
		$zgw = $this->zgw(objects: $objects);
		$mappings = $this->createMock(ZgwMappingService::class);
		$mappings->method('getMapping')->willReturn(self::MAPPING);
		$zgw->method('getZgwMappingService')->willReturn($mappings);
		$this->logger->expects($this->once())->method('error')
			->with('DRC download error: db secret', ['exception' => $failure]);

		$response = $this->controller(zgw: $zgw)->download(uuid: self::UUID);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['detail' => 'Not found'], $response->getData());
	}//end testAFailingDownloadIsALogged404()

	// ------------------------------------------------------------------
	// Lock
	// ------------------------------------------------------------------

	/**
	 * Without an EIO mapping a failing lock has no fallback and answers 400 with the cause.
	 *
	 * @return void
	 */
	public function testALockWithoutMappingThatFailsAnswersTheCause(): void {
		$objects = $this->objects();
		$objects->expects($this->never())->method('find');
		$objects->expects($this->once())->method('lockObject')
			->with($this->callback(static fn (LockRequest $r): bool => $r->identifier === self::UUID))
			->willThrowException(new RuntimeException('no user session'));
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: $objects, mapping: null);

		$response = $this->controller(zgw: $zgw)->lock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'no user session'], $response->getData());
	}//end testALockWithoutMappingThatFailsAnswersTheCause()

	/**
	 * An unreadable document counts as unlocked; a lockId that cannot be stored is a warning, and the lock still answers.
	 *
	 * @return void
	 */
	public function testALockIdThatCannotBeStoredIsAWarning(): void {
		$objects = $this->objects();
		$objects->expects($this->exactly(2))->method('find')->willThrowException(new RuntimeException('unreadable'));
		$objects->expects($this->once())->method('lockObject');
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: $objects);
		$this->logger->expects($this->once())->method('warning')
			->with('DRC: Failed to store lockId in data blob: unreadable');

		$response = $this->controller(zgw: $zgw)->lock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->getData()['lock']);
	}//end testALockIdThatCannotBeStoredIsAWarning()

	/**
	 * A lock table that throws falls back to the data blob.
	 *
	 * @return void
	 */
	public function testALockTableThatThrowsFallsBackToTheDataBlob(): void {
		$objects = $this->objects(withLockInfo: true);
		$objects->expects($this->once())->method('getLockInfo')->with(self::UUID)->willThrowException(new RuntimeException('no table'));
		$objects->method('find')->willReturn(['id' => self::UUID, 'lockId' => 'blob-lock']);
		$objects->expects($this->never())->method('lockObject');

		$response = $this->controller(zgw: $this->zgw(objects: $objects))->lock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'Document is already locked.'], $response->getData());
	}//end testALockTableThatThrowsFallsBackToTheDataBlob()

	// ------------------------------------------------------------------
	// Unlock
	// ------------------------------------------------------------------

	/**
	 * An unlock without a lockId and without the forcing scope is refused with its own sentence.
	 *
	 * @return void
	 */
	public function testAnUnlockWithoutLockIdOrForcingScopeIsRefused(): void {
		$objects = $this->objects(withLockInfo: true);
		$objects->method('getLockInfo')->willReturn(['lock_id' => 'L1']);
		$objects->expects($this->never())->method('unlockObject');
		$zgw = $this->zgw(objects: $objects, scoped: false);
		$zgw->method('getRequestBody')->willReturn([]);

		$response = $this->controller(zgw: $zgw)->unlock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$detail = 'Forced unlocking is not allowed without the correct scope.';
		$this->assertSame(
			['detail' => $detail, 'invalidParams' => [['name' => 'nonFieldErrors', 'code' => 'incorrect-lock-id', 'reason' => $detail]]],
			$response->getData()
		);
	}//end testAnUnlockWithoutLockIdOrForcingScopeIsRefused()

	/**
	 * A failing unlock whose mapping has gone answers 400 with the original cause.
	 *
	 * @return void
	 */
	public function testAFailingUnlockWithoutMappingForTheFallbackAnswersTheCause(): void {
		$objects = $this->objects(withLockInfo: true);
		$objects->method('getLockInfo')->willReturn(['lock_id' => 'L1']);
		$objects->expects($this->once())->method('unlockObject')
			->with($this->callback(static fn (UnlockRequest $r): bool => $r->identifier === self::UUID))
			->willThrowException(new RuntimeException('no user session'));
		$objects->expects($this->never())->method('saveObject');
		$calls = 0;
		$zgw = $this->zgw(
			objects: $objects,
			mapping: static function () use (&$calls): ?array {
				$calls++;
				return $calls === 1 ? self::MAPPING : null;
			}
		);
		$zgw->method('getRequestBody')->willReturn(['lock' => 'L1']);

		$response = $this->controller(zgw: $zgw)->unlock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'no user session'], $response->getData());
	}//end testAFailingUnlockWithoutMappingForTheFallbackAnswersTheCause()

	/**
	 * When the unlock fallback cannot read the document either, it is logged and answered 400.
	 *
	 * @return void
	 */
	public function testAFailingUnlockFallbackIsALogged400(): void {
		$objects = $this->objects(withLockInfo: true);
		$objects->method('getLockInfo')->willReturn(['lock_id' => 'L1']);
		$objects->method('unlockObject')->willThrowException(new RuntimeException('no user session'));
		$failure = new RuntimeException('document unreadable');
		$objects->method('find')->willThrowException($failure);
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: $objects);
		$zgw->method('getRequestBody')->willReturn(['lock' => 'L1']);
		$this->logger->expects($this->once())->method('error')
			->with('DRC unlock fallback error: document unreadable', ['exception' => $failure]);

		$response = $this->controller(zgw: $zgw)->unlock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'document unreadable'], $response->getData());
	}//end testAFailingUnlockFallbackIsALogged400()

	/**
	 * A lockId that cannot be cleared from the data blob is a warning, and the unlock still answers 204.
	 *
	 * @return void
	 */
	public function testALockIdThatCannotBeClearedIsAWarning(): void {
		$objects = $this->objects(withLockInfo: true);
		$objects->method('getLockInfo')->willReturn(['lock_id' => 'L1']);
		$objects->expects($this->once())->method('unlockObject');
		$objects->method('find')->willThrowException(new RuntimeException('document unreadable'));
		$objects->expects($this->never())->method('saveObject');
		$zgw = $this->zgw(objects: $objects);
		$zgw->method('getRequestBody')->willReturn(['lock' => 'L1']);
		$this->logger->expects($this->once())->method('warning')
			->with('DRC: Failed to clear lockId in data blob: document unreadable');

		$response = $this->controller(zgw: $zgw)->unlock(uuid: self::UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testALockIdThatCannotBeClearedIsAWarning()

	// ------------------------------------------------------------------
	// Chunked upload
	// ------------------------------------------------------------------

	/**
	 * A failing chunk upload is logged and answered 400 with its message.
	 *
	 * @return void
	 */
	public function testAFailingChunkUploadIsALogged400(): void {
		$objects = $this->objects();
		$failure = new RuntimeException('document gone');
		$objects->method('find')->willThrowException($failure);
		$this->logger->expects($this->once())->method('error')
			->with('DRC chunk upload error: document gone', ['exception' => $failure]);

		$response = $this->controller(zgw: $this->zgw(objects: $objects))->uploadChunk(uuid: self::UUID);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'document gone'], $response->getData());
	}//end testAFailingChunkUploadIsALogged400()

	/**
	 * A pending upload of zero parts is an invalid chunk configuration.
	 *
	 * @return void
	 */
	public function testAPendingUploadOfZeroPartsIsRefused(): void {
		$objects = $this->objects();
		$objects->method('find')->willReturn(['fileParts' => json_encode(['pending' => true, 'totalParts' => 0])]);
		$this->docs->expects($this->never())->method('storeChunk');

		$response = $this->controller(zgw: $this->zgw(objects: $objects), params: ['sequenceNumber' => '1'])->uploadChunk(uuid: self::UUID);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'Invalid chunk configuration.'], $response->getData());
	}//end testAPendingUploadOfZeroPartsIsRefused()

	/**
	 * A chunk that is not the last is stored and answers its progress, merging nothing.
	 *
	 * @return void
	 */
	public function testAChunkThatIsNotTheLastAnswersItsProgress(): void {
		$objects = $this->objects();
		$objects->method('find')->willReturn(['id' => self::UUID, 'fileParts' => ['pending' => true, 'totalParts' => 3]]);
		$objects->expects($this->never())->method('saveObject');
		$this->docs->expects($this->once())->method('storeChunk')->with(self::UUID, 2, 'chunk-two')->willReturn(9);
		$this->docs->expects($this->once())->method('getUploadedChunks')->with(self::UUID, 3)->willReturn([1, 2]);
		$this->docs->expects($this->never())->method('mergeChunks');
		$controller = $this->controller(zgw: $this->zgw(objects: $objects), params: ['sequenceNumber' => '2']);

		$response = $this->withInput('chunk-two', static fn () => $controller->uploadChunk(uuid: self::UUID));

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['sequenceNumber' => 2, 'size' => 9, 'uploadComplete' => false, 'uploadedParts' => 2, 'totalParts' => 3],
			$response->getData()
		);
	}//end testAChunkThatIsNotTheLastAnswersItsProgress()

	/**
	 * The last chunk merges the file and saves the whole object with its size and file id, chunk metadata cleared.
	 *
	 * @return void
	 */
	public function testTheLastChunkMergesTheFileAndStampsTheDocument(): void {
		$objects = $this->objects();
		$objects->method('find')->willReturn(
			['id' => self::UUID, '@self' => ['id' => self::UUID], 'organisation' => 'org', 'title' => 'Groot', 'fileName' => 'groot.bin', 'fileParts' => ['pending' => true, 'totalParts' => 2]]
		);
		$objects->expects($this->once())->method('saveObject')
			->with(
				['title' => 'Groot', 'fileName' => 'groot.bin', 'fileParts' => '', 'fileSize' => 123, 'fileId' => 55],
				$this->callback(static fn (ObjectSaveOptions $o): bool => $o->uuid === self::UUID && $o->target->register === '1')
			)
			->willReturnArgument(0);
		$this->docs->method('storeChunk')->willReturn(23);
		$this->docs->method('getUploadedChunks')->willReturn([1, 2]);
		$this->docs->expects($this->once())->method('mergeChunks')->with(self::UUID, 'groot.bin', 2)->willReturn(123);
		$this->docs->expects($this->once())->method('getFileId')->with(self::UUID, 'groot.bin')->willReturn(55);
		$controller = $this->controller(zgw: $this->zgw(objects: $objects), params: ['sequenceNumber' => '2']);

		$response = $this->withInput('last', static fn () => $controller->uploadChunk(uuid: self::UUID));

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['sequenceNumber' => 2, 'size' => 23, 'uploadComplete' => true, 'bestandsomvang' => 123, 'uploadedParts' => 2, 'totalParts' => 2],
			$response->getData()
		);
	}//end testTheLastChunkMergesTheFileAndStampsTheDocument()
}//end class
