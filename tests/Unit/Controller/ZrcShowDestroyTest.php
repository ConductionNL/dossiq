<?php

/**
 * What ZrcController::show() and destroy() check, call and add for each resource.
 *
 * Characterisation written against the unsplit methods and run green on them
 * before they were split. Allowed and refused outcomes are pinned side by side.
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
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Zaakdossier\DocumentJoinHoming;
use OCA\Dossiq\Service\Zgw\ZrcStatusEffects;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A store that answers every find with one ZIO row.
 */
class ZrcShowDestroyStore {
	/**
	 * The uuids found.
	 *
	 * @var array<int, string>
	 */
	public array $found = [];

	/**
	 * Answer a find.
	 *
	 * @param string $uuid     The uuid.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function find(string $uuid, string $register='', string $schema=''): array {
		$this->found[] = $uuid . '@' . $schema;
		return ['case' => 'case-9', 'document' => 'https://drc/io/1'];
	}//end find()
}//end class

/**
 * @covers \OCA\Dossiq\Controller\ZrcController
 */
class ZrcShowDestroyTest extends TestCase {

	private const CASE_UUID = 'aaaaaaaa-1111-2222-3333-444444444444';

	/**
	 * Every call worth seeing, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Scopes the consumer holds.
	 *
	 * @var array<string, bool>
	 */
	private array $scopes = ['zaken.verwijderen' => true];

	/**
	 * The consumer's authorisaties: null is unrestricted, an empty list reads nothing.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $autorisaties = null;

	/**
	 * The status handleShow and handleDestroy answer with.
	 *
	 * @var integer
	 */
	private int $status = 200;

	/**
	 * What JWT validation answers.
	 *
	 * @var JSONResponse|null
	 */
	private ?JSONResponse $jwtError = null;

	/**
	 * The store.
	 *
	 * @var ZrcShowDestroyStore
	 */
	private ZrcShowDestroyStore $store;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new ZrcShowDestroyStore();
	}//end setUp()

	/**
	 * The controller over recording stubs.
	 *
	 * @return ZrcController
	 */
	private function controller(): ZrcController {
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturnCallback(fn (): ?JSONResponse => $this->jwtError);
		$zgw->method('consumerHasScope')->willReturnCallback(fn ($r, $c, string $scope): bool => ($this->scopes[$scope] ?? false));
		$zgw->method('getConsumerAuthorisaties')->willReturnCallback(fn (): ?array => $this->autorisaties);
		$zgw->method('getObjectService')->willReturn($this->store);
		$zgw->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		$zgw->method('loadMappingConfig')->willReturnCallback(
			fn (string $api, string $resource): ?array => ($resource === 'zaken') ? null : ['sourceRegister' => '1', 'sourceSchema' => $resource]
		);
		$zgw->method('buildBaseUrl')->willReturnCallback(fn ($r, string $api, string $resource): string => 'https://nc/' . $api . '/' . $resource);
		$zgw->method('resolveZaakClosed')->willReturn(false);
		$zgw->method('handleShow')->willReturnCallback(
			function ($request, string $api, string $resource, string $uuid): JSONResponse {
				$this->calls[] = 'show ' . $resource . ' ' . $uuid;
				return new JSONResponse(['uuid' => $uuid], $this->status);
			}
		);
		$zgw->method('handleDestroy')->willReturnCallback(
			function ($request, string $api, string $resource, string $uuid, $mapping, $closed, $forced): JSONResponse {
				$this->calls[] = 'destroy ' . $resource . ' ' . $uuid . ' ' . var_export($closed, true) . ' ' . var_export($forced, true);
				return new JSONResponse([], $this->status);
			}
		);

		$mapping = $this->createMock(ZgwMappingService::class);
		$mapping->method('getMapping')->willReturnCallback(
			function (string $name): ?array {
				$this->calls[] = 'mapping ' . $name;
				return null;
			}
		);
		$zgw->method('getZgwMappingService')->willReturn($mapping);

		$relations = $this->createMock(CaseRelationService::class);
		$relations->method('listRelations')->willReturn([['caseId' => 'other', 'aardRelatie' => 'vervolg']]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ZrcController(
			request: $this->createMock(IRequest::class),
			zgwService: $zgw,
			l10n: $l10n,
			caseRelationService: $relations,
			statusEffects: $this->createMock(ZrcStatusEffects::class),
			joinHoming: $this->createMock(DocumentJoinHoming::class),
		);
	}//end controller()

	/**
	 * Show: a bad token or a refused zaak read stops before the read; only a read zaak gets its related zaken.
	 *
	 * @return void
	 */
	public function testShowChecksTheZaakReadAndEnrichesOnlyAReadZaak(): void {
		$zaak = $this->controller()->show(resource: 'zaken', uuid: self::CASE_UUID);
		$this->assertSame(200, $zaak->getStatus());
		$this->assertSame(
			['uuid' => self::CASE_UUID, 'relevanteAndereZaken' => [['url' => 'https://nc/zaken/zaken/other', 'aardRelatie' => 'vervolg']]],
			$zaak->getData()
		);

		$status = $this->controller()->show(resource: 'statussen', uuid: 's1');
		$this->assertSame(['uuid' => 's1'], $status->getData());

		$this->status = 404;
		$this->assertSame(['uuid' => self::CASE_UUID], $this->controller()->show(resource: 'zaken', uuid: self::CASE_UUID)->getData());

		$this->autorisaties = [];
		$this->assertSame(403, $this->controller()->show(resource: 'zaken', uuid: self::CASE_UUID)->getStatus());
		$this->assertSame(['uuid' => 's2'], $this->controller()->show(resource: 'statussen', uuid: 's2')->getData());

		$this->jwtError = new JSONResponse(['detail' => 'bad token'], 401);
		$this->assertSame(401, $this->controller()->show(resource: 'statussen', uuid: 's3')->getStatus());

		$this->assertSame(
			['show zaken ' . self::CASE_UUID, 'show statussen s1', 'show zaken ' . self::CASE_UUID, 'show statussen s2'],
			$this->calls
		);
	}//end testShowChecksTheZaakReadAndEnrichesOnlyAReadZaak()

	/**
	 * Destroy of a sub-resource: a bad token or no zaken.verwijderen stops it; a deleted ZIO also cleans up its OIO.
	 *
	 * @return void
	 */
	public function testDestroyOfASubResourceIsGatedAndCleansUpTheOio(): void {
		$this->status = 204;
		$this->assertSame(204, $this->controller()->destroy(resource: 'zaakinformatieobjecten', uuid: 'zio-1')->getStatus());
		$this->assertSame(204, $this->controller()->destroy(resource: 'rollen', uuid: 'rol-1')->getStatus());

		$this->status = 400;
		$this->assertSame(400, $this->controller()->destroy(resource: 'zaakinformatieobjecten', uuid: 'zio-2')->getStatus());

		$this->scopes = [];
		$this->assertSame(403, $this->controller()->destroy(resource: 'zaakinformatieobjecten', uuid: 'zio-3')->getStatus());

		$this->jwtError = new JSONResponse(['detail' => 'bad token'], 401);
		$this->assertSame(401, $this->controller()->destroy(resource: 'rollen', uuid: 'rol-2')->getStatus());

		$this->assertSame(
			[
				'destroy zaakinformatieobjecten zio-1 false true',
				'mapping objectinformatieobject',
				'destroy rollen rol-1 false true',
				'destroy zaakinformatieobjecten zio-2 false true',
			],
			$this->calls
		);
		$this->assertSame(
			['zio-1@zaakinformatieobjecten', 'zio-1@zaakinformatieobjecten', 'rol-1@rollen', 'zio-2@zaakinformatieobjecten', 'zio-2@zaakinformatieobjecten'],
			$this->store->found
		);
	}//end testDestroyOfASubResourceIsGatedAndCleansUpTheOio()
}//end class
