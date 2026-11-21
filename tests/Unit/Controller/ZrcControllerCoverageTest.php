<?php

/**
 * ZrcController Branch Coverage Tests
 *
 * Pins the ZrcController paths the other Zrc suites leave open: a create with
 * no mapping or with a failing body, the forced-update scope lookup on a
 * closed zaak, the productenOfDiensten pre-validation against the zaaktype
 * (zrc-015), the ZaakInformatieObject response enrichment (zrc-004a), and the
 * ObjectInformatieObject create/delete sync with the DRC (zrc-005a/b, zrc-023).
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

use OCA\Dossiq\Controller\ZrcController;
use OCA\Dossiq\Kernel\Contracts\CaseRelationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentJoinHomingInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwBusinessRulesServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwMappingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZrcStatusEffectsInterface;
use OCA\Dossiq\Service\Zgw\ZgwSearchScope;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An object store fake that records what the controller asks of it.
 */
class ZrcCoverageStore {

	/**
	 * Rows find() answers, by uuid; a Throwable is thrown instead.
	 *
	 * @var array<string, mixed>
	 */
	public array $rows = [];

	/**
	 * Search pages to answer in order; a Throwable is thrown instead.
	 *
	 * @var array<int, mixed>
	 */
	public array $pages = [];

	/**
	 * Every find() call as [uuid, options].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	public array $finds = [];

	/**
	 * Every search query built.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Every saved object as [object, options].
	 *
	 * @var array<int, array{0: array, 1: ObjectSaveOptions}>
	 */
	public array $saved = [];

	/**
	 * Every deleted uuid.
	 *
	 * @var array<int, string>
	 */
	public array $deleted = [];

	/**
	 * Find a row.
	 *
	 * @param string $uuid    The uuid.
	 * @param mixed  $options The find options.
	 *
	 * @return mixed The row.
	 */
	public function find(string $uuid, mixed $options = null): mixed {
		$this->finds[] = [$uuid, $options];
		$row = ($this->rows[$uuid] ?? null);
		if ($row instanceof \Throwable) {
			throw $row;
		}

		return $row;
	}//end find()

	/**
	 * Build a search query, recording its parameters.
	 *
	 * @param array $requestParams The parameters.
	 * @param mixed $register      The register.
	 * @param mixed $schema        The schema.
	 *
	 * @return array The query.
	 */
	public function buildSearchQuery(array $requestParams, mixed $register = null, mixed $schema = null): array {
		$query = ['params' => $requestParams, 'register' => $register, 'schema' => $schema];
		$this->queries[] = $query;
		return $query;
	}//end buildSearchQuery()

	/**
	 * Answer the next search page.
	 *
	 * @param array $query The query.
	 *
	 * @return array The page.
	 */
	public function searchObjectsPaginated(array $query = []): array {
		$page = array_shift($this->pages) ?? ['results' => []];
		if ($page instanceof \Throwable) {
			throw $page;
		}

		return $page;
	}//end searchObjectsPaginated()

	/**
	 * Record a save.
	 *
	 * @param array             $object  The object.
	 * @param ObjectSaveOptions $options The options.
	 *
	 * @return array The saved object.
	 */
	public function saveObject(array $object, ObjectSaveOptions $options): array {
		$this->saved[] = [$object, $options];
		return $object;
	}//end saveObject()

	/**
	 * Record a delete.
	 *
	 * @param ObjectDeletion $deletion The deletion.
	 *
	 * @return void
	 */
	public function deleteObject(ObjectDeletion $deletion): void {
		$this->deleted[] = $deletion->uuid;
	}//end deleteObject()
}//end class

/**
 * Branch coverage tests for ZrcController.
 *
 * @covers \OCA\Dossiq\Controller\ZrcController
 * @uses \OCA\Dossiq\Service\Zgw\ZgwSearchScope
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 */
class ZrcControllerCoverageTest extends TestCase {

	/**
	 * A zaaktype uuid.
	 *
	 * @var string
	 */
	private const ZT_UUID = '11111111-2222-3333-4444-555555555555';

	/**
	 * The shared ZGW service.
	 *
	 * @var ZgwServiceInterface&MockObject
	 */
	private ZgwServiceInterface $zgw;

	/**
	 * The mapping service.
	 *
	 * @var ZgwMappingServiceInterface&MockObject
	 */
	private ZgwMappingServiceInterface $mappings;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The object store fake.
	 *
	 * @var ZrcCoverageStore
	 */
	private ZrcCoverageStore $store;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new ZrcCoverageStore();
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->mappings = $this->createMock(originalClassName: ZgwMappingServiceInterface::class);
		$this->zgw = $this->createMock(originalClassName: ZgwServiceInterface::class);
		$this->zgw->method('getLogger')->willReturn($this->logger);
		$this->zgw->method('getZgwMappingService')->willReturn($this->mappings);
		$this->zgw->method('getObjectService')->willReturn($this->store);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return ZrcController
	 */
	private function controller(): ZrcController {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		return new ZrcController(
			request: $this->request,
			zgwService: $this->zgw,
			l10n: $l10n,
			caseRelationService: $this->createMock(originalClassName: CaseRelationServiceInterface::class),
			statusEffects: $this->createMock(originalClassName: ZrcStatusEffectsInterface::class),
			joinHoming: $this->createMock(originalClassName: DocumentJoinHomingInterface::class),
		);
	}//end controller()

	/**
	 * Call a private method on a fresh controller.
	 *
	 * @param string               $method The method.
	 * @param array<string, mixed> $args   Named arguments.
	 *
	 * @return mixed
	 */
	private function call(string $method, array $args): mixed {
		$controller = $this->controller();
		return (new \ReflectionMethod($controller, $method))->invokeArgs($controller, $args);
	}//end call()

	/**
	 * Let a create through its refusal checks.
	 *
	 * @return void
	 */
	private function allowCreate(): void {
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->method('consumerHasScope')->willReturn(true);
	}//end allowCreate()

	/**
	 * A create of a resource with no mapping answers the service's mapping-not-found response.
	 *
	 * @return void
	 */
	public function testCreateWithoutMappingAnswersMappingNotFound(): void {
		$this->allowCreate();
		$notFound = new JSONResponse(['detail' => 'no mapping'], Http::STATUS_NOT_FOUND);
		$this->zgw->expects($this->once())->method('loadMappingConfig')->with('zaken', 'rollen')->willReturn(null);
		$this->zgw->expects($this->once())->method('mappingNotFoundResponse')->with('zaken', 'rollen')->willReturn($notFound);
		$this->zgw->expects($this->never())->method('getRequestBody');

		$this->assertSame(expected: $notFound, actual: $this->controller()->create('rollen'));
	}//end testCreateWithoutMappingAnswersMappingNotFound()

	/**
	 * A create whose body cannot be read is logged and answered as 400 with the message.
	 *
	 * @return void
	 */
	public function testCreateFailureIsLoggedAndAnswered400(): void {
		$this->allowCreate();
		$this->zgw->method('loadMappingConfig')->willReturn(['sourceRegister' => '1', 'sourceSchema' => '2']);
		$this->zgw->method('getRequestBody')->willThrowException(new \RuntimeException('body broken'));
		$this->logger->expects($this->once())->method('error')
			->with('ZRC create error: body broken', $this->callback(callback: static fn (array $ctx): bool => $ctx['exception'] instanceof \RuntimeException));

		$response = $this->controller()->create('rollen');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['detail' => 'body broken'], actual: $response->getData());
	}//end testCreateFailureIsLoggedAndAnswered400()

	/**
	 * A create into a closed zaak asks for the forced-update scope and hands its answer to the rules.
	 *
	 * @return void
	 */
	public function testValidateCreateOnClosedCaseAsksForcedScope(): void {
		$body = ['zaak' => 'https://x/zaken/1'];
		$mapping = ['sourceRegister' => '1', 'sourceSchema' => '2'];
		$this->zgw->expects($this->once())->method('resolveZaakClosedFromBody')->with('statussen', $body)->willReturn(true);
		$this->zgw->expects($this->once())->method('consumerHasScope')
			->with($this->request, 'zrc', 'zaken.geforceerd-bijwerken')->willReturn(false);
		$rules = $this->createMock(originalClassName: ZgwBusinessRulesServiceInterface::class);
		$rules->expects($this->once())->method('validate')
			->with('zaken', 'statussen', 'create', $body, null, $this->store, $mapping, null, true, false)
			->willReturn(['valid' => false, 'status' => 400]);
		$this->zgw->method('getBusinessRulesService')->willReturn($rules);

		$result = $this->call(method: 'validateCreate', args: ['resource' => 'statussen', 'body' => $body, 'mappingConfig' => $mapping]);

		$this->assertSame(expected: ['valid' => false, 'status' => 400], actual: $result);
	}//end testValidateCreateOnClosedCaseAsksForcedScope()

	/**
	 * A successful update of a resource without ZRC enrichment is answered as the handler gave it.
	 *
	 * @return void
	 */
	public function testEnrichUpdatedPassesOtherResourcesThrough(): void {
		$response = new JSONResponse(['url' => 'x'], Http::STATUS_OK);
		$this->zgw->expects($this->never())->method('getRequestBody');

		$this->assertSame(
			expected: $response,
			actual: $this->call(method: 'enrichUpdated', args: ['resource' => 'rollen', 'uuid' => 'u', 'response' => $response])
		);
	}//end testEnrichUpdatedPassesOtherResourcesThrough()

	/**
	 * The closed state of an existing closed zaak carries the forced-update scope.
	 *
	 * @return void
	 */
	public function testClosedStateOfAClosedCaseAsksForcedScope(): void {
		$this->store->rows['u-1'] = ['id' => 'u-1', 'status' => 'closed'];
		$this->zgw->expects($this->once())->method('resolveZaakClosed')
			->with('statussen', ['id' => 'u-1', 'status' => 'closed'])->willReturn(true);
		$this->zgw->expects($this->once())->method('consumerHasScope')
			->with($this->request, 'zrc', 'zaken.geforceerd-bijwerken')->willReturn(true);

		$result = $this->call(
			method: 'closedStateOf',
			args: ['resource' => 'statussen', 'uuid' => 'u-1', 'mappingConfig' => ['sourceRegister' => '7', 'sourceSchema' => '8']]
		);

		$this->assertSame(expected: [true, true], actual: $result);
		$this->assertInstanceOf(expected: ObjectFindOptions::class, actual: $this->store->finds[0][1]);
	}//end testClosedStateOfAClosedCaseAsksForcedScope()

	/**
	 * The ZIO response gains the fixed aardRelatieWeergave and the body's registratiedatum, unless it has one.
	 *
	 * @return void
	 */
	public function testEnrichZioResponse(): void {
		$this->assertSame(
			expected: ['natureRelationshipDisplay' => 'Hoort bij, omgekeerd: kent', 'registrationDate' => '2026-01-01'],
			actual: $this->call(method: 'enrichZioResponse', args: ['mapped' => [], 'body' => ['registrationDate' => '2026-01-01']])
		);
		$this->assertSame(
			expected: ['registrationDate' => 'kept', 'natureRelationshipDisplay' => 'Hoort bij, omgekeerd: kent'],
			actual: $this->call(method: 'enrichZioResponse', args: ['mapped' => ['registrationDate' => 'kept'], 'body' => ['registrationDate' => 'other']])
		);
	}//end testEnrichZioResponse()

	/**
	 * Configure the zaaktype mapping and stored zaaktype.
	 *
	 * @param mixed $zaaktype The stored zaaktype row (or Throwable).
	 *
	 * @return void
	 */
	private function zaaktype(mixed $zaaktype): void {
		$this->mappings->method('getMapping')->willReturnMap([
			['caseType', ['sourceRegister' => '3', 'sourceSchema' => '4']],
		]);
		$this->store->rows[self::ZT_UUID] = $zaaktype;
	}//end zaaktype()

	/**
	 * A product the zaaktype does not list is refused with 400 naming it.
	 *
	 * @return void
	 */
	public function testProductNotListedOnZaaktypeIsRefused(): void {
		$this->zaaktype(zaaktype: ['productsOrServices' => ['https://p/1']]);

		$response = $this->call(method: 'preValidateProductenOfDiensten', args: [
			'producten' => ['https://p/1', 'https://p/9'],
			'caseTypeUrl' => 'https://x/zaaktypen/' . self::ZT_UUID,
		]);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: 'productenOfDiensten contains a value not present in the zaaktype.', actual: $data['detail']);
		$this->assertSame(expected: 'invalid-products-services', actual: $data['invalidParams'][0]['code']);
		$this->assertSame(expected: "Product 'https://p/9' is not allowed for this zaaktype.", actual: $data['invalidParams'][0]['reason']);
		$this->assertSame(expected: self::ZT_UUID, actual: $this->store->finds[0][0]);
	}//end testProductNotListedOnZaaktypeIsRefused()

	/**
	 * Listed products pass, including a JSON-encoded legacy list.
	 *
	 * @return void
	 */
	public function testListedProductsPassIncludingJsonList(): void {
		$this->zaaktype(zaaktype: ['productenOfDiensten' => json_encode(['https://p/1', 'https://p/2'])]);

		$this->assertNull(actual: $this->call(method: 'preValidateProductenOfDiensten', args: [
			'producten' => ['https://p/2'],
			'caseTypeUrl' => 'https://x/zaaktypen/' . self::ZT_UUID,
		]));
	}//end testListedProductsPassIncludingJsonList()

	/**
	 * A zaaktype listing no products allows any product.
	 *
	 * @return void
	 */
	public function testZaaktypeWithoutProductListAllowsAnything(): void {
		$this->zaaktype(zaaktype: ['productsAndServices' => []]);

		$this->assertNull(actual: $this->call(method: 'preValidateProductenOfDiensten', args: [
			'producten' => ['https://p/anything'],
			'caseTypeUrl' => 'https://x/zaaktypen/' . self::ZT_UUID,
		]));
	}//end testZaaktypeWithoutProductListAllowsAnything()

	/**
	 * An unreadable zaaktype, a URL with no uuid, or no zaaktype mapping skip the check.
	 *
	 * @return void
	 */
	public function testUnresolvableZaaktypeSkipsTheCheck(): void {
		$this->zaaktype(zaaktype: new \RuntimeException(message: 'gone'));
		$args = ['producten' => ['https://p/1'], 'caseTypeUrl' => 'https://x/zaaktypen/' . self::ZT_UUID];
		$this->assertNull(actual: $this->call(method: 'preValidateProductenOfDiensten', args: $args));
		$this->assertCount(expectedCount: 1, haystack: $this->store->finds);

		$this->assertNull(
			actual: $this->call(method: 'preValidateProductenOfDiensten', args: ['producten' => ['p'], 'caseTypeUrl' => 'https://x/zaaktypen/none'])
		);
		$this->assertCount(expectedCount: 1, haystack: $this->store->finds);
	}//end testUnresolvableZaaktypeSkipsTheCheck()

	/**
	 * Without a zaaktype mapping the check is skipped and nothing is read.
	 *
	 * @return void
	 */
	public function testNoZaaktypeMappingSkipsTheCheck(): void {
		$this->mappings->expects($this->once())->method('getMapping')->with('caseType')->willReturn(null);

		$this->assertNull(actual: $this->call(method: 'preValidateProductenOfDiensten', args: [
			'producten' => ['https://p/1'],
			'caseTypeUrl' => 'https://x/zaaktypen/' . self::ZT_UUID,
		]));
		$this->assertSame(expected: [], actual: $this->store->finds);
	}//end testNoZaaktypeMappingSkipsTheCheck()

	/**
	 * Map the OIO mapping, ZIO mapping and base URL.
	 *
	 * @param array|null $oioMapping The objectinformatieobject mapping.
	 *
	 * @return void
	 */
	private function oioMapping(?array $oioMapping): void {
		$this->mappings->method('getMapping')->willReturnCallback(
			static fn (string $key): ?array => match ($key) {
				'objectinformatieobject' => $oioMapping,
				default => null,
			}
		);
	}//end oioMapping()

	/**
	 * Creating a ZIO saves the inbound-mapped OIO into the OIO register and schema.
	 *
	 * @return void
	 */
	public function testSyncCreateSavesMappedOio(): void {
		$config = ['sourceRegister' => '21', 'sourceSchema' => '22'];
		$this->oioMapping(oioMapping: $config);
		$mapping = new \stdClass();
		$this->zgw->expects($this->once())->method('createInboundMapping')->with($config)->willReturn($mapping);
		$this->zgw->expects($this->once())->method('applyInboundMapping')
			->with(['object' => 'https://z/1', 'objectType' => 'case', 'informatieobject' => 'https://io/1'], $mapping, $config)
			->willReturn(['object' => 'https://z/1', 'document' => 'https://io/1']);
		$this->logger->expects($this->once())->method('info')->with('zrc-005a: Created ObjectInformatieObject for zaak/io sync');

		$this->call(method: 'syncCreateObjectInformatieObject', args: ['caseUrl' => 'https://z/1', 'ioUrl' => 'https://io/1']);

		$this->assertCount(expectedCount: 1, haystack: $this->store->saved);
		$this->assertSame(expected: ['object' => 'https://z/1', 'document' => 'https://io/1'], actual: $this->store->saved[0][0]);
	}//end testSyncCreateSavesMappedOio()

	/**
	 * Without an OIO mapping the create sync is a logged no-op; empty URLs skip it entirely.
	 *
	 * @return void
	 */
	public function testSyncCreateWithoutMappingOrUrlsSavesNothing(): void {
		$this->oioMapping(oioMapping: null);
		$this->logger->expects($this->once())->method('debug')->with('zrc-005a: objectinformatieobject mapping not configured');

		$this->call(method: 'syncCreateObjectInformatieObject', args: ['caseUrl' => 'https://z/1', 'ioUrl' => 'https://io/1']);
		$this->call(method: 'syncCreateObjectInformatieObject', args: ['caseUrl' => '', 'ioUrl' => 'https://io/1']);

		$this->assertSame(expected: [], actual: $this->store->saved);
	}//end testSyncCreateWithoutMappingOrUrlsSavesNothing()

	/**
	 * A failing OIO create is logged as a warning and swallowed.
	 *
	 * @return void
	 */
	public function testSyncCreateFailureIsLogged(): void {
		$this->oioMapping(oioMapping: ['sourceRegister' => '21', 'sourceSchema' => '22']);
		$this->zgw->method('createInboundMapping')->willThrowException(new \RuntimeException('mapping broke'));
		$this->logger->expects($this->once())->method('warning')
			->with('zrc-005a: Failed to create ObjectInformatieObject: mapping broke');

		$this->call(method: 'syncCreateObjectInformatieObject', args: ['caseUrl' => 'https://z/1', 'ioUrl' => 'https://io/1']);

		$this->assertSame(expected: [], actual: $this->store->saved);
	}//end testSyncCreateFailureIsLogged()

	/**
	 * Deleting a zaak pages through its ZIOs and deletes the OIO of each.
	 *
	 * @return void
	 */
	public function testSyncDeleteOiosOfCaseDeletesEachLinkedOio(): void {
		$this->oioMapping(oioMapping: ['sourceRegister' => '21', 'sourceSchema' => '22']);
		$this->zgw->method('loadMappingConfig')->with('zaken', 'zaakinformatieobjecten')
			->willReturn(['sourceRegister' => '12', 'sourceSchema' => '34']);
		$this->zgw->method('buildBaseUrl')->with($this->request, 'zaken', 'zaken')->willReturn('https://h/zaken');
		$this->store->rows['zio-1'] = ['id' => 'zio-1', 'case' => 'case-1', 'document' => 'https://io/1'];
		$this->store->rows['zio-2'] = ['zaak' => 'case-1', 'informatieobject' => ''];
		$this->store->pages = [
			['results' => [['id' => 'zio-1'], ['@self' => ['id' => 'zio-2']], ['id' => '']]],
			['results' => [['id' => 'oio-1'], ['@self' => ['id' => 'oio-2']], ['id' => '']]],
		];
		$this->logger->expects($this->exactly(count: 2))->method('info');

		$scope = ZgwSearchScope::fromMapping(['sourceRegister' => '12', 'sourceSchema' => '34']);
		$this->call(method: 'syncDeleteOiosOfCase', args: ['uuid' => 'case-1', 'zioScope' => $scope]);

		$this->assertSame(expected: ['oio-1', 'oio-2'], actual: $this->store->deleted);
		$this->assertSame(expected: ['case' => 'case-1', '_limit' => 100, '_page' => 1], actual: $this->store->queries[0]['params']);
		$this->assertSame(expected: ['object' => 'https://h/zaken/case-1', 'document' => 'https://io/1'], actual: $this->store->queries[1]['params']);
		$this->assertSame(expected: 21, actual: $this->store->queries[1]['register']);
		$this->assertSame(expected: 22, actual: $this->store->queries[1]['schema']);
	}//end testSyncDeleteOiosOfCaseDeletesEachLinkedOio()

	/**
	 * A failing ZIO search during the zaak delete is logged and swallowed.
	 *
	 * @return void
	 */
	public function testSyncDeleteOiosOfCaseFailureIsLogged(): void {
		$this->store->pages = [new \RuntimeException('search down')];
		$this->logger->expects($this->once())->method('warning')
			->with('zrc-023: Failed to sync-delete OIOs for zaak case-1: search down');

		$scope = ZgwSearchScope::fromMapping(['sourceRegister' => '12', 'sourceSchema' => '34']);
		$this->call(method: 'syncDeleteOiosOfCase', args: ['uuid' => 'case-1', 'zioScope' => $scope]);

		$this->assertSame(expected: [], actual: $this->store->deleted);
	}//end testSyncDeleteOiosOfCaseFailureIsLogged()

	/**
	 * A ZIO that cannot be read, or has no ZIO mapping, yields no OIO sync data.
	 *
	 * @return void
	 */
	public function testZioDataForOioSyncUnreadable(): void {
		$this->zgw->method('loadMappingConfig')->willReturnOnConsecutiveCalls(
			['sourceRegister' => '12', 'sourceSchema' => '34'],
			null
		);
		$this->store->rows['zio-1'] = new \RuntimeException('not found');
		$this->logger->expects($this->once())->method('debug')
			->with('zrc-005b: Could not get ZIO data for OIO sync: not found');

		$controller = $this->controller();
		$method = new \ReflectionMethod($controller, 'getZioDataForOioSync');
		$this->assertNull(actual: $method->invoke($controller, 'zio-1'));
		$this->assertNull(actual: $method->invoke($controller, 'zio-1'));
		$this->assertCount(expectedCount: 1, haystack: $this->store->finds);
	}//end testZioDataForOioSyncUnreadable()

	/**
	 * An OIO mapping that cannot be searched is logged as an orphan error and nothing is deleted.
	 *
	 * @return void
	 */
	public function testDeleteOioWithUnsearchableMappingLogsOrphan(): void {
		$this->oioMapping(oioMapping: ['sourceRegister' => 'slug', 'sourceSchema' => '22']);
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains(string: 'so the OIO for https://io/1 is being left behind as an orphan'));

		$this->call(method: 'syncDeleteObjectInformatieObject', args: ['caseUrl' => 'https://z/1', 'ioUrl' => 'https://io/1']);

		$this->assertSame(expected: [], actual: $this->store->queries);
		$this->assertSame(expected: [], actual: $this->store->deleted);
	}//end testDeleteOioWithUnsearchableMappingLogsOrphan()

	/**
	 * No OIO mapping, or an empty URL, deletes nothing and searches nothing.
	 *
	 * @return void
	 */
	public function testDeleteOioWithoutMappingOrUrlsIsANoOp(): void {
		$this->oioMapping(oioMapping: ['sourceRegister' => '21', 'sourceSchema' => '22']);
		$this->call(method: 'syncDeleteObjectInformatieObject', args: ['caseUrl' => '', 'ioUrl' => 'https://io/1']);
		$this->assertSame(expected: [], actual: $this->store->queries);

		$this->mappings = $this->createMock(originalClassName: ZgwMappingServiceInterface::class);
		$this->mappings->method('getMapping')->willReturn(null);
		$this->zgw = $this->createMock(originalClassName: ZgwServiceInterface::class);
		$this->zgw->method('getZgwMappingService')->willReturn($this->mappings);
		$this->zgw->method('getObjectService')->willReturn($this->store);
		$this->call(method: 'syncDeleteObjectInformatieObject', args: ['caseUrl' => 'https://z/1', 'ioUrl' => 'https://io/1']);
		$this->assertSame(expected: [], actual: $this->store->queries);
	}//end testDeleteOioWithoutMappingOrUrlsIsANoOp()

	/**
	 * A failing OIO search during a delete sync is logged as a warning and swallowed.
	 *
	 * @return void
	 */
	public function testDeleteOioFailureIsLogged(): void {
		$this->oioMapping(oioMapping: ['sourceRegister' => '21', 'sourceSchema' => '22']);
		$this->store->pages = [new \RuntimeException('search down')];
		$this->logger->expects($this->once())->method('warning')
			->with('zrc-005b: Failed to delete ObjectInformatieObject: search down');

		$this->call(method: 'syncDeleteObjectInformatieObject', args: ['caseUrl' => 'https://z/1', 'ioUrl' => 'https://io/1']);

		$this->assertSame(expected: [], actual: $this->store->deleted);
	}//end testDeleteOioFailureIsLogged()

}//end class
