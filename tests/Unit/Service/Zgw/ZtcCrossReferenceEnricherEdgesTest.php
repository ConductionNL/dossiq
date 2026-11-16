<?php

/**
 * ZtcCrossReferenceEnricher edges: an unmapped besluittype and a zaaktype that stores no lists.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher;
use OCA\Dossiq\Service\ZgwService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;

/**
 * What the enricher does when there is nothing stored to expand.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher
 * @uses \OCA\Dossiq\Service\Zgw\ZtcRelatedTypeLookup
 * @uses \OCA\Dossiq\Service\Zgw\ZtcStoredUuidUrls
 * @uses \OCA\Dossiq\Service\Zgw\ZtcEnrichmentTarget
 * @uses \OCA\Dossiq\Service\Zgw\MappedObjects
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZtcCrossReferenceEnricherEdgesTest extends TestCase {
	private const BASE = 'https://nc/zgw/catalogi/v1';

	private const ZT = 'a1000000-0000-0000-0000-000000000001';

	private const BT = 'b1000000-0000-0000-0000-000000000001';

	/**
	 * Every call on the object service, as "<method> <schema>[ <id>]".
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * An object service whose finds answer from a map by "<schema>:<id>" and whose searches find nothing.
	 *
	 * @param array<string, array> $finds The stored objects.
	 *
	 * @return object
	 */
	private function objects(array $finds): object {
		$calls = &$this->calls;

		return new class($finds, $calls) {
			/**
			 * @param array<string, array> $finds The stored objects.
			 * @param array<int, string> $calls The call log.
			 */
			public function __construct(private array $finds, private array &$calls) {
			}

			public function find(mixed $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->calls[] = 'find ' . $options?->schema . ' ' . $id;
				return $this->finds[$options?->schema . ':' . $id] ?? throw new \RuntimeException('not found');
			}

			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				return ['schema' => $schema, 'params' => $requestParams];
			}

			public function searchObjectsPaginated(array $query): array {
				$this->calls[] = 'search ' . $query['schema'] . ' ' . (string)array_key_first($query['params']);
				return ['results' => []];
			}
		};
	}//end objects()

	/**
	 * A ZgwService whose Catalogi resources map onto schema = resource name, except the unmapped ones.
	 *
	 * @param object $objectService The object service.
	 * @param array<int, string> $unmapped Resources without a mapping.
	 *
	 * @return ZgwService
	 */
	private function zgw(object $objectService, array $unmapped = []): ZgwService {
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getObjectService')->willReturn($objectService);
		$zgw->method('loadMappingConfig')->willReturnCallback(
			static fn (string $api, string $resource): ?array => in_array($resource, $unmapped, true) ? null : ['sourceRegister' => 'r', 'sourceSchema' => $resource]
		);

		return $zgw;
	}//end zgw()

	/**
	 * Without a besluittypen mapping a besluittype comes back as it went in, and nothing is read.
	 *
	 * @return void
	 */
	public function testAnUnmappedBesluittypeIsReturnedUnread(): void {
		$objects = $this->objects(finds: ['besluittypen:' . self::BT => ['caseTypes' => [self::ZT]]]);
		$data = ['uuid' => self::BT, 'omschrijving' => 'Besluit'];

		$enriched = (new ZtcCrossReferenceEnricher(zgwService: $this->zgw(objectService: $objects, unmapped: ['besluittypen'])))
			->enrich(resource: 'besluittypen', data: $data, baseUrl: self::BASE);

		$this->assertSame($data, $enriched);
		$this->assertSame([], $this->calls);
	}//end testAnUnmappedBesluittypeIsReturnedUnread()

	/**
	 * A readable zaaktype that stores no deelzaaktypen, besluittypen or related zaaktypen gets
	 * empty lists, its besluittypen from the fallback search, and no related-zaaktype lookup.
	 *
	 * @return void
	 */
	public function testAZaaktypeThatStoresNoListsGetsEmptyListsAndNoRelatedLookup(): void {
		$objects = $this->objects(finds: ['zaaktypen:' . self::ZT => ['identifier' => 'ZT-1', 'subCaseTypes' => [], 'decisionTypes' => 'none']]);

		$enriched = (new ZtcCrossReferenceEnricher(zgwService: $this->zgw(objectService: $objects)))
			->enrich(resource: 'zaaktypen', data: ['uuid' => self::ZT], baseUrl: self::BASE);

		$this->assertSame(
			[
				'uuid' => self::ZT,
				'deelzaaktypen' => [],
				'gerelateerdeZaaktypen' => [],
				'besluittypen' => [],
				'informatieobjecttypen' => [],
				'eigenschappen' => [],
				'statustypen' => [],
				'resultaattypen' => [],
				'roltypen' => [],
			],
			$enriched
		);
		$this->assertSame(
			[
				'find zaaktypen ' . self::ZT,
				'search zaaktype-informatieobjecttypen caseType',
				'search besluittypen caseType',
				'search eigenschappen caseType',
				'search statustypen caseType',
				'search resultaattypen caseType',
				'search roltypen caseType',
			],
			$this->calls,
			'the zaaktype is read once and no identifier search for related zaaktypen runs'
		);
	}//end testAZaaktypeThatStoresNoListsGetsEmptyListsAndNoRelatedLookup()
}//end class
