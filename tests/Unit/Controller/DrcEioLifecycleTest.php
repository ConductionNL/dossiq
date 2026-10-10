<?php

/**
 * Characterisation tests for the enkelvoudiginformatieobject paths of DrcController.
 *
 * Pins the delete (relation refusal, cascade, file cleanup), the update (lock kept) and the
 * chunked create (fileParts marker and bestandsdelen) through the public controller methods,
 * so their decomposition (method-decomposition) can be proven to change no outcome.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DrcController;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ObjectService shape the EIO paths call.
 */
interface DrcLifecycleObjectServiceStub {
	public function find(mixed $id, mixed $register, mixed $schema): mixed;

	public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array;

	public function searchObjectsPaginated(array $query): array;

	public function deleteObject(string $uuid): mixed;

	public function saveObject(mixed $register, mixed $schema, array $object, ?string $uuid=null): mixed;
}//end interface

/**
 * Characterisation tests for the EIO delete, update and chunked create.
 *
 * @covers \OCA\Dossiq\Controller\DrcController
 */
class DrcEioLifecycleTest extends TestCase {

	private const UUID = 'd0000000-1111-2222-3333-444455556666';

	/**
	 * Schema id per resource; register is always '1'.
	 */
	private const SCHEMAS = [
		'enkelvoudiginformatieobjecten' => '11',
		'zaakinformatieobjecten'        => '12',
		'objectinformatieobjecten'      => '13',
		'besluitinformatieobjecten'     => '14',
		'gebruiksrechten'               => '15',
	];

	/**
	 * Calls observed: deleted objects, saved objects, deleteFiles, handleDestroy.
	 *
	 * @var array<string, array>
	 */
	private array $seen = ['deleted' => [], 'saved' => [], 'files' => [], 'destroyed' => []];

	/**
	 * Build the controller over a ZgwService mock and an ObjectService stub.
	 *
	 * @param mixed $stored   The stored EIO find() returns, or a Throwable to throw
	 * @param array $searches Results by schema id
	 * @param array $body     The request body
	 * @param int   $destroyStatus The status handleDestroy answers
	 *
	 * @return DrcController
	 */
	private function controller(mixed $stored, array $searches=[], array $body=[], int $destroyStatus=Http::STATUS_NO_CONTENT): DrcController {
		$objects = $this->createMock(DrcLifecycleObjectServiceStub::class);
		$objects->method('find')->willReturnCallback(
			static function (mixed $id, mixed $register, mixed $schema) use ($stored): mixed {
				if ($stored instanceof \Throwable) {
					throw $stored;
				}

				return $stored;
			}
		);
		$objects->method('buildSearchQuery')->willReturnCallback(
			static fn (array $requestParams, mixed $register, mixed $schema): array => ['schema' => (string)$schema, 'params' => $requestParams]
		);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			static fn (array $query): array => ['results' => $searches[$query['schema']] ?? []]
		);
		$objects->method('deleteObject')->willReturnCallback(function (string $uuid): bool {
			$this->seen['deleted'][] = $uuid;
			return true;
		});
		$objects->method('saveObject')->willReturnCallback(
			function (mixed $register, mixed $schema, array $object, ?string $uuid=null): array {
				$this->seen['saved'][] = $object;
				return array_merge(['id' => self::UUID], $object);
			}
		);

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		$zgw->method('validateJwtAuth')->willReturn(null);
		$zgw->method('consumerHasScope')->willReturn(true);
		$zgw->method('getObjectService')->willReturn($objects);
		$zgw->method('loadMappingConfig')->willReturnCallback(
			static fn (string $api, string $resource): ?array => isset(self::SCHEMAS[$resource]) === true
				? ['sourceRegister' => '1', 'sourceSchema' => self::SCHEMAS[$resource]]
				: null
		);
		$zgw->method('handleDestroy')->willReturnCallback(
			function ($request, string $api, string $resource, string $uuid) use ($destroyStatus): JSONResponse {
				$this->seen['destroyed'][] = $resource.':'.$uuid;
				return new JSONResponse(data: [], statusCode: $destroyStatus);
			}
		);
		$zgw->method('getRequestBody')->willReturn($body);
		$rules = $this->createMock(ZgwBusinessRulesService::class);
		$rules->method('validate')->willReturn(['valid' => true, 'enrichedBody' => $body]);
		$zgw->method('getBusinessRulesService')->willReturn($rules);
		$zgw->method('createInboundMapping')->willReturn(new \stdClass());
		$zgw->method('applyInboundMapping')->willReturn(['title' => 'Brief', 'content' => 'raw']);
		$zgw->method('buildBaseUrl')->willReturn('http://localhost/drc/enkelvoudiginformatieobjecten');
		$zgw->method('createOutboundMapping')->willReturn(new \stdClass());
		$zgw->method('applyOutboundMapping')->willReturnCallback(
			static fn (array $objectData, object $mapping, array $mappingConfig, string $baseUrl): array => ['titel' => $objectData['title'] ?? '']
		);
		$documents = $this->createMock(ZgwDocumentService::class);
		$documents->method('deleteFiles')->willReturnCallback(function (string $uuid): void {
			$this->seen['files'][] = $uuid;
		});
		$zgw->method('getDocumentService')->willReturn($documents);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DrcController(appName: 'dossiq', request: $this->createMock(IRequest::class), zgwService: $zgw, l10n: $l10n);

	}//end controller()

	/**
	 * A document a zaak still points at is not deleted.
	 *
	 * @return void
	 */
	public function testDocumentWithARelationIsNotDeleted(): void {
		$response = $this->controller(stored: ['id' => self::UUID], searches: ['12' => [['id' => 'zio-1']]])
			->destroy('enkelvoudiginformatieobjecten', self::UUID);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('pending-relations', $response->getData()['invalidParams'][0]['code']);
		$this->assertSame([], $this->seen['destroyed']);
		$this->assertSame([], $this->seen['files']);

	}//end testDocumentWithARelationIsNotDeleted()

	/**
	 * A deleted document takes its gebruiksrechten and its stored files with it.
	 *
	 * @return void
	 */
	public function testDeletedDocumentCascades(): void {
		$response = $this->controller(stored: ['id' => self::UUID], searches: ['15' => [['id' => 'gr-1'], ['@self' => ['id' => 'gr-2']]]])
			->destroy('enkelvoudiginformatieobjecten', self::UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame(['enkelvoudiginformatieobjecten:'.self::UUID], $this->seen['destroyed']);
		$this->assertSame(['gr-1', 'gr-2'], $this->seen['deleted']);
		$this->assertSame([self::UUID], $this->seen['files']);

	}//end testDeletedDocumentCascades()

	/**
	 * A document that could not be read before the delete keeps its files; a refused delete cascades nothing.
	 *
	 * @return void
	 */
	public function testUnreadableOrRefusedDeleteCleansNothing(): void {
		$this->controller(stored: new \RuntimeException('gone'), searches: ['15' => [['id' => 'gr-1']]])
			->destroy('enkelvoudiginformatieobjecten', self::UUID);
		$this->assertSame([], $this->seen['files']);
		$this->assertSame(['gr-1'], $this->seen['deleted'], 'the gebruiksrechten still go');

		$this->seen = ['deleted' => [], 'saved' => [], 'files' => [], 'destroyed' => []];
		$this->controller(stored: ['id' => self::UUID], searches: ['15' => [['id' => 'gr-1']]], destroyStatus: Http::STATUS_NOT_FOUND)
			->destroy('enkelvoudiginformatieobjecten', self::UUID);
		$this->assertSame([], $this->seen['deleted']);
		$this->assertSame([], $this->seen['files']);

	}//end testUnreadableOrRefusedDeleteCleansNothing()

	/**
	 * Another DRC resource goes straight to the generic delete.
	 *
	 * @return void
	 */
	public function testOtherResourceUsesTheGenericDelete(): void {
		$this->controller(stored: ['id' => self::UUID])->destroy('verzendingen', self::UUID);

		$this->assertSame(['verzendingen:'.self::UUID], $this->seen['destroyed']);

	}//end testOtherResourceUsesTheGenericDelete()

	/**
	 * An update with the right lock id keeps the stored lock on the saved document.
	 *
	 * @return void
	 */
	public function testUpdateKeepsTheLock(): void {
		$response = $this->controller(
			stored: ['id' => self::UUID, 'lockId' => 'L1', 'locked' => true],
			body: ['lock' => 'L1', 'titel' => 'Brief']
		)->update('enkelvoudiginformatieobjecten', self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['titel' => 'Brief'], $response->getData());
		$this->assertTrue($this->seen['saved'][0]['locked']);
		$this->assertSame('L1', $this->seen['saved'][0]['lockId']);
		$this->assertSame('raw', $this->seen['saved'][0]['content'], 'without inhoud the content travels in the object');

	}//end testUpdateKeepsTheLock()

	/**
	 * An update of an unlocked document, or with the wrong lock id, is refused.
	 *
	 * @return void
	 */
	public function testUpdateNeedsTheLock(): void {
		$unlocked = $this->controller(stored: ['id' => self::UUID], body: ['lock' => 'L1'])
			->update('enkelvoudiginformatieobjecten', self::UUID);
		$wrong = $this->controller(stored: ['id' => self::UUID, 'lockId' => 'L1'], body: ['lock' => 'L2'])
			->update('enkelvoudiginformatieobjecten', self::UUID);
		$flag = $this->controller(stored: ['id' => self::UUID, 'locked' => 'true'], body: ['lock' => 'entity-lock'])
			->update('enkelvoudiginformatieobjecten', self::UUID);

		$this->assertSame('unlocked', $unlocked->getData()['invalidParams'][0]['code']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $wrong->getStatus());
		$this->assertSame(Http::STATUS_OK, $flag->getStatus(), 'a locked flag reads as the entity-lock id');
		$this->assertSame([], array_filter($this->seen['saved'], static fn (array $o): bool => ($o['lockId'] ?? '') === 'L2'));

	}//end testUpdateNeedsTheLock()

	/**
	 * A create with bestandsomvang and no inhoud opens a chunked upload and answers its parts.
	 *
	 * @return void
	 */
	public function testChunkedCreateOpensTheUpload(): void {
		$response = $this->controller(stored: [], body: ['bestandsomvang' => (2 * 10485760) + 1])
			->create('enkelvoudiginformatieobjecten');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$marker = json_decode($this->seen['saved'][0]['fileParts'], true);
		$this->assertSame(['pending' => true, 'totalParts' => 3, 'chunkSize' => 10485760, 'fileSize' => 20971521], $marker);
		$this->assertCount(3, $response->getData()['bestandsdelen']);
		$this->assertSame('raw', $this->seen['saved'][0]['content']);

	}//end testChunkedCreateOpensTheUpload()

	/**
	 * A plain create without a size opens no upload and answers no parts.
	 *
	 * @return void
	 */
	public function testPlainCreateHasNoParts(): void {
		$response = $this->controller(stored: [], body: [])->create('enkelvoudiginformatieobjecten');

		$this->assertArrayNotHasKey('fileParts', $this->seen['saved'][0]);
		$this->assertSame([], $response->getData()['bestandsdelen']);

	}//end testPlainCreateHasNoParts()
}//end class
