<?php

/**
 * DRC usage-rights clearing, asserted from the endpoint that triggers it.
 *
 * Drc-006 clears `indicatieGebruiksrecht` on a document once its last
 * gebruiksrecht is deleted. The trigger is a count of zero coming back from
 * OpenRegister's paginated search — and a count of zero is ALSO what that
 * search answers when the gebruiksrechten mapping names its register or schema
 * by slug, because `buildSearchQuery()` casts the reference to int and
 * `MagicMapper` cannot load register `0`. No exception, no empty-result flag,
 * just a well-formed page saying there is nothing there.
 *
 * The fake store below reproduces that behaviour rather than stubbing it away.
 * A double that resolved slugs would make the bug untestable and the fix
 * unprovable: it would report green both before and after.
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

use OCA\Dossiq\Controller\DrcController;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister's search path, with the int cast that loses a slug.
 *
 * `buildSearchQuery()` is a verbatim reproduction of
 * `SearchQueryHandler::buildSearchQuery()`'s handling of the register and
 * schema arguments, and `searchObjectsPaginated()` of
 * `MagicMapper::searchObjectsPaginated()`'s behaviour when either id fails to
 * resolve. `find()` and `saveObject()` take a slug OR an id, as the real ones
 * do: that asymmetry is the whole defect.
 */
final class FakeOpenRegisterStore {
	/**
	 * Objects this store holds, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $objects = [];

	/**
	 * Every saveObject() call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, int> $registerIds The register ids this store knows.
	 * @param array<int, int> $schemaIds The schema ids this store knows.
	 */
	public function __construct(
		private readonly array $registerIds,
		private readonly array $schemaIds,
	) {
	}//end __construct()

	/**
	 * Build a search query exactly as OpenRegister does.
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
		$query = ['@self' => []];
		if ($register !== null) {
			// The cast that turns every slug and every uuid into 0.
			$query['@self']['register'] = (int)$register;
		}

		if ($schema !== null) {
			$query['@self']['schema'] = (int)$schema;
		}

		return array_merge($query, $requestParams);
	}//end buildSearchQuery()

	/**
	 * Search, answering an unresolvable scope with an empty page and no error.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed> The paginated result.
	 */
	public function searchObjectsPaginated(array $query = []): array {
		$registerId = ($query['@self']['register'] ?? null);
		$schemaId = ($query['@self']['schema'] ?? null);

		$resolves = ($registerId !== null && in_array($registerId, $this->registerIds, true) === true
			&& $schemaId !== null && in_array($schemaId, $this->schemaIds, true) === true);
		if ($resolves === false) {
			// MagicMapper logs one warning here and returns this.
			return ['results' => [], 'total' => 0];
		}

		$rows = array_values($this->objects);
		foreach ($query as $key => $value) {
			if (str_starts_with($key, '_') === true || $key === '@self') {
				continue;
			}

			$rows = array_values(
				array_filter($rows, static fn (array $row): bool => ($row[$key] ?? null) === $value)
			);
		}

		return ['results' => $rows, 'total' => count($rows)];
	}//end searchObjectsPaginated()

	/**
	 * Find one object by uuid; the reference may be a slug or an id.
	 *
	 * @param int|string $id The object uuid.
	 * @param mixed ...$args The remaining find() arguments.
	 *
	 * @return array<string, mixed>|null The stored row.
	 */
	public function find(int|string $id, mixed ...$args): ?array {
		return ($this->objects[(string)$id] ?? null);
	}//end find()

	/**
	 * Record a write; the reference may be a slug or an id.
	 *
	 * @param array<string, mixed> $object The object data.
	 * @param mixed $register The register reference.
	 * @param mixed $schema The schema reference.
	 * @param string|null $uuid The uuid being overwritten.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	public function saveObject(
		array $object,
		mixed $register = null,
		mixed $schema = null,
		?string $uuid = null,
	): array {
		$this->saves[] = ['uuid' => $uuid, 'object' => $object];

		return $object;
	}//end saveObject()
}//end class

/**
 * Deleting the last gebruiksrecht, from `DrcController::destroy()` down.
 */
final class DrcGebruiksrechtScopeTest extends TestCase {
	/**
	 * The uuid of the document under test.
	 *
	 * @var string
	 */
	private const EIO_UUID = '11111111-2222-3333-4444-555555555555';

	/**
	 * The uuid of the gebruiksrecht being deleted.
	 *
	 * @var string
	 */
	private const GR_UUID = '99999999-8888-7777-6666-555555555555';

	/**
	 * The store the controller reads and writes.
	 *
	 * @var FakeOpenRegisterStore
	 */
	private FakeOpenRegisterStore $store;

	/**
	 * Build a controller whose gebruiksrechten mapping uses the given scope.
	 *
	 * @param mixed $grRegister The gebruiksrechten mapping's sourceRegister.
	 * @param mixed $grSchema The gebruiksrechten mapping's sourceSchema.
	 * @param bool $anotherRightRemains Whether a second gebruiksrecht survives the delete.
	 *
	 * @return DrcController The controller under test.
	 */
	private function controllerWith(
		mixed $grRegister,
		mixed $grSchema,
		bool $anotherRightRemains,
	): DrcController {
		$this->store = new FakeOpenRegisterStore(registerIds: [12], schemaIds: [34, 56]);
		$this->store->objects[self::GR_UUID] = [
			'id' => self::GR_UUID,
			'document' => self::EIO_UUID,
		];
		$this->store->objects[self::EIO_UUID] = [
			'id' => self::EIO_UUID,
			'usageRightsIndication' => true,
		];

		if ($anotherRightRemains === true) {
			$this->store->objects['77777777-8888-7777-6666-555555555555'] = [
				'id' => '77777777-8888-7777-6666-555555555555',
				'document' => self::EIO_UUID,
			];
		}

		$mappings = [
			'gebruiksrechten' => [
				'sourceRegister' => $grRegister,
				'sourceSchema' => $grSchema,
			],
			'enkelvoudiginformatieobjecten' => [
				'sourceRegister' => '12',
				'sourceSchema' => '56',
			],
		];

		$zgwService = $this->createMock(ZgwService::class);
		$zgwService->method('validateJwtAuth')->willReturn(null);
		$zgwService->method('consumerHasScope')->willReturn(true);
		$zgwService->method('getObjectService')->willReturn($this->store);
		$zgwService->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		$zgwService->method('loadMappingConfig')->willReturnCallback(
			static fn (string $zgwApi, string $resource): ?array => ($mappings[$resource] ?? null)
		);
		// The delete really removes the row, so the count that follows is the
		// count of what SURVIVES it, as it is in production.
		$store = $this->store;
		$zgwService->method('handleDestroy')->willReturnCallback(
			static function (...$args) use ($store): JSONResponse {
				unset($store->objects[(string)$args[3]]);

				return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DrcController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			zgwService: $zgwService,
			l10n: $l10n,
		);
	}//end controllerWith()

	/**
	 * CONTROL: with a searchable scope and no rights left, the flag is cleared.
	 *
	 * Without this, a fix that simply never clears anything would also pass
	 * the test below.
	 *
	 * @return void
	 */
	public function testLastRightDeletedClearsTheIndicationOnASearchableScope(): void {
		$controller = $this->controllerWith(
			grRegister: '12',
			grSchema: '34',
			anotherRightRemains: false
		);

		$controller->destroy(resource: 'gebruiksrechten', uuid: self::GR_UUID);

		$this->assertCount(1, $this->store->saves);
		$this->assertArrayHasKey('usageRightsIndication', $this->store->saves[0]['object']);
		$this->assertNull($this->store->saves[0]['object']['usageRightsIndication']);
	}//end testLastRightDeletedClearsTheIndicationOnASearchableScope()

	/**
	 * CONTROL: with a searchable scope and a right left, nothing is written.
	 *
	 * @return void
	 */
	public function testARemainingRightLeavesTheIndicationAlone(): void {
		$controller = $this->controllerWith(
			grRegister: '12',
			grSchema: '34',
			anotherRightRemains: true
		);

		$controller->destroy(resource: 'gebruiksrechten', uuid: self::GR_UUID);

		$this->assertSame([], $this->store->saves);
	}//end testARemainingRightLeavesTheIndicationAlone()

	/**
	 * THE DEFECT: a slug-scoped mapping must not clear a usage right.
	 *
	 * The document still carries a gebruiksrecht. The search cannot see it,
	 * because the mapping names its schema by slug and OpenRegister reads that
	 * as schema `0`. Answering the resulting zero by writing
	 * `usageRightsIndication = null` records "this document carries no usage
	 * restrictions" about a document whose restrictions were never counted.
	 *
	 * @return void
	 */
	public function testASlugScopedMappingNeverClearsTheIndication(): void {
		$controller = $this->controllerWith(
			grRegister: '12',
			grSchema: 'gebruiksrecht',
			anotherRightRemains: true
		);

		$controller->destroy(resource: 'gebruiksrechten', uuid: self::GR_UUID);

		$this->assertSame(
			[],
			$this->store->saves,
			'A usage right was cleared on the strength of a search that could not run.'
		);
		$this->assertTrue($this->store->objects[self::EIO_UUID]['usageRightsIndication']);
	}//end testASlugScopedMappingNeverClearsTheIndication()

	/**
	 * THE DEFECT: an empty sourceSchema is the same zero, and must not clear.
	 *
	 * This is the shape a mapping written before its schema existed keeps for
	 * the life of the instance.
	 *
	 * @return void
	 */
	public function testAnEmptySchemaNeverClearsTheIndication(): void {
		$controller = $this->controllerWith(
			grRegister: '12',
			grSchema: '',
			anotherRightRemains: true
		);

		$controller->destroy(resource: 'gebruiksrechten', uuid: self::GR_UUID);

		$this->assertSame(
			[],
			$this->store->saves,
			'A usage right was cleared on the strength of a search that could not run.'
		);
	}//end testAnEmptySchemaNeverClearsTheIndication()
}//end class
