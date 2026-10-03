<?php

/**
 * Deleting a zaak whose OIO cascade cannot run.
 *
 * Zrc-005b sync-deletes the ObjectInformatieObjecten in DRC before the zaak
 * itself goes, because OpenRegister's own cascade does not reach across
 * components. It finds them by paging a zaakinformatieobject search — and an
 * unsearchable scope answers that search with an empty first page and no
 * error, which reads as "this zaak has no documents linked". The zaak is then
 * destroyed and every OIO that pointed at it survives, pointing at nothing.
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
use OCA\Dossiq\Service\Archival\ArchivalNominationDeriver;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Zaakdossier\DocumentJoinHoming;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The object store, deleting for real and losing a slug exactly as the real
 * search path does.
 */
final class ZrcCascadeStore {
	/**
	 * Uuids passed to deleteObject(), in order.
	 *
	 * @var array<int, string>
	 */
	public array $deleted = [];

	/**
	 * Build a search query, reproducing OpenRegister's int cast.
	 *
	 * @param array<string, mixed> $requestParams The filters.
	 * @param mixed $register The register reference.
	 * @param mixed $schema The schema reference.
	 *
	 * @return array<string, mixed> The query.
	 */
	public function buildSearchQuery(
		array $requestParams,
		mixed $register = null,
		mixed $schema = null,
	): array {
		return [
			'@self' => ['register' => (int)$register, 'schema' => (int)$schema],
		];
	}//end buildSearchQuery()

	/**
	 * Search; an unresolvable scope answers empty with no error.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed> The paginated result.
	 */
	public function searchObjectsPaginated(array $query = []): array {
		return ['results' => [], 'total' => 0];
	}//end searchObjectsPaginated()

	/**
	 * Find the zaak under test.
	 *
	 * @param int|string $id The uuid.
	 * @param mixed ...$args The remaining find() arguments.
	 *
	 * @return array<string, mixed> The zaak row.
	 */
	public function find(int|string $id, mixed ...$args): array {
		return ['id' => (string)$id, 'archiefstatus' => ''];
	}//end find()

	/**
	 * Record a delete.
	 *
	 * @param string $uuid The uuid being deleted.
	 *
	 * @return void
	 */
	public function deleteObject(string $uuid): void {
		$this->deleted[] = $uuid;
	}//end deleteObject()
}//end class

/**
 * `ZrcController::destroy('zaken', …)` against an unsearchable ZIO mapping.
 */
final class ZrcDestroyCascadeScopeTest extends TestCase {
	/**
	 * The zaak under test.
	 *
	 * @var string
	 */
	private const CASE_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	/**
	 * The store, watched for deletes.
	 *
	 * @var ZrcCascadeStore
	 */
	private ZrcCascadeStore $store;

	/**
	 * Build a controller whose zaakinformatieobject mapping uses the given scope.
	 *
	 * @param array<string, mixed>|null $zioMapping The zaakinformatieobject mapping.
	 *
	 * @return ZrcController The controller under test.
	 */
	private function controllerWith(?array $zioMapping): ZrcController {
		$this->store = new ZrcCascadeStore();

		$mappingService = $this->createMock(ZgwMappingService::class);
		$mappingService->method('getMapping')->willReturnCallback(
			static fn (string $resourceKey): ?array => ($resourceKey === 'zaakinformatieobject' ? $zioMapping : null)
		);

		$zgwService = $this->createMock(ZgwService::class);
		$zgwService->method('validateJwtAuth')->willReturn(null);
		$zgwService->method('consumerHasScope')->willReturn(true);
		$zgwService->method('getObjectService')->willReturn($this->store);
		$zgwService->method('getZgwMappingService')->willReturn($mappingService);
		$zgwService->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		$zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => '12', 'sourceSchema' => '34']
		);
		$zgwService->method('buildBaseUrl')->willReturn('https://example.test/zaken');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ZrcController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			zgwService: $zgwService,
			l10n: $l10n,
			dates: $this->createMock(CaseDateNormaliser::class),
			caseRelationService: $this->createMock(CaseRelationService::class),
			archivalDeriver: $this->createMock(ArchivalNominationDeriver::class),
			joinHoming: $this->createMock(DocumentJoinHoming::class),
		);
	}//end controllerWith()

	/**
	 * CONTROL: a searchable ZIO mapping still lets the zaak be deleted.
	 *
	 * Without this, a fix that refused every delete would also pass the test
	 * below.
	 *
	 * @return void
	 */
	public function testASearchableZioMappingStillDeletesTheZaak(): void {
		$controller = $this->controllerWith(
			zioMapping: ['sourceRegister' => '12', 'sourceSchema' => '34']
		);

		$response = $controller->destroy(resource: 'zaken', uuid: self::CASE_UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame([self::CASE_UUID], $this->store->deleted);
	}//end testASearchableZioMappingStillDeletesTheZaak()

	/**
	 * CONTROL: no ZIO mapping at all is not this guard's business.
	 *
	 * Nothing can create a zaakinformatieobject either, so there is nothing to
	 * orphan and the delete proceeds as it always did.
	 *
	 * @return void
	 */
	public function testNoZioMappingStillDeletesTheZaak(): void {
		$controller = $this->controllerWith(zioMapping: null);

		$response = $controller->destroy(resource: 'zaken', uuid: self::CASE_UUID);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame([self::CASE_UUID], $this->store->deleted);
	}//end testNoZioMappingStillDeletesTheZaak()

	/**
	 * THE DEFECT: a slug-scoped ZIO mapping must stop the delete.
	 *
	 * @return void
	 */
	public function testASlugScopedZioMappingRefusesTheDelete(): void {
		$controller = $this->controllerWith(
			zioMapping: ['sourceRegister' => '12', 'sourceSchema' => 'zaakinformatieobject']
		);

		$response = $controller->destroy(resource: 'zaken', uuid: self::CASE_UUID);

		$this->assertSame(
			[],
			$this->store->deleted,
			'The zaak was destroyed although its linked documents could not be unlinked.'
		);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			'zaakinformatieobject-mapping-unsearchable',
			$response->getData()['code']
		);
	}//end testASlugScopedZioMappingRefusesTheDelete()

	/**
	 * THE DEFECT: an empty sourceSchema is the same zero, and stops it too.
	 *
	 * @return void
	 */
	public function testAnEmptySchemaRefusesTheDelete(): void {
		$controller = $this->controllerWith(
			zioMapping: ['sourceRegister' => '12', 'sourceSchema' => '']
		);

		$response = $controller->destroy(resource: 'zaken', uuid: self::CASE_UUID);

		$this->assertSame(
			[],
			$this->store->deleted,
			'The zaak was destroyed although its linked documents could not be unlinked.'
		);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testAnEmptySchemaRefusesTheDelete()
}//end class
