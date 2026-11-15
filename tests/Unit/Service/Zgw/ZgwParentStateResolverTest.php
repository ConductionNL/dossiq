<?php

/**
 * Unit tests for ZgwParentStateResolver.
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

use OCA\Dossiq\Service\Zgw\ZgwParentStateResolver;
use OCA\Dossiq\Service\ZgwMappingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ObjectService shape the resolver calls.
 */
interface ParentStateObjectServiceStub {
	public function find(mixed $id, string $register, string $schema): mixed;
}//end interface

/**
 * Tests for the parent zaak and parent zaaktype answers.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZgwParentStateResolver
 */
class ZgwParentStateResolverTest extends TestCase {

	private const CASE_UUID = 'a0000000-1111-2222-3333-444455556666';
	private const ZT_UUID = 'b0000000-1111-2222-3333-444455556666';

	/**
	 * Ids the ObjectService was asked for.
	 *
	 * @var array<mixed>
	 */
	private array $asked = [];

	/**
	 * Build the resolver; mappings resolve unless switched off.
	 *
	 * @param bool                      $mapped Whether getMapping answers
	 * @param LoggerInterface|null      $logger The logger
	 *
	 * @return ZgwParentStateResolver
	 */
	private function resolver(bool $mapped=true, ?LoggerInterface $logger=null): ZgwParentStateResolver {
		$mapping = $this->createMock(ZgwMappingService::class);
		$mapping->method('getMapping')->willReturn(
			$mapped === true ? ['sourceRegister' => '1', 'sourceSchema' => '2'] : null
		);

		return new ZgwParentStateResolver(
			zgwMappingService: $mapping,
			logger: $logger ?? $this->createMock(LoggerInterface::class)
		);

	}//end resolver()

	/**
	 * An ObjectService answering from a uuid map, or throwing.
	 *
	 * @param array<string, mixed> $objects Objects by id
	 * @param bool                 $throws  Whether find throws
	 *
	 * @return ParentStateObjectServiceStub
	 */
	private function objects(array $objects, bool $throws=false): ParentStateObjectServiceStub {
		$service = $this->createMock(ParentStateObjectServiceStub::class);
		$service->method('find')->willReturnCallback(
			function (mixed $id, string $register, string $schema) use ($objects, $throws): mixed {
				$this->asked[] = $id;
				if ($throws === true) {
					throw new \RuntimeException('register down');
				}

				return $objects[$id] ?? null;
			}
		);

		return $service;

	}//end objects()

	/**
	 * A zaak itself is closed when it carries an einddatum, without any lookup.
	 *
	 * @return void
	 */
	public function testZaakItselfReadsItsOwnEndDate(): void {
		$resolver = $this->resolver();

		$this->assertTrue($resolver->resolveZaakClosed(objectService: null, resource: 'zaken', existingData: ['endDate' => '2026-01-01']));
		$this->assertFalse($resolver->resolveZaakClosed(objectService: null, resource: 'zaken', existingData: ['endDate' => '']));
		$this->assertFalse($resolver->resolveZaakClosed(objectService: null, resource: 'zaken', existingData: []));

	}//end testZaakItselfReadsItsOwnEndDate()

	/**
	 * A sub-resource reads its zaak's einddatum, through the uuid in its zaak URL.
	 *
	 * @return void
	 */
	public function testSubResourceReadsItsZaak(): void {
		$resolver = $this->resolver();
		$closed   = $this->objects(objects: [self::CASE_UUID => ['endDate' => '2026-01-01']]);
		$open     = $this->objects(objects: [self::CASE_UUID => new class implements \JsonSerializable {
			/**
			 * Serialise as an open zaak.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['endDate' => null];
			}//end jsonSerialize()
		},
		]);

		$this->assertTrue($resolver->resolveZaakClosed(objectService: $closed, resource: 'statussen', existingData: ['case' => 'https://x/zaken/'.self::CASE_UUID]));
		$this->assertFalse($resolver->resolveZaakClosed(objectService: $open, resource: 'rollen', existingData: ['zaak' => self::CASE_UUID]));
		$this->assertSame([self::CASE_UUID, self::CASE_UUID], $this->asked);

	}//end testSubResourceReadsItsZaak()

	/**
	 * Not a zaak sub-resource, no zaak reference, no mapping or no zaak: not applicable.
	 *
	 * @return void
	 */
	public function testZaakClosedNotApplicable(): void {
		$objects = $this->objects(objects: []);

		$this->assertNull($this->resolver()->resolveZaakClosed(objectService: $objects, resource: 'besluiten', existingData: ['case' => self::CASE_UUID]));
		$this->assertNull($this->resolver()->resolveZaakClosed(objectService: $objects, resource: 'statussen', existingData: ['case' => '']));
		$this->assertNull($this->resolver(mapped: false)->resolveZaakClosed(objectService: null, resource: 'statussen', existingData: ['case' => self::CASE_UUID]));
		$this->assertNull($this->resolver()->resolveZaakClosed(objectService: $objects, resource: 'statussen', existingData: ['case' => self::CASE_UUID]));

	}//end testZaakClosedNotApplicable()

	/**
	 * A reference without a uuid is looked up as it is.
	 *
	 * @return void
	 */
	public function testReferenceWithoutUuidIsLookedUpAsIs(): void {
		$this->resolver()->resolveZaakClosed(objectService: $this->objects(objects: []), resource: 'statussen', existingData: ['case' => 'zaak-7']);

		$this->assertSame(['zaak-7'], $this->asked);

	}//end testReferenceWithoutUuidIsLookedUpAsIs()

	/**
	 * A failing lookup, or a missing ObjectService, reads as closed and is logged as an error.
	 *
	 * @return void
	 */
	public function testZaakClosedFailsClosed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('error');
		$resolver = $this->resolver(logger: $logger);

		$this->assertTrue($resolver->resolveZaakClosed(objectService: $this->objects(objects: [], throws: true), resource: 'statussen', existingData: ['case' => self::CASE_UUID]));
		$this->assertTrue($resolver->resolveZaakClosedFromBody(objectService: null, resource: 'statussen', body: ['case' => self::CASE_UUID]));

	}//end testZaakClosedFailsClosed()

	/**
	 * From a body: only a zaak sub-resource with a uuid in its zaak URL is looked up.
	 *
	 * @return void
	 */
	public function testZaakClosedFromBody(): void {
		$resolver = $this->resolver();
		$objects  = $this->objects(objects: [self::CASE_UUID => ['endDate' => '2026-01-01']]);

		$this->assertNull($resolver->resolveZaakClosedFromBody(objectService: $objects, resource: 'zaken', body: ['case' => self::CASE_UUID]));
		$this->assertNull($resolver->resolveZaakClosedFromBody(objectService: $objects, resource: 'statussen', body: []));
		$this->assertNull($resolver->resolveZaakClosedFromBody(objectService: $objects, resource: 'statussen', body: ['case' => 'https://x/zaken/geen-uuid']));
		$this->assertTrue($resolver->resolveZaakClosedFromBody(objectService: $objects, resource: 'resultaten', body: ['case' => 'https://x/zaken/'.self::CASE_UUID]));

	}//end testZaakClosedFromBody()

	/**
	 * A type reads its zaaktype's concept flag; every published spelling reads as published.
	 *
	 * @return void
	 */
	public function testParentZaaktypeDraft(): void {
		$resolver = $this->resolver();
		foreach ([false, 'false', '0', 0] as $published) {
			$objects = $this->objects(objects: [self::ZT_UUID => ['isDraft' => $published]]);
			$this->assertFalse($resolver->resolveParentZaaktypeDraft(objectService: $objects, resource: 'statustypen', existingData: ['caseType' => self::ZT_UUID]));
		}

		$concept = $this->objects(objects: [self::ZT_UUID => ['concept' => true]]);
		$unset   = $this->objects(objects: [self::ZT_UUID => []]);
		$this->assertTrue($resolver->resolveParentZaaktypeDraft(objectService: $concept, resource: 'roltypen', existingData: ['caseType' => 'https://x/zaaktypen/'.self::ZT_UUID]));
		$this->assertTrue($resolver->resolveParentZaaktypeDraft(objectService: $unset, resource: 'eigenschappen', existingData: ['caseType' => self::ZT_UUID]));

	}//end testParentZaaktypeDraft()

	/**
	 * The concept answer is not applicable outside ZTC sub-resources, and a failure reads as not applicable.
	 *
	 * @return void
	 */
	public function testParentZaaktypeDraftNotApplicableAndFailsOpen(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');
		$resolver = $this->resolver(logger: $logger);
		$objects  = $this->objects(objects: []);

		$this->assertNull($resolver->resolveParentZaaktypeDraft(objectService: $objects, resource: 'zaaktypen', existingData: ['caseType' => self::ZT_UUID]));
		$this->assertNull($resolver->resolveParentZaaktypeDraft(objectService: $objects, resource: 'statustypen', existingData: []));
		$this->assertNull($resolver->resolveParentZaaktypeDraft(objectService: $objects, resource: 'statustypen', existingData: ['caseType' => self::ZT_UUID]));
		$this->assertNull($resolver->resolveParentZaaktypeDraft(objectService: $this->objects(objects: [], throws: true), resource: 'statustypen', existingData: ['caseType' => self::ZT_UUID]));
		$this->assertNull($resolver->resolveParentZaaktypeDraftFromBody(objectService: null, resource: 'statustypen', body: ['caseType' => self::ZT_UUID]));

	}//end testParentZaaktypeDraftNotApplicableAndFailsOpen()

	/**
	 * From a body: only a ZTC sub-resource with a uuid in its zaaktype reference is looked up.
	 *
	 * @return void
	 */
	public function testParentZaaktypeDraftFromBody(): void {
		$resolver = $this->resolver();
		$objects  = $this->objects(objects: [self::ZT_UUID => ['isDraft' => false]]);

		$this->assertNull($resolver->resolveParentZaaktypeDraftFromBody(objectService: $objects, resource: 'besluittypen', body: ['caseType' => self::ZT_UUID]));
		$this->assertNull($resolver->resolveParentZaaktypeDraftFromBody(objectService: $objects, resource: 'statustypen', body: ['caseType' => '']));
		$this->assertNull($resolver->resolveParentZaaktypeDraftFromBody(objectService: $objects, resource: 'statustypen', body: ['caseType' => 'ZT-1']));
		$this->assertNull($this->resolver(mapped: false)->resolveParentZaaktypeDraftFromBody(objectService: $objects, resource: 'statustypen', body: ['caseType' => self::ZT_UUID]));
		$this->assertFalse($resolver->resolveParentZaaktypeDraftFromBody(objectService: $objects, resource: 'zaaktype-informatieobjecttypen', body: ['caseType' => self::ZT_UUID]));

	}//end testParentZaaktypeDraftFromBody()
}//end class
