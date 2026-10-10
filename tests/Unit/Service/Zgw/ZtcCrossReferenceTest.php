<?php

/**
 * Unit tests for the ZTC cross-reference classes.
 *
 * Covers ZtcCrossReferenceEnricher, ZtcRelatedTypeLookup and ZtcUrlValidityFilter, which
 * together build and filter a zaaktype's and besluittype's related-type URL lists.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher;
use OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;

/**
 * The ObjectService shape the ZTC classes call.
 */
interface ZtcCrossRefObjectServiceStub {
	public function find(mixed $id, string $register, string $schema): mixed;

	public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

	public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * Tests for building and filtering ZTC cross-reference lists.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher
 * @covers \OCA\Dossiq\Service\Zgw\ZtcRelatedTypeLookup
 * @covers \OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter
 */
class ZtcCrossReferenceTest extends TestCase {

	private const BASE = 'https://nc/zgw/catalogi/v1';
	private const ZT = 'a1000000-0000-0000-0000-000000000001';
	private const ZT_DEEL = 'a1000000-0000-0000-0000-000000000002';
	private const ZT_DEEL_V2 = 'a1000000-0000-0000-0000-000000000003';
	private const BT = 'b1000000-0000-0000-0000-000000000001';
	private const IOT = 'c1000000-0000-0000-0000-000000000001';
	private const IOT_V2 = 'c1000000-0000-0000-0000-000000000002';

	/**
	 * A ZgwService mock: every Catalogi resource is mapped onto schema = resource name.
	 *
	 * @param object|null $objectService The ObjectService, or null
	 * @param array       $unmapped      Resources without a mapping
	 *
	 * @return ZgwService
	 */
	private function zgw(?object $objectService, array $unmapped=[]): ZgwService {
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getObjectService')->willReturn($objectService);
		$zgw->method('loadMappingConfig')->willReturnCallback(
			static function (string $api, string $resource) use ($unmapped): ?array {
				if (in_array($resource, $unmapped, true) === true) {
					return null;
				}

				return ['sourceRegister' => 'r', 'sourceSchema' => $resource];
			}
		);

		return $zgw;

	}//end zgw()

	/**
	 * An ObjectService: finds answer "<schema>:<id>", searches answer "<schema>:<field>=<value>".
	 *
	 * @param array $finds    Objects by "<schema>:<id>"; a Throwable value is thrown
	 * @param array $searches Result rows by "<schema>:<field>=<value>"
	 *
	 * @return ZtcCrossRefObjectServiceStub
	 */
	private function objects(array $finds, array $searches=[]): ZtcCrossRefObjectServiceStub {
		$service = $this->createMock(ZtcCrossRefObjectServiceStub::class);
		$service->method('find')->willReturnCallback(
			static function (mixed $id, string $register, string $schema) use ($finds): mixed {
				$found = $finds[$schema.':'.$id] ?? null;
				if ($found instanceof \Throwable) {
					throw $found;
				}

				if ($found === null) {
					throw new \RuntimeException('not found');
				}

				return $found;
			}
		);
		$service->method('buildSearchQuery')->willReturnCallback(
			static fn (array $requestParams, string $register, string $schema): array => ['schema' => $schema, 'params' => $requestParams]
		);
		$service->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use ($searches): array {
				$params = $query['params'];
				unset($params['_limit']);
				$key = $query['schema'].':'.array_key_first($params).'='.reset($params);
				return ['results' => $searches[$key] ?? []];
			}
		);

		return $service;

	}//end objects()

	/**
	 * A besluittype gets its stored documentTypes and caseTypes as URLs (array or JSON string).
	 *
	 * @return void
	 */
	public function testBesluittypeListsComeFromItsStoredUuids(): void {
		$objects = $this->objects(
			finds: ['besluittypen:'.self::BT => ['documentTypes' => json_encode([self::IOT, '', 7]), 'caseTypes' => [self::ZT]]]
		);

		$data = (new ZtcCrossReferenceEnricher(zgwService: $this->zgw(objectService: $objects)))
			->enrich(resource: 'besluittypen', data: ['uuid' => self::BT], baseUrl: self::BASE);

		$this->assertSame([self::BASE.'/informatieobjecttypen/'.self::IOT], $data['informatieobjecttypen']);
		$this->assertSame([self::BASE.'/zaaktypen/'.self::ZT], $data['zaaktypen']);

	}//end testBesluittypeListsComeFromItsStoredUuids()

	/**
	 * A besluittype that cannot be read, or stores nothing, is left as it was.
	 *
	 * @return void
	 */
	public function testBesluittypeWithoutStoredListsIsUnchanged(): void {
		$enricher = new ZtcCrossReferenceEnricher(zgwService: $this->zgw(objectService: $this->objects(finds: ['besluittypen:'.self::BT => []])));

		$this->assertSame(['uuid' => self::BT], $enricher->enrich(resource: 'besluittypen', data: ['uuid' => self::BT], baseUrl: self::BASE));
		$this->assertSame(['uuid' => 'other'], $enricher->enrich(resource: 'besluittypen', data: ['uuid' => 'other'], baseUrl: self::BASE));

	}//end testBesluittypeWithoutStoredListsIsUnchanged()

	/**
	 * Without an ObjectService, a uuid, or a known resource nothing is enriched, and no list defaults are added.
	 *
	 * @return void
	 */
	public function testNothingToEnrichPassesThrough(): void {
		$none = new ZtcCrossReferenceEnricher(zgwService: $this->zgw(objectService: null));
		$some = new ZtcCrossReferenceEnricher(zgwService: $this->zgw(objectService: $this->objects(finds: [])));

		$this->assertSame(['uuid' => self::ZT], $none->enrich(resource: 'zaaktypen', data: ['uuid' => self::ZT], baseUrl: self::BASE));
		$this->assertSame([], $some->enrich(resource: 'zaaktypen', data: [], baseUrl: self::BASE));
		$this->assertSame(['uuid' => self::ZT], $some->enrich(resource: 'statustypen', data: ['uuid' => self::ZT], baseUrl: self::BASE));

	}//end testNothingToEnrichPassesThrough()

	/**
	 * A zaaktype gets every list: deelzaaktypen through shared identifiers, stored besluittypen,
	 * gerelateerdeZaaktypen expanded and deduplicated, informatieobjecttypen through its ZIOTs
	 * and shared names, and its sub-resources.
	 *
	 * @return void
	 */
	public function testZaaktypeGetsEveryList(): void {
		$objects = $this->objects(
			finds: [
				'zaaktypen:'.self::ZT                        => [
					'subCaseTypes'     => [self::ZT_DEEL, '', 'broken'],
					'decisionTypes'    => [self::BT],
					'relatedCaseTypes' => json_encode(
						[
							['caseType' => self::ZT_DEEL, 'aardRelatie' => 'vervolg'],
							['caseType' => self::BASE.'/zaaktypen/'.self::ZT_DEEL, 'aardRelatie' => 'dubbel'],
							['caseType' => 'https://elders/zaaktypen/x', 'aardRelatie' => 'extern'],
							['caseType' => ''],
						]
					),
				],
				'zaaktypen:'.self::ZT_DEEL                   => ['identifier' => 'ZT-DEEL'],
				'zaaktypen:broken'                           => new \RuntimeException('gone'),
				'informatieobjecttypen:'.self::IOT           => ['name' => 'Brief'],
			],
			searches: [
				'zaaktypen:identifier=ZT-DEEL'                       => [['id' => self::ZT_DEEL], ['@self' => ['id' => self::ZT_DEEL_V2]], ['id' => '']],
				'zaaktype-informatieobjecttypen:caseType='.self::ZT => [['informatieobjecttype' => self::IOT], ['informatieobjecttype' => '']],
				'informatieobjecttypen:name=Brief'                   => [['id' => self::IOT], ['id' => self::IOT_V2]],
				'statustypen:caseType='.self::ZT                     => [['id' => 's1'], ['id' => 's2']],
				'besluittypen:caseType='.self::ZT                    => [['id' => 'never-used']],
			]
		);

		$data = (new ZtcCrossReferenceEnricher(zgwService: $this->zgw(objectService: $objects)))
			->enrich(resource: 'zaaktypen', data: ['uuid' => self::ZT], baseUrl: self::BASE);

		$this->assertSame(
			[self::BASE.'/zaaktypen/'.self::ZT_DEEL, self::BASE.'/zaaktypen/'.self::ZT_DEEL_V2, self::BASE.'/zaaktypen/broken'],
			$data['deelzaaktypen']
		);
		$this->assertSame([self::BASE.'/besluittypen/'.self::BT], $data['besluittypen'], 'stored besluittypen win over the fallback search');
		$this->assertSame(
			[
				['caseType' => self::BASE.'/zaaktypen/'.self::ZT_DEEL, 'aardRelatie' => 'vervolg'],
				['caseType' => self::BASE.'/zaaktypen/'.self::ZT_DEEL_V2, 'aardRelatie' => 'vervolg'],
				['caseType' => 'https://elders/zaaktypen/x', 'aardRelatie' => 'extern'],
			],
			$data['gerelateerdeZaaktypen']
		);
		$this->assertSame(
			[self::BASE.'/informatieobjecttypen/'.self::IOT, self::BASE.'/informatieobjecttypen/'.self::IOT_V2],
			$data['informatieobjecttypen']
		);
		$this->assertSame([self::BASE.'/statustypen/s1', self::BASE.'/statustypen/s2'], $data['statustypen']);
		$this->assertSame([], $data['roltypen'], 'an empty list defaults to [] rather than null');
		$this->assertSame([], $data['eigenschappen']);

	}//end testZaaktypeGetsEveryList()

	/**
	 * When the zaaktype stores no besluittypen, they come from the besluittypen pointing at it;
	 * an unreadable zaaktype still gets its mapped relations and sub-resources.
	 *
	 * @return void
	 */
	public function testZaaktypeFallbacks(): void {
		$objects = $this->objects(
			finds: [
				'zaaktypen:'.self::ZT              => new \RuntimeException('unreadable'),
				'informatieobjecttypen:'.self::IOT => new \RuntimeException('unreadable'),
			],
			searches: [
				'besluittypen:caseType='.self::ZT                    => [['id' => self::BT]],
				'zaaktype-informatieobjecttypen:caseType='.self::ZT => [['informatieobjecttype' => self::IOT]],
			]
		);

		$data = (new ZtcCrossReferenceEnricher(zgwService: $this->zgw(objectService: $objects)))->enrich(
			resource: 'zaaktypen',
			data: ['uuid' => self::ZT, 'gerelateerdeZaaktypen' => [['caseType' => 'ZT-UNREADABLE']]],
			baseUrl: self::BASE
		);

		$this->assertSame([self::BASE.'/besluittypen/'.self::BT], $data['besluittypen']);
		$this->assertSame([self::BASE.'/informatieobjecttypen/'.self::IOT], $data['informatieobjecttypen'], 'an unreadable IOT stands in with its own URL');
		$this->assertSame([['caseType' => self::BASE.'/zaaktypen/ZT-UNREADABLE']], $data['gerelateerdeZaaktypen']);
		$this->assertSame([], $data['deelzaaktypen']);

	}//end testZaaktypeFallbacks()

	/**
	 * Without the zaaktypen mapping, the stored lists and the relations are not built.
	 *
	 * @return void
	 */
	public function testUnmappedZaaktypenBuildsNoStoredLists(): void {
		$zgw = $this->zgw(objectService: $this->objects(finds: []), unmapped: ['zaaktypen', 'zaaktype-informatieobjecttypen', 'besluittypen']);

		$data = (new ZtcCrossReferenceEnricher(zgwService: $zgw))->enrich(
			resource: 'zaaktypen',
			data: ['uuid' => self::ZT, 'gerelateerdeZaaktypen' => [['caseType' => 'ZT-1']]],
			baseUrl: self::BASE
		);

		$this->assertSame([['caseType' => 'ZT-1']], $data['gerelateerdeZaaktypen']);
		$this->assertSame([], $data['besluittypen']);

	}//end testUnmappedZaaktypenBuildsNoStoredLists()

	/**
	 * Only URLs to published types valid today stay; nested relations are judged by their caseType.
	 *
	 * @return void
	 */
	public function testValidityFilterKeepsOnlyPublishedCurrentTypes(): void {
		$objects = $this->objects(
			finds: [
				'informatieobjecttypen:'.self::IOT    => ['isDraft' => false, 'validFrom' => '2026-01-01', 'validUntil' => '2026-12-31'],
				'informatieobjecttypen:'.self::IOT_V2 => ['isDraft' => false, 'validFrom' => '2027-01-01'],
				'zaaktypen:'.self::ZT                 => ['draft' => 'false', 'endValidity' => ''],
				'zaaktypen:'.self::ZT_DEEL            => ['isDraft' => 1],
				'zaaktypen:'.self::ZT_DEEL_V2         => ['isDraft' => '0', 'validUntil' => '2026-01-01'],
			]
		);
		$filter = new ZtcUrlValidityFilter(zgwService: $this->zgw(objectService: $objects));

		$data = $filter->filter(
			fieldConfigs: [
				'informatieobjecttypen' => ['schemaKey' => 'document_type_schema', 'nested' => false],
				'deelzaaktypen'         => ['schemaKey' => 'case_type_schema', 'nested' => false],
				'gerelateerdeZaaktypen' => ['schemaKey' => 'case_type_schema', 'nested' => true],
				'missing'               => ['schemaKey' => 'case_type_schema', 'nested' => false],
				'other'                 => ['schemaKey' => 'unknown_schema', 'nested' => false],
			],
			data: [
				'informatieobjecttypen' => ['x/'.self::IOT, 'x/'.self::IOT_V2, 'geen-uuid', 5],
				'deelzaaktypen'         => ['x/'.self::ZT, 'x/'.self::ZT_DEEL, 'x/'.self::ZT_DEEL_V2, 'x/'.self::BT],
				'gerelateerdeZaaktypen' => [['caseType' => 'x/'.self::ZT], ['caseType' => 'x/'.self::ZT_DEEL], ['aardRelatie' => 'zonder']],
				'other'                 => ['x/'.self::BT],
			],
			today: '2026-06-15'
		);

		$this->assertSame(['x/'.self::IOT], $data['informatieobjecttypen']);
		$this->assertSame(['x/'.self::ZT], $data['deelzaaktypen'], 'a concept, an ended and an unreadable type go');
		$this->assertSame([['caseType' => 'x/'.self::ZT]], $data['gerelateerdeZaaktypen']);
		$this->assertArrayNotHasKey('missing', $data);
		$this->assertSame(['x/'.self::BT], $data['other'], 'an unknown schema key is not judged');

	}//end testValidityFilterKeepsOnlyPublishedCurrentTypes()

	/**
	 * Without field configs, an ObjectService or a mapping, URLs are not judged.
	 *
	 * @return void
	 */
	public function testValidityFilterWithoutMeansToJudgeKeepsEverything(): void {
		$data = ['informatieobjecttypen' => ['x/'.self::IOT]];
		$configs = ['informatieobjecttypen' => ['schemaKey' => 'document_type_schema', 'nested' => false]];

		$this->assertSame($data, (new ZtcUrlValidityFilter(zgwService: $this->zgw(objectService: null)))->filter(fieldConfigs: $configs, data: $data, today: '2026-06-15'));
		$this->assertSame($data, (new ZtcUrlValidityFilter(zgwService: $this->zgw(objectService: $this->objects(finds: []))))->filter(fieldConfigs: [], data: $data, today: '2026-06-15'));
		$this->assertSame(
			$data,
			(new ZtcUrlValidityFilter(zgwService: $this->zgw(objectService: $this->objects(finds: []), unmapped: ['informatieobjecttypen'])))
				->filter(fieldConfigs: $configs, data: $data, today: '2026-06-15')
		);

	}//end testValidityFilterWithoutMeansToJudgeKeepsEverything()
}//end class
