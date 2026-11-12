<?php

/**
 * DrcController relation search golden tests.
 *
 * Pins how deleting a document looks for relations that point at it (drc-008a):
 * the three relation mappings read first, in order; per schema an exact search
 * on `document` and then a full-text search; the first hit stopping the search
 * and refusing the delete; a schema without a mapping skipped.
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
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The object service methods a delete reads through.
 */
interface DrcRelationSearchObjectServiceStub {
	/**
	 * @param mixed $id       The object.
	 * @param mixed $register The register.
	 * @param mixed $schema   The schema.
	 *
	 * @return mixed
	 */
	public function find(mixed $id, mixed $register, mixed $schema): mixed;

	/**
	 * @param array<string, mixed> $requestParams The search.
	 * @param mixed                $register      The register.
	 * @param mixed                $schema        The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array;

	/**
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed>
	 */
	public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * @covers \OCA\Dossiq\Controller\DrcController
 *
 * @uses \OCA\Dossiq\Controller\ZgwController
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 */
class DrcRelationSearchGoldenTest extends TestCase {

	private const UUID = 'eio-1';

	/**
	 * The calls the doubles saw, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A controller whose relation searches answer from $hits, keyed "schema:param".
	 *
	 * @param array<string, array<int, mixed>> $hits     Search answers.
	 * @param array<string, string>            $mappings Resource => schema of the relation mappings that exist.
	 *
	 * @return DrcController
	 */
	private function controller(array $hits, array $mappings): DrcController {
		$objects = $this->createMock(DrcRelationSearchObjectServiceStub::class);
		$objects->method('find')->willReturn(['id' => self::UUID]);
		$objects->method('buildSearchQuery')->willReturnCallback(
			function (array $requestParams, mixed $register, mixed $schema): array {
				$this->calls[] = ['query', (string)$register, (string)$schema, $requestParams];
				return ['key' => $schema . ':' . (array_key_exists('document', $requestParams) === true ? 'document' : '_search')];
			}
		);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			static fn (array $query): array => ['results' => ($hits[$query['key']] ?? [])]
		);

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		$zgw->method('validateJwtAuth')->willReturn(null);
		$zgw->method('consumerHasScope')->willReturn(true);
		$zgw->method('getObjectService')->willReturn($objects);
		$zgw->method('loadMappingConfig')->willReturnCallback(
			function (string $api, string $resource) use ($mappings): ?array {
				$this->calls[] = ['mapping', $api, $resource];
				return isset($mappings[$resource]) === true ? ['sourceRegister' => 'r-' . $api, 'sourceSchema' => $mappings[$resource]] : null;
			}
		);
		$zgw->method('handleDestroy')->willReturnCallback(
			function (): JSONResponse {
				$this->calls[] = ['destroy'];
				return new JSONResponse(data: [], statusCode: Http::STATUS_NOT_FOUND);
			}
		);
		$zgw->method('getDocumentService')->willReturn($this->createMock(ZgwDocumentService::class));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DrcController(appName: 'dossiq', request: $this->createMock(IRequest::class), zgwService: $zgw, l10n: $l10n);
	}//end controller()

	/**
	 * All three relation mappings.
	 *
	 * @return array<string, string>
	 */
	private static function allMappings(): array {
		return ['objectinformatieobjecten' => 'oio', 'zaakinformatieobjecten' => 'zio', 'besluitinformatieobjecten' => 'bio'];
	}//end allMappings()

	/**
	 * The relation calls, up to the delete.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function relationCalls(): array {
		$calls = [];
		foreach ($this->calls as $call) {
			if ($call[0] === 'destroy') {
				break;
			}

			if ($call[0] === 'query' || in_array($call[2] ?? '', array_keys(self::allMappings()), true) === true) {
				$calls[] = $call;
			}
		}

		return $calls;
	}//end relationCalls()

	/**
	 * Without relations the three mappings are read, each schema searched twice, and the delete goes on.
	 *
	 * @return void
	 */
	public function testWithoutRelationsEverySchemaIsSearchedTwice(): void {
		$this->controller([], self::allMappings())->destroy('enkelvoudiginformatieobjecten', self::UUID);

		$this->assertSame(
			[
				['mapping', 'documenten', 'objectinformatieobjecten'],
				['mapping', 'zaken', 'zaakinformatieobjecten'],
				['mapping', 'besluiten', 'besluitinformatieobjecten'],
				['query', 'r-documenten', 'oio', ['document' => self::UUID, '_limit' => 1]],
				['query', 'r-documenten', 'oio', ['_search' => self::UUID, '_limit' => 1]],
				['query', 'r-zaken', 'zio', ['document' => self::UUID, '_limit' => 1]],
				['query', 'r-zaken', 'zio', ['_search' => self::UUID, '_limit' => 1]],
				['query', 'r-besluiten', 'bio', ['document' => self::UUID, '_limit' => 1]],
				['query', 'r-besluiten', 'bio', ['_search' => self::UUID, '_limit' => 1]],
			],
			$this->relationCalls()
		);
		$this->assertContains(['destroy'], $this->calls);
	}//end testWithoutRelationsEverySchemaIsSearchedTwice()

	/**
	 * A full-text hit on the zaak side stops the search and refuses the delete.
	 *
	 * @return void
	 */
	public function testAFullTextHitStopsTheSearch(): void {
		$response = $this->controller(['zio:_search' => [['@self' => ['id' => 'zio-1']]]], self::allMappings())
			->destroy('enkelvoudiginformatieobjecten', self::UUID);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('pending-relations', $response->getData()['invalidParams'][0]['code']);
		$this->assertSame(['oio', 'oio', 'zio', 'zio'], array_column(array_slice($this->relationCalls(), 3), 2));
		$this->assertNotContains(['destroy'], $this->calls);
	}//end testAFullTextHitStopsTheSearch()

	/**
	 * An exact hit stops after one search.
	 *
	 * @return void
	 */
	public function testAnExactHitStopsAtOnce(): void {
		$response = $this->controller(['oio:document' => [['id' => 'oio-1']]], self::allMappings())
			->destroy('enkelvoudiginformatieobjecten', self::UUID);

		$this->assertSame(400, $response->getStatus());
		$this->assertCount(4, $this->relationCalls());
	}//end testAnExactHitStopsAtOnce()

	/**
	 * A relation schema without a mapping is not searched.
	 *
	 * @return void
	 */
	public function testASchemaWithoutAMappingIsSkipped(): void {
		$this->controller([], ['besluitinformatieobjecten' => 'bio'])->destroy('enkelvoudiginformatieobjecten', self::UUID);

		$this->assertSame(['bio', 'bio'], array_column(array_slice($this->relationCalls(), 3), 2));
	}//end testASchemaWithoutAMappingIsSkipped()
}//end class
