<?php

/**
 * Ztc Related Type Lookup Test
 *
 * How the ZTC enrichment widens a stored reference into every type sharing
 * its identity: zaaktypen by identifier, informatieobjecttypen by name, and
 * the objects listed under a case type; and how each lookup degrades when
 * OpenRegister cannot answer.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZtcRelatedTypeLookup;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zgw\ZtcRelatedTypeLookup
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses   \OCA\Dossiq\Support\NormalisesObjectRows
 */
class ZtcRelatedTypeLookupTest extends TestCase {

	private const BASE = 'https://nc/apps/procest/api/zgw/catalogi/v1';

	/**
	 * Rows per schema, keyed by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Schemas whose search or find throws.
	 *
	 * @var array<int, string>
	 */
	public array $failing = [];

	/**
	 * Mappings the ZgwService answers, by resource.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $mappings = [
		'zaaktypen' => ['sourceRegister' => 'dossiq', 'sourceSchema' => 'caseType'],
		'zaaktype-informatieobjecttypen' => ['sourceRegister' => 'dossiq', 'sourceSchema' => 'caseTypeDocumentType'],
		'informatieobjecttypen' => ['sourceRegister' => 'dossiq', 'sourceSchema' => 'documentType'],
		'statustypen' => ['sourceRegister' => 'dossiq', 'sourceSchema' => 'statusType'],
	];

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The lookup under test.
	 *
	 * @return ZtcRelatedTypeLookup
	 */
	private function lookup(): ZtcRelatedTypeLookup {
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getLogger')->willReturn($this->logger);
		$zgw->method('loadMappingConfig')->willReturnCallback(
			fn (string $api, string $resource): ?array => $this->mappings[$resource] ?? null
		);

		return new ZtcRelatedTypeLookup(zgwService: $zgw);
	}

	/**
	 * An ObjectService double over `$rows`: equality search on every param.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class($this) {
			public function __construct(private readonly ZtcRelatedTypeLookupTest $test) {
			}

			public function find(mixed $id, mixed $register = null, mixed $schema = null): mixed {
				if (in_array((string)$schema, $this->test->failing, true) === true) {
					throw new RuntimeException('find failed');
				}

				return $this->test->rows[(string)$schema][(string)$id] ?? null;
			}

			/**
			 * @param array<string, mixed> $requestParams The params.
			 *
			 * @return array<string, mixed>
			 */
			public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array {
				unset($requestParams['_limit']);
				return ['schema' => (string)$schema, 'params' => $requestParams];
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query): array {
				if (in_array($query['schema'], $this->test->failing, true) === true) {
					throw new RuntimeException('index offline');
				}

				$results = [];
				foreach ($this->test->rows[$query['schema']] ?? [] as $row) {
					if (array_intersect_assoc($query['params'], $row) === $query['params']) {
						$results[] = $row;
					}
				}

				return ['results' => $results];
			}
		};
	}

	public function testAZaaktypeWidensToEveryVersionWithItsIdentifier(): void {
		$this->rows['caseType'] = [
			'zt-1' => ['id' => 'zt-1', 'identifier' => 'VERG'],
			'zt-2' => ['@self' => ['id' => 'zt-2'], 'identifier' => 'VERG'],
			'zt-3' => ['id' => 'zt-3', 'identifier' => 'OTHER'],
		];

		self::assertSame(
			[self::BASE . '/zaaktypen/zt-1', self::BASE . '/zaaktypen/zt-2'],
			$this->lookup()->sameIdentifierUrls($this->store(), $this->mappings['zaaktypen'], 'zt-1', self::BASE)
		);
	}

	public function testAZaaktypeWithoutAnIdentifierWidensToNothing(): void {
		$this->rows['caseType'] = ['zt-1' => ['id' => 'zt-1']];

		self::assertSame([], $this->lookup()->sameIdentifierUrls($this->store(), $this->mappings['zaaktypen'], 'zt-1', self::BASE));
	}

	public function testAFailedZaaktypeLookupFallsBackToTheReferenceItself(): void {
		$this->failing = ['caseType'];

		self::assertSame(
			[self::BASE . '/zaaktypen/zt-9'],
			$this->lookup()->sameIdentifierUrls($this->store(), $this->mappings['zaaktypen'], 'zt-9', self::BASE)
		);
	}

	public function testDocumentTypesWidenByNameAndAreDeduplicated(): void {
		$this->rows['caseTypeDocumentType'] = [
			'l-1' => ['id' => 'l-1', 'caseType' => 'zt-1', 'informatieobjecttype' => 'iot-1'],
			'l-2' => ['id' => 'l-2', 'caseType' => 'zt-1', 'informatieobjecttype' => 'iot-2'],
			'l-3' => ['id' => 'l-3', 'caseType' => 'zt-1', 'informatieobjecttype' => ''],
			'l-4' => ['id' => 'l-4', 'caseType' => 'zt-1', 'informatieobjecttype' => 'iot-nameless'],
		];
		$this->rows['documentType'] = [
			'iot-1' => ['id' => 'iot-1', 'name' => 'Besluit'],
			'iot-2' => ['id' => 'iot-2', 'name' => 'Besluit'],
			'iot-nameless' => ['id' => 'iot-nameless', 'name' => ''],
		];

		self::assertSame(
			[self::BASE . '/informatieobjecttypen/iot-1', self::BASE . '/informatieobjecttypen/iot-2'],
			$this->lookup()->caseTypeDocumentTypeUrls($this->store(), 'zt-1', self::BASE)
		);
	}

	public function testAFailedDocumentTypeLookupFallsBackToTheReferenceItself(): void {
		$this->rows['caseTypeDocumentType'] = ['l-1' => ['id' => 'l-1', 'caseType' => 'zt-1', 'informatieobjecttype' => 'iot-1']];
		$this->failing = ['documentType'];

		self::assertSame(
			[self::BASE . '/informatieobjecttypen/iot-1'],
			$this->lookup()->caseTypeDocumentTypeUrls($this->store(), 'zt-1', self::BASE)
		);
	}

	public function testWithoutBothMappingsThereAreNoDocumentTypes(): void {
		unset($this->mappings['informatieobjecttypen']);

		self::assertSame([], $this->lookup()->caseTypeDocumentTypeUrls($this->store(), 'zt-1', self::BASE));
	}

	public function testObjectsUnderACaseTypeAreListedByTheirId(): void {
		$this->rows['statusType'] = [
			's-1' => ['id' => 's-1', 'caseType' => 'zt-1'],
			's-2' => ['@self' => ['id' => 's-2'], 'caseType' => 'zt-1'],
			's-3' => ['caseType' => 'zt-1'],
			's-4' => ['id' => 's-4', 'caseType' => 'zt-2'],
		];

		self::assertSame(
			[self::BASE . '/statustypen/s-1', self::BASE . '/statustypen/s-2'],
			$this->lookup()->urlsOfObjectsFor($this->store(), 'statustypen', 'zt-1', self::BASE)
		);
		self::assertSame([], $this->lookup()->urlsOfObjectsFor($this->store(), 'roltypen', 'zt-1', self::BASE));
	}

	public function testAFailedSearchIsLoggedAndLeavesTheListUnenriched(): void {
		$this->failing = ['statusType'];
		$this->logger->expects($this->once())
			->method('warning')
			->with('ZTC enrichment: the search of statusType failed, so its list stays unenriched: index offline');

		self::assertSame([], $this->lookup()->urlsOfObjectsFor($this->store(), 'statustypen', 'zt-1', self::BASE));
	}
}
