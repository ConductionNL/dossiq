<?php

/**
 * Unit tests for ZtcUrlValidityFilter: list fields of catalogue URLs keep only
 * URLs whose target type is published and valid today, and a lookup failure
 * drops the URL.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter
 *
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 */
class ZtcUrlValidityFilterTest extends TestCase {

	private const CURRENT = '11111111-1111-4111-8111-111111111111';

	private const DRAFT = '22222222-2222-4222-8222-222222222222';

	private const FUTURE = '33333333-3333-4333-8333-333333333333';

	private const ENDED = '44444444-4444-4444-8444-444444444444';

	private const BROKEN = '55555555-5555-4555-8555-555555555555';

	private const OPEN_ENDED = '66666666-6666-4666-8666-666666666666';

	private ZgwService&MockObject $zgw;

	/** @var list<array{string, string, string}> */
	private array $finds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->zgw = $this->createMock(ZgwService::class);
	}//end setUp()

	private function withStore(): void {
		$rows = [
			self::CURRENT => ['isDraft' => false, 'validFrom' => '2026-01-01', 'validUntil' => '2026-12-31'],
			self::DRAFT => ['isDraft' => 'true', 'validFrom' => '2026-01-01'],
			self::FUTURE => ['draft' => false, 'startValidity' => '2026-07-01'],
			self::ENDED => ['isDraft' => 0, 'endValidity' => '2026-05-31'],
			self::OPEN_ENDED => ['isDraft' => false, 'validFrom' => '', 'validUntil' => ''],
		];
		$finds = &$this->finds;
		$service = new class($rows, $finds) {
			public function __construct(private array $rows, private array &$finds) {
			}

			public function find(string $id, string $register, string $schema): array {
				$this->finds[] = [$id, $register, $schema];
				if (isset($this->rows[$id]) === false) {
					throw new RuntimeException('not found');
				}

				return $this->rows[$id];
			}
		};
		$this->zgw->method('getObjectService')->willReturn($service);
		$this->zgw->method('loadMappingConfig')->willReturnCallback(
			static fn (string $api, string $resource): ?array => $resource === 'besluittypen' ? null : ['sourceRegister' => '7', 'sourceSchema' => 'schema-' . $resource]
		);
	}//end withStore()

	private function url(string $uuid): string {
		return 'https://zgw.example/catalogi/api/v1/informatieobjecttypen/' . $uuid;
	}//end url()

	public function testOnlyPublishedAndCurrentTypesStay(): void {
		$this->withStore();
		$data = [
			'informatieobjecttypen' => [
				$this->url(self::CURRENT),
				$this->url(self::DRAFT),
				$this->url(self::FUTURE),
				$this->url(self::ENDED),
				$this->url(self::BROKEN),
				$this->url(self::OPEN_ENDED),
				'https://zgw.example/no-uuid',
				42,
			],
			'omschrijving' => 'untouched',
		];

		$filtered = (new ZtcUrlValidityFilter($this->zgw))->filter(
			['informatieobjecttypen' => ['nested' => false, 'schemaKey' => 'document_type_schema']],
			$data,
			'2026-06-15'
		);

		$this->assertSame([$this->url(self::CURRENT), $this->url(self::OPEN_ENDED)], $filtered['informatieobjecttypen']);
		$this->assertSame('untouched', $filtered['omschrijving']);
		$this->assertContains([self::CURRENT, '7', 'schema-informatieobjecttypen'], $this->finds);
	}//end testOnlyPublishedAndCurrentTypesStay()

	public function testTheBoundaryDaysThemselvesAreValid(): void {
		$this->withStore();
		$filter = new ZtcUrlValidityFilter($this->zgw);
		$config = ['types' => ['nested' => false, 'schemaKey' => 'document_type_schema']];

		$this->assertSame([$this->url(self::FUTURE)], $filter->filter($config, ['types' => [$this->url(self::FUTURE)]], '2026-07-01')['types']);
		$this->assertSame([$this->url(self::ENDED)], $filter->filter($config, ['types' => [$this->url(self::ENDED)]], '2026-05-31')['types']);
	}//end testTheBoundaryDaysThemselvesAreValid()

	public function testNestedItemsAreJudgedByTheirCaseType(): void {
		$this->withStore();
		$current = ['caseType' => $this->url(self::CURRENT), 'aardRelatie' => 'vervolg'];

		$filtered = (new ZtcUrlValidityFilter($this->zgw))->filter(
			['gerelateerdeZaaktypen' => ['nested' => true, 'schemaKey' => 'case_type_schema']],
			['gerelateerdeZaaktypen' => [$current, ['caseType' => $this->url(self::DRAFT)], ['aardRelatie' => 'bijdrage']]],
			'2026-06-15'
		);

		$this->assertSame([$current], $filtered['gerelateerdeZaaktypen']);
	}//end testNestedItemsAreJudgedByTheirCaseType()

	public function testAUrlIsKeptUnlookedWhenItsResourceHasNoMappingOrIsUnknown(): void {
		$this->withStore();
		$filter = new ZtcUrlValidityFilter($this->zgw);
		$broken = $this->url(self::BROKEN);

		$this->assertSame([$broken], $filter->filter(['besluittypen' => ['nested' => false, 'schemaKey' => 'decision_type_schema']], ['besluittypen' => [$broken]], '2026-06-15')['besluittypen']);
		$this->assertSame([$broken], $filter->filter(['other' => ['nested' => false, 'schemaKey' => 'status_type_schema']], ['other' => [$broken]], '2026-06-15')['other']);
		$this->assertSame([], $this->finds);
	}//end testAUrlIsKeptUnlookedWhenItsResourceHasNoMappingOrIsUnknown()

	public function testNothingIsFilteredWithoutConfigOrAStoreOrAList(): void {
		$data = ['types' => [$this->url(self::DRAFT)], 'single' => $this->url(self::DRAFT)];
		$this->zgw->method('getObjectService')->willReturn(null);
		$filter = new ZtcUrlValidityFilter($this->zgw);

		$this->assertSame($data, $filter->filter([], $data, '2026-06-15'));
		$this->assertSame($data, $filter->filter(['types' => ['nested' => false, 'schemaKey' => 'document_type_schema']], $data, '2026-06-15'));
	}//end testNothingIsFilteredWithoutConfigOrAStoreOrAList()

	public function testAScalarFieldIsLeftAlone(): void {
		$this->withStore();
		$data = ['single' => $this->url(self::DRAFT)];

		$this->assertSame($data, (new ZtcUrlValidityFilter($this->zgw))->filter(['single' => ['nested' => false, 'schemaKey' => 'document_type_schema'], 'absent' => ['nested' => false, 'schemaKey' => 'document_type_schema']], $data, '2026-06-15'));
	}//end testAScalarFieldIsLeftAlone()
}//end class
