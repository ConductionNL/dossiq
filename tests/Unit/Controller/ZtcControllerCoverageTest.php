<?php

/**
 * The catalogue list, create, parent-draft and audit-trail paths of ZtcController.
 *
 * Covers what the write-gate golden leaves out: a list answer passed through
 * when it is not a page, a page filtered by datumGeldigheid and stripped of
 * references to unpublished types, a zaaktype-informatieobjecttype created by
 * omschrijving, a created zaaktype re-answered with its cross-references,
 * the parent zaaktype's draft state read from the stored object on update,
 * and the audit-trail endpoints behind the JWT check.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ZtcController;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue endpoints' list, create, parent-draft and audit-trail answers.
 *
 * @covers \OCA\Dossiq\Controller\ZtcController
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 * @uses \OCA\Dossiq\Controller\ZgwController
 * @uses \OCA\Dossiq\Service\Zgw\ValidityDateFilter
 * @uses \OCA\Dossiq\Service\Zgw\ZtcHelperFactory
 * @uses \OCA\Dossiq\Service\Zgw\ZtcTypeNameResolver
 * @uses \OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher
 * @uses \OCA\Dossiq\Service\Zgw\ZtcRelatedTypeLookup
 * @uses \OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter
 * @uses \OCA\Dossiq\Service\Zgw\ZtcStoredUuidUrls
 */
class ZtcControllerCoverageTest extends TestCase {
	/**
	 * A besluittype uuid the object store holds as a concept.
	 *
	 * @var string
	 */
	private const DRAFT_BT = '11111111-2222-3333-4444-555555555555';

	/**
	 * The request every call carries.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The ZGW service double.
	 *
	 * @var ZgwServiceInterface&MockObject
	 */
	private ZgwServiceInterface $zgw;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('nc.example');
		$this->zgw = $this->createMock(originalClassName: ZgwServiceInterface::class);
	}//end setUp()

	/**
	 * A JWT refusal answers the list, before the store is asked.
	 *
	 * @return void
	 */
	public function testIndexAnswersTheJwtRefusal(): void {
		$refusal = new JSONResponse(data: ['detail' => 'no token'], statusCode: 403);
		$this->zgw->method('validateJwtAuth')->with($this->request)->willReturn($refusal);
		$this->zgw->expects(self::never())->method('handleIndex');

		self::assertSame($refusal, $this->controller()->index(resource: 'zaaktypen'));
	}//end testIndexAnswersTheJwtRefusal()

	/**
	 * A failed list, and a list answer that is not a page, pass through unchanged.
	 *
	 * @return void
	 */
	public function testIndexPassesAFailureAndANonPageThrough(): void {
		$failed = new JSONResponse(data: ['detail' => 'boom'], statusCode: 500);
		$noResults = new JSONResponse(data: ['count' => 0], statusCode: 200);
		$scalarResults = new JSONResponse(data: ['results' => 'x'], statusCode: 200);
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->expects(self::exactly(3))->method('handleIndex')
			->with($this->request, 'catalogi', 'statustypen')
			->willReturnOnConsecutiveCalls($failed, $noResults, $scalarResults);

		$controller = $this->controller();
		self::assertSame($failed, $controller->index(resource: 'statustypen'));
		self::assertSame($noResults, $controller->index(resource: 'statustypen'));
		self::assertSame($scalarResults, $controller->index(resource: 'statustypen'));
	}//end testIndexPassesAFailureAndANonPageThrough()

	/**
	 * With datumGeldigheid only the results valid on that date stay, and the count follows.
	 *
	 * @return void
	 */
	public function testIndexFiltersByValidityDate(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key): ?string => $key === 'datumGeldigheid' ? '2024-06-01' : null
		);
		$valid = ['url' => 'a', 'startValidity' => '2024-01-01', 'endValidity' => '2024-12-31'];
		$notYet = ['url' => 'b', 'startValidity' => '2024-07-01'];
		$ended = ['url' => 'c', 'startValidity' => '2023-01-01', 'endValidity' => '2024-05-31'];
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->method('handleIndex')->willReturn(
			new JSONResponse(data: ['count' => 3, 'next' => null, 'results' => [$valid, $notYet, $ended]], statusCode: 200)
		);

		$response = $this->controller()->index(resource: 'statustypen');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['count' => 1, 'next' => null, 'results' => [$valid]], $response->getData());
	}//end testIndexFiltersByValidityDate()

	/**
	 * A zaaktypen page drops references to a besluittype that is still a concept; no date keeps every result.
	 *
	 * @return void
	 */
	public function testIndexDropsReferencesToUnpublishedTypes(): void {
		$this->request->method('getParam')->willReturn('');
		$store = new ZtcCoverageObjectServiceStub(rows: [self::DRAFT_BT => ['isDraft' => true]]);
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->method('getObjectService')->willReturn($store);
		$this->zgw->method('loadMappingConfig')->willReturn(['sourceRegister' => 'r-1', 'sourceSchema' => 's-bt']);
		$item = ['url' => 'zt', 'besluittypen' => ['https://nc.example/besluittypen/' . self::DRAFT_BT, 'not-a-url']];
		$this->zgw->method('handleIndex')->willReturn(
			new JSONResponse(data: ['count' => 2, 'results' => [$item, ['url' => 'zt-2']]], statusCode: 200)
		);

		$response = $this->controller()->index(resource: 'zaaktypen');

		self::assertSame(200, $response->getStatus());
		self::assertSame(
			['count' => 2, 'results' => [['url' => 'zt', 'besluittypen' => []], ['url' => 'zt-2']]],
			$response->getData()
		);
		self::assertSame([[self::DRAFT_BT, 'r-1', 's-bt']], $store->finds);
	}//end testIndexDropsReferencesToUnpublishedTypes()

	/**
	 * A zaaktype-informatieobjecttype names its informatieobjecttype by omschrijving; the uuid is written back
	 * into the body before the create reaches the store with the parent zaaktype's draft state.
	 *
	 * @return void
	 */
	public function testCreatingAZiotResolvesTheInformatieobjecttypeByName(): void {
		$this->allow();
		$body = ['zaaktype' => 'zt-url', 'informatieobjecttype' => 'Bijlage'];
		$store = new ZtcCoverageObjectServiceStub(rows: [], search: ['total' => 1, 'results' => [['id' => 'iot-uuid']]]);
		$this->zgw->method('getRequestBody')->with($this->request)->willReturn($body);
		$this->zgw->expects(self::once())->method('resolveParentZaaktypeDraftFromBody')
			->with('zaaktype-informatieobjecttypen', $body)->willReturn(true);
		$this->zgw->method('getObjectService')->willReturn($store);
		$this->zgw->method('loadMappingConfig')->with('catalogi', 'informatieobjecttypen')
			->willReturn(['sourceRegister' => 'r-1', 'sourceSchema' => 's-iot']);
		$this->zgw->expects(self::once())->method('updateCachedBodyField')->with('informatieobjecttype', 'iot-uuid');
		$answer = new JSONResponse(data: ['url' => 'ziot'], statusCode: 201);
		$this->zgw->expects(self::once())->method('handleCreate')
			->with($this->request, 'catalogi', 'zaaktype-informatieobjecttypen', null, true, true)
			->willReturn($answer);

		self::assertSame($answer, $this->controller()->create(resource: 'zaaktype-informatieobjecttypen'));
		self::assertSame([['name' => 'Bijlage', '_limit' => 1]], $store->searches);
	}//end testCreatingAZiotResolvesTheInformatieobjecttypeByName()

	/**
	 * A created zaaktype is answered anew with 201; a refused one passes through.
	 *
	 * @return void
	 */
	public function testACreatedZaaktypeIsReAnsweredAndARefusalPassesThrough(): void {
		$this->allow();
		$this->zgw->method('getRequestBody')->willReturn(['omschrijving' => 'A']);
		$this->zgw->method('resolveParentZaaktypeDraftFromBody')->willReturn(null);
		$this->zgw->expects(self::never())->method('updateCachedBodyField');
		$created = new JSONResponse(data: ['url' => 'zt', 'omschrijving' => 'A'], statusCode: 201);
		$refused = new JSONResponse(data: ['detail' => 'invalid'], statusCode: 400);
		$this->zgw->method('handleCreate')->willReturnOnConsecutiveCalls($created, $refused);

		$controller = $this->controller();
		$response = $controller->create(resource: 'zaaktypen');

		self::assertNotSame($created, $response);
		self::assertSame([201, ['url' => 'zt', 'omschrijving' => 'A']], [$response->getStatus(), $response->getData()]);
		self::assertSame($refused, $controller->create(resource: 'zaaktypen'));
	}//end testACreatedZaaktypeIsReAnsweredAndARefusalPassesThrough()

	/**
	 * An update reads the stored object and hands the parent zaaktype's draft state to the store.
	 *
	 * @return void
	 */
	public function testAnUpdateReadsTheParentDraftFromTheStoredObject(): void {
		$this->allow();
		$store = new ZtcCoverageObjectServiceStub(rows: ['st-1' => ['caseType' => 'zt-1']]);
		$this->zgw->method('getObjectService')->willReturn($store);
		$this->zgw->method('loadMappingConfig')->with('catalogi', 'statustypen')
			->willReturn(['sourceRegister' => 'r-1', 'sourceSchema' => 's-st']);
		$this->zgw->expects(self::once())->method('resolveParentZaaktypeDraft')
			->with('statustypen', ['caseType' => 'zt-1'])->willReturn(false);
		$answer = new JSONResponse(data: ['url' => 'st'], statusCode: 200);
		$this->zgw->expects(self::once())->method('handleUpdate')
			->with($this->request, 'catalogi', 'statustypen', 'st-1', true, false)->willReturn($answer);

		self::assertSame($answer, $this->controller()->patch(resource: 'statustypen', uuid: 'st-1'));
		self::assertSame([['st-1', 'r-1', 's-st']], $store->finds);
	}//end testAnUpdateReadsTheParentDraftFromTheStoredObject()

	/**
	 * A stored object that cannot be read leaves the parent draft state unknown.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoredObjectLeavesTheParentDraftUnknown(): void {
		$this->allow();
		$this->zgw->method('getObjectService')->willReturn(new ZtcCoverageObjectServiceStub(rows: []));
		$this->zgw->method('loadMappingConfig')->willReturn(['sourceRegister' => 'r-1', 'sourceSchema' => 's-st']);
		$this->zgw->expects(self::never())->method('resolveParentZaaktypeDraft');
		$answer = new JSONResponse(data: [], statusCode: 204);
		$this->zgw->expects(self::once())->method('handleDestroy')
			->with($this->request, 'catalogi', 'statustypen', 'gone', null)->willReturn($answer);

		self::assertSame($answer, $this->controller()->destroy(resource: 'statustypen', uuid: 'gone'));
	}//end testAnUnreadableStoredObjectLeavesTheParentDraftUnknown()

	/**
	 * The audit-trail endpoints reach the store with the catalogi register behind a valid token.
	 *
	 * @return void
	 */
	public function testTheAuditTrailReachesTheStore(): void {
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$list = new JSONResponse(data: [['uuid' => 'a-1']], statusCode: 200);
		$entry = new JSONResponse(data: ['uuid' => 'a-1'], statusCode: 200);
		$this->zgw->expects(self::once())->method('handleAudittrailIndex')
			->with($this->request, 'catalogi', 'zaaktypen', 'zt-1')->willReturn($list);
		$this->zgw->expects(self::once())->method('handleAudittrailShow')
			->with($this->request, 'catalogi', 'zaaktypen', 'zt-1', 'a-1')->willReturn($entry);

		$controller = $this->controller();
		self::assertSame($list, $controller->audittrailIndex(resource: 'zaaktypen', uuid: 'zt-1'));
		self::assertSame($entry, $controller->audittrailShow(resource: 'zaaktypen', uuid: 'zt-1', auditUuid: 'a-1'));
	}//end testTheAuditTrailReachesTheStore()

	/**
	 * Without a valid token the audit trail answers the refusal and reads nothing.
	 *
	 * @return void
	 */
	public function testTheAuditTrailAnswersTheJwtRefusal(): void {
		$refusal = new JSONResponse(data: ['detail' => 'no token'], statusCode: 403);
		$this->zgw->method('validateJwtAuth')->willReturn($refusal);
		$this->zgw->expects(self::never())->method('handleAudittrailIndex');
		$this->zgw->expects(self::never())->method('handleAudittrailShow');

		$controller = $this->controller();
		self::assertSame($refusal, $controller->audittrailIndex(resource: 'zaaktypen', uuid: 'zt-1'));
		self::assertSame($refusal, $controller->audittrailShow(resource: 'zaaktypen', uuid: 'zt-1', auditUuid: 'a-1'));
	}//end testTheAuditTrailAnswersTheJwtRefusal()

	/**
	 * A consumer that passes the JWT and holds the write scope.
	 *
	 * @return void
	 */
	private function allow(): void {
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->method('consumerHasScope')->willReturn(true);
	}//end allow()

	/**
	 * The controller over the doubles.
	 *
	 * @return ZtcController The controller.
	 */
	private function controller(): ZtcController {
		return new ZtcController(appName: 'dossiq', request: $this->request, zgwService: $this->zgw);
	}//end controller()
}//end class

/**
 * An OpenRegister ObjectService boundary double: reads rows by id and answers searches.
 */
class ZtcCoverageObjectServiceStub {
	/**
	 * Every find, as [id, register, schema].
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: mixed}>
	 */
	public array $finds = [];

	/**
	 * Every search's request parameters.
	 *
	 * @var array<int, array>
	 */
	public array $searches = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array> $rows   The stored rows by id; any other id is not found.
	 * @param array                $search The answer to every search.
	 */
	public function __construct(
		private readonly array $rows,
		private readonly array $search = ['total' => 0, 'results' => []],
	) {
	}//end __construct()

	/**
	 * Read one row.
	 *
	 * @param string $id      The id.
	 * @param object $options The find options.
	 *
	 * @return array The row.
	 *
	 * @throws \RuntimeException When the row does not exist.
	 */
	public function find(string $id, object $options): array {
		$this->finds[] = [$id, $options->register, $options->schema];
		if (isset($this->rows[$id]) === false) {
			throw new \RuntimeException('Not found');
		}

		return $this->rows[$id];
	}//end find()

	/**
	 * Build a search query.
	 *
	 * @param array $requestParams The parameters.
	 * @param mixed $register      The register.
	 * @param mixed $schema        The schema.
	 *
	 * @return array The query.
	 */
	public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array {
		$this->searches[] = $requestParams;
		return $requestParams + ['register' => $register, 'schema' => $schema];
	}//end buildSearchQuery()

	/**
	 * Run a search.
	 *
	 * @param array $query         The query.
	 * @param bool  $_rbac         Whether RBAC applies.
	 * @param bool  $_multitenancy Whether multitenancy applies.
	 *
	 * @return array The page.
	 */
	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		return $this->search;
	}//end searchObjectsPaginated()
}//end class
