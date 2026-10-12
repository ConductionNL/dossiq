<?php

/**
 * Characterisation tests for ZgwDrcRulesService.
 *
 * Pins the document create rules (drc-001, drc-005, drc-006, drc-008) and the
 * ObjectInformatieObject create rules (drc-002, drc-003, drc-004) through the public rules
 * methods, so their decomposition (method-decomposition) can be proven to change no outcome.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwDrcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ObjectService shape the DRC rules call with named arguments.
 */
interface DrcObjectServiceStub {
	public function find(string $id, string $register, string $schema): ?array;

	public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

	public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array;
}//end interface

/**
 * Characterisation tests for the DRC rules.
 *
 * @covers \OCA\Dossiq\Service\ZgwDrcRulesService
 * @covers \OCA\Dossiq\Service\ZgwRulesBase
 *
 * @uses \OCA\Dossiq\Service\FieldValidator
 */
class ZgwDrcRulesServiceTest extends TestCase {

	private const IOT_UUID = '70000000-1111-2222-3333-444455556666';
	private const IO_UUID = '71000000-1111-2222-3333-444455556666';
	private const CASE_UUID = '72000000-1111-2222-3333-444455556666';

	private const IOT_URL = 'https://ztc.example.com/informatieobjecttypen/'.self::IOT_UUID;
	private const IO_URL = 'https://drc.example.com/enkelvoudiginformatieobjecten/'.self::IO_UUID;
	private const CASE_URL = 'https://zrc.example.com/zaken/'.self::CASE_UUID;

	/**
	 * The total every search reports.
	 *
	 * @var integer
	 */
	private int $searchTotal = 0;

	/**
	 * The search results every uniqueness search returns.
	 *
	 * @var array
	 */
	private array $searchResults = [];

	/**
	 * Answers a search from its query when set; the query carries its schema under `@schema`.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $searchHandler = null;

	/**
	 * Build the service with an ObjectService answering from a uuid map.
	 *
	 * @param array<string, array>|null $objects Objects by uuid, or null for no context
	 *
	 * @return ZgwDrcRulesService
	 */
	private function buildService(?array $objects, array $mappingConfig=['sourceRegister' => '1', 'sourceSchema' => '2']): ZgwDrcRulesService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $key);

		$service = new ZgwDrcRulesService(
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settings,
			fieldValidator: new FieldValidator()
		);

		if ($objects !== null) {
			$objectService = $this->createMock(DrcObjectServiceStub::class);
			$objectService->method('find')->willReturnCallback(
				static function (string $id, string $register, string $schema) use ($objects): ?array {
					return $objects[$id] ?? null;
				}
			);
			$objectService->method('buildSearchQuery')->willReturnCallback(
				static fn (array $requestParams, string $register, string $schema): array => ($requestParams + ['@schema' => $schema])
			);
			$objectService->method('searchObjectsPaginated')->willReturnCallback(
				function (array $query, bool $_rbac=true, bool $_multitenancy=true): array {
					if ($this->searchHandler !== null) {
						return ($this->searchHandler)($query);
					}

					return [
						'total'   => $this->searchTotal,
						'results' => $this->searchResults,
					];
				}
			);
			$service->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
		}

		return $service;

	}//end buildService()

	/**
	 * A published informatieobjecttype with a confidentiality.
	 *
	 * @param array $extra Extra fields
	 *
	 * @return array<string, array>
	 */
	private function iotWorld(array $extra=[]): array {
		return [self::IOT_UUID => array_merge(['id' => self::IOT_UUID, 'isDraft' => false, 'confidentiality' => 'zaakvertrouwelijk'], $extra)];

	}//end iotWorld()

	/**
	 * Drc-006a and drc-008: without a context an absent or false indicatie becomes null and an identificatie is generated.
	 *
	 * @return void
	 */
	public function testCreateDefaultsWithoutContext(): void {
		$service = $this->buildService(objects: null);

		$absent = $service->rulesEnkelvoudiginformatieobjectenCreate(body: []);
		$false  = $service->rulesEnkelvoudiginformatieobjectenCreate(body: ['indicatieGebruiksrecht' => false]);
		$true   = $service->rulesEnkelvoudiginformatieobjectenCreate(body: ['indicatieGebruiksrecht' => true]);

		$this->assertTrue($absent['valid']);
		$this->assertArrayHasKey('indicatieGebruiksrecht', $absent['enrichedBody']);
		$this->assertNull($absent['enrichedBody']['indicatieGebruiksrecht']);
		$this->assertStringStartsWith('DOCUMENT', $absent['enrichedBody']['identificatie']);
		$this->assertNull($false['enrichedBody']['indicatieGebruiksrecht']);
		$this->assertTrue($true['valid']);
		$this->assertTrue($true['enrichedBody']['indicatieGebruiksrecht']);

	}//end testCreateDefaultsWithoutContext()

	/**
	 * Drc-001: a draft informatieobjecttype is refused.
	 *
	 * @return void
	 */
	public function testDraftInformatieobjecttypeIsRefused(): void {
		$result = $this->buildService(objects: $this->iotWorld(extra: ['isDraft' => true]))
			->rulesEnkelvoudiginformatieobjectenCreate(body: ['informatieobjecttype' => self::IOT_URL]);

		$this->assertFalse($result['valid']);
		$this->assertSame('not-published', $result['invalidParams'][0]['code']);

	}//end testDraftInformatieobjecttypeIsRefused()

	/**
	 * Drc-005: the confidentiality comes from the type when the body has none, and stays when it has one.
	 *
	 * @return void
	 */
	public function testConfidentialityIsDerivedOnlyWhenAbsent(): void {
		$service = $this->buildService(objects: $this->iotWorld());

		$derived = $service->rulesEnkelvoudiginformatieobjectenCreate(body: ['informatieobjecttype' => self::IOT_URL]);
		$kept    = $service->rulesEnkelvoudiginformatieobjectenCreate(
			body: ['informatieobjecttype' => self::IOT_URL, 'vertrouwelijkheidaanduiding' => 'openbaar']
		);

		$this->assertSame('zaakvertrouwelijk', $derived['enrichedBody']['vertrouwelijkheidaanduiding']);
		$this->assertSame('openbaar', $kept['enrichedBody']['vertrouwelijkheidaanduiding']);

	}//end testConfidentialityIsDerivedOnlyWhenAbsent()

	/**
	 * Drc-006b: with a context, indicatieGebruiksrecht true on create is refused.
	 *
	 * @return void
	 */
	public function testIndicatieGebruiksrechtTrueIsRefusedWithContext(): void {
		$result = $this->buildService(objects: $this->iotWorld())
			->rulesEnkelvoudiginformatieobjectenCreate(body: ['indicatieGebruiksrecht' => true]);

		$this->assertFalse($result['valid']);
		$this->assertSame('indicatieGebruiksrecht kan niet true zijn zonder dat er gebruiksrechten bestaan.', $result['detail']);
		$this->assertSame('missing-gebruiksrechten', $result['invalidParams'][0]['code']);
		$this->assertSame('indicatieGebruiksrecht', $result['invalidParams'][0]['name']);

	}//end testIndicatieGebruiksrechtTrueIsRefusedWithContext()

	/**
	 * Drc-008: a taken identificatie is refused, a free one kept.
	 *
	 * @return void
	 */
	public function testIdentificatieUniqueness(): void {
		$service = $this->buildService(objects: $this->iotWorld());

		$free = $service->rulesEnkelvoudiginformatieobjectenCreate(body: ['identificatie' => 'D-1', 'bronorganisatie' => '123']);
		$this->searchResults = [['sourceOrganisation' => '123']];
		$taken = $service->rulesEnkelvoudiginformatieobjectenCreate(body: ['identificatie' => 'D-1', 'bronorganisatie' => '123']);

		$this->assertTrue($free['valid']);
		$this->assertSame('D-1', $free['enrichedBody']['identificatie']);
		$this->assertSame('identificatie-niet-uniek', $taken['invalidParams'][0]['code']);

	}//end testIdentificatieUniqueness()

	/**
	 * Drc-002: a bad informatieobject URL is refused before the object URL is read.
	 *
	 * @return void
	 */
	public function testBadInformatieobjectUrlIsRefused(): void {
		$result = $this->buildService(objects: null)->rulesObjectinformatieobjectenCreate(
			body: ['informatieobject' => 'geen-url', 'object' => 'ook-geen-url']
		);

		$this->assertSame('informatieobject', $result['invalidParams'][0]['name']);
		$this->assertSame('bad-url', $result['invalidParams'][0]['code']);

	}//end testBadInformatieobjectUrlIsRefused()

	/**
	 * Drc-002a-d: the object URL must hold a uuid and match the objectType.
	 *
	 * @return void
	 */
	public function testObjectUrlRules(): void {
		$service = $this->buildService(objects: null);

		$noUuid   = $service->rulesObjectinformatieobjectenCreate(body: ['object' => 'https://zrc.example.com/zaken']);
		$wrongCase = $service->rulesObjectinformatieobjectenCreate(
			body: ['object' => 'https://brc.example.com/besluiten/'.self::CASE_UUID, 'objectType' => 'case']
		);
		$wrongDecision = $service->rulesObjectinformatieobjectenCreate(
			body: ['object' => self::CASE_URL, 'objectType' => 'decision']
		);
		$fine = $service->rulesObjectinformatieobjectenCreate(
			body: ['informatieobject' => self::IO_URL, 'object' => self::CASE_URL, 'objectType' => 'case']
		);

		$this->assertSame('object', $noUuid['invalidParams'][0]['name']);
		$this->assertSame('bad-url', $noUuid['invalidParams'][0]['code']);
		$this->assertSame('De object URL wijst niet naar een zaak.', $wrongCase['detail']);
		$this->assertSame('invalid-resource', $wrongCase['invalidParams'][0]['code']);
		$this->assertSame('De object URL wijst niet naar een besluit.', $wrongDecision['detail']);
		$this->assertTrue($fine['valid']);

	}//end testObjectUrlRules()

	/**
	 * Drc-003: with a context, an existing relation is refused as a duplicate first.
	 *
	 * @return void
	 */
	public function testExistingRelationIsADuplicate(): void {
		$this->searchTotal = 1;
		$result = $this->buildService(objects: [])->rulesObjectinformatieobjectenCreate(
			body: ['informatieobject' => self::IO_URL, 'object' => self::CASE_URL, 'objectType' => 'case']
		);

		$this->assertSame('De combinatie informatieobject + object + objectType bestaat al.', $result['detail']);
		$this->assertSame('unique', $result['invalidParams'][0]['code']);

	}//end testExistingRelationIsADuplicate()

	/**
	 * Drc-004: with a context and no ZIO/BIO, the relation is inconsistent, worded per objectType.
	 *
	 * @return void
	 */
	public function testMissingCrossRegisterRelationIsInconsistent(): void {
		$service = $this->buildService(objects: []);

		$case = $service->rulesObjectinformatieobjectenCreate(
			body: ['informatieobject' => self::IO_URL, 'object' => self::CASE_URL, 'objectType' => 'case']
		);
		$decision = $service->rulesObjectinformatieobjectenCreate(
			body: [
				'informatieobject' => self::IO_URL,
				'object'           => 'https://brc.example.com/besluiten/'.self::CASE_UUID,
				'objectType'       => 'decision',
			]
		);

		$this->assertSame('Er bestaat geen ZaakInformatieObject in de Zaken API voor deze combinatie.', $case['detail']);
		$this->assertSame('inconsistent-relation', $case['invalidParams'][0]['code']);
		$this->assertSame('Er bestaat geen BesluitInformatieObject in de Besluiten API voor deze combinatie.', $decision['detail']);

	}//end testMissingCrossRegisterRelationIsInconsistent()

	/**
	 * Drc-004 is skipped without an objectType, and an unknown objectType checks nothing further.
	 *
	 * @return void
	 */
	public function testCrossRegisterNeedsAKnownObjectType(): void {
		$service = $this->buildService(objects: []);

		$noType = $service->rulesObjectinformatieobjectenCreate(
			body: ['informatieobject' => self::IO_URL, 'object' => self::CASE_URL]
		);
		$otherType = $service->rulesObjectinformatieobjectenCreate(
			body: ['informatieobject' => self::IO_URL, 'object' => self::CASE_URL, 'objectType' => 'verzoek']
		);

		$this->assertTrue($noType['valid']);
		$this->assertTrue($otherType['valid']);

	}//end testCrossRegisterNeedsAKnownObjectType()

	/**
	 * The body of an ObjectInformatieObject that links the document to the case.
	 *
	 * @return array<string, string>
	 */
	private function caseLink(): array {
		return ['informatieobject' => self::IO_URL, 'object' => self::CASE_URL, 'objectType' => 'case'];

	}//end caseLink()

	/**
	 * Drc-006: an unlocked document needs no lock, a locked one needs its own lock id, on PUT and on PATCH.
	 *
	 * @return void
	 */
	public function testLockRules(): void {
		$service = $this->buildService(objects: null);

		foreach (['rulesEnkelvoudiginformatieobjectenUpdate', 'rulesEnkelvoudiginformatieobjectenPatch'] as $rules) {
			$new       = $service->$rules(body: ['titel' => 'a']);
			$unlocked  = $service->$rules(body: ['titel' => 'a'], existingObject: ['locked' => '']);
			$notLocked = $service->$rules(body: ['titel' => 'a'], existingObject: ['locked' => false]);
			$missing   = $service->$rules(body: ['titel' => 'a'], existingObject: ['locked' => 'lock-1']);
			$wrong     = $service->$rules(body: ['lock' => 'lock-2'], existingObject: ['locked' => 'lock-1']);
			$right     = $service->$rules(body: ['lock' => 'lock-1', 'titel' => 'a'], existingObject: ['locked' => 'lock-1']);
			$oldField  = $service->$rules(body: ['lock' => 'lock-2'], existingObject: ['lock' => 'lock-1']);

			$this->assertTrue($new['valid'], $rules);
			$this->assertTrue($unlocked['valid'], $rules);
			$this->assertTrue($notLocked['valid'], $rules);
			$this->assertSame(400, $missing['status'], $rules);
			$this->assertSame('Het document is vergrendeld. Geef een geldig lock ID mee.', $missing['detail'], $rules);
			$this->assertSame('lock', $missing['invalidParams'][0]['name'], $rules);
			$this->assertSame('required', $missing['invalidParams'][0]['code'], $rules);
			$this->assertSame('Het opgegeven lock ID is niet geldig.', $wrong['detail'], $rules);
			$this->assertSame('incorrect-lock-id', $wrong['invalidParams'][0]['code'], $rules);
			$this->assertTrue($right['valid'], $rules);
			$this->assertSame(['lock' => 'lock-1', 'titel' => 'a'], $right['enrichedBody'], $rules);
			$this->assertSame('incorrect-lock-id', $oldField['invalidParams'][0]['code'], $rules);
		}

	}//end testLockRules()

	/**
	 * Drc-007 has nothing to check without a stored document, a context, an id or a register.
	 *
	 * @return void
	 */
	public function testDestroyWithNothingToCheck(): void {
		$this->searchResults = [['id' => 'oio-1']];

		$noDocument = $this->buildService(objects: [])->rulesEnkelvoudiginformatieobjectenDestroy(body: []);
		$noContext  = $this->buildService(objects: null)
			->rulesEnkelvoudiginformatieobjectenDestroy(body: [], existingObject: ['id' => self::IO_UUID]);
		$noId       = $this->buildService(objects: [])
			->rulesEnkelvoudiginformatieobjectenDestroy(body: [], existingObject: ['title' => 'x']);
		$noRegister = $this->buildService(objects: [], mappingConfig: [])
			->rulesEnkelvoudiginformatieobjectenDestroy(body: [], existingObject: ['id' => self::IO_UUID]);

		$this->assertTrue($noDocument['valid']);
		$this->assertTrue($noContext['valid']);
		$this->assertTrue($noId['valid']);
		$this->assertTrue($noRegister['valid']);

	}//end testDestroyWithNothingToCheck()

	/**
	 * Drc-007: a document with a relation is not deleted, whether the relation stores its uuid or its URL.
	 *
	 * @return void
	 */
	public function testDestroyIsRefusedWhileARelationExists(): void {
		$queries = [];
		$this->searchHandler = static function (array $query) use (&$queries): array {
			$queries[] = $query;
			return ['results' => [['id' => 'oio-1']]];
		};
		$byUuid = $this->buildService(objects: [])
			->rulesEnkelvoudiginformatieobjectenDestroy(body: [], existingObject: ['@self' => ['id' => self::IO_UUID]]);

		$this->searchHandler = static function (array $query) use (&$queries): array {
			$queries[] = $query;
			if ($query['document'] === self::IO_UUID) {
				return ['results' => []];
			}

			return ['results' => [new \ArrayObject(), ['@self' => ['id' => 'oio-2']], ['title' => 'no id']]];
		};
		$this->searchHandler = $this->serialisable(handler: $this->searchHandler);
		$byUrl = $this->buildService(objects: [])
			->rulesEnkelvoudiginformatieobjectenDestroy(body: [], existingObject: ['id' => self::IO_UUID]);

		$this->assertSame(400, $byUuid['status']);
		$this->assertSame('Het informatieobject kan niet verwijderd worden omdat er nog relaties aan gekoppeld zijn.', $byUuid['detail']);
		$this->assertSame('nonFieldErrors', $byUuid['invalidParams'][0]['name']);
		$this->assertSame('pending-relations', $byUuid['invalidParams'][0]['code']);
		$this->assertSame('pending-relations', $byUrl['invalidParams'][0]['code']);
		$this->assertSame(
			[
				['document' => self::IO_UUID, '_limit' => 100, '@schema' => 'document_link_schema'],
				['document' => self::IO_UUID, '_limit' => 100, '@schema' => 'document_link_schema'],
				['document' => '%'.self::IO_UUID.'%', '_limit' => 100, '@schema' => 'document_link_schema'],
			],
			$queries
		);

	}//end testDestroyIsRefusedWhileARelationExists()

	/**
	 * Swap the non-array hit of a handler's answer for an entity that serialises to a relation.
	 *
	 * @param \Closure $handler The search handler
	 *
	 * @return \Closure
	 */
	private function serialisable(\Closure $handler): \Closure {
		return static function (array $query) use ($handler): array {
			$answer = $handler($query);
			foreach ($answer['results'] as $index => $hit) {
				if (is_array($hit) === false) {
					$answer['results'][$index] = new class implements \JsonSerializable {
						public function jsonSerialize(): array {
							return ['id' => 'oio-entity'];
						}//end jsonSerialize()
					};
				}
			}

			return $answer;
		};

	}//end serialisable()

	/**
	 * Drc-007: no relation, or a relation search that fails, lets the delete through.
	 *
	 * @return void
	 */
	public function testDestroyPassesWithoutARelationOrWhenTheSearchFails(): void {
		$none = $this->buildService(objects: [])
			->rulesEnkelvoudiginformatieobjectenDestroy(body: ['x' => 1], existingObject: ['id' => self::IO_UUID]);

		$this->searchHandler = static function (array $query): array {
			throw new \RuntimeException('down');
		};
		$failed = $this->buildService(objects: [])
			->rulesEnkelvoudiginformatieobjectenDestroy(body: [], existingObject: ['id' => self::IO_UUID]);

		$this->assertTrue($none['valid']);
		$this->assertSame(['x' => 1], $none['enrichedBody']);
		$this->assertTrue($failed['valid']);

	}//end testDestroyPassesWithoutARelationOrWhenTheSearchFails()

	/**
	 * Drc-003: a relation stored by uuid, or found only by full text, is a duplicate too.
	 *
	 * @return void
	 */
	public function testDuplicateIsFoundByUuidAndByFullText(): void {
		$this->searchHandler = static fn (array $query): array => ['total' => (int)(($query['document'] ?? '') === self::IO_UUID)];
		$byUuid = $this->buildService(objects: [])->rulesObjectinformatieobjectenCreate(body: $this->caseLink());

		$this->searchHandler = static fn (array $query): array => [
			'results' => array_fill(0, (int)(($query['_search'] ?? '') === self::IO_UUID.' '.self::CASE_UUID), ['id' => 'oio-1']),
		];
		$byText = $this->buildService(objects: [])->rulesObjectinformatieobjectenCreate(body: $this->caseLink());

		$this->assertSame('unique', $byUuid['invalidParams'][0]['code']);
		$this->assertSame('unique', $byText['invalidParams'][0]['code']);

	}//end testDuplicateIsFoundByUuidAndByFullText()

	/**
	 * Drc-003a/b: a ZaakInformatieObject or BesluitInformatieObject for the same pair is a duplicate.
	 *
	 * @return void
	 */
	public function testRelationInZrcOrBrcIsADuplicate(): void {
		$queries = [];
		$this->searchHandler = static function (array $query) use (&$queries): array {
			$queries[] = $query;
			return ['total' => (int)(in_array($query['@schema'], ['case_document_schema', 'decision_document_schema'], true))];
		};
		$decisionUrl = 'https://brc.example.com/besluiten/'.self::CASE_UUID;

		$case     = $this->buildService(objects: [])->rulesObjectinformatieobjectenCreate(body: $this->caseLink());
		$decision = $this->buildService(objects: [])->rulesObjectinformatieobjectenCreate(
			body: ['informatieobject' => self::IO_URL, 'object' => $decisionUrl, 'objectType' => 'decision']
		);

		$this->assertSame('unique', $case['invalidParams'][0]['code']);
		$this->assertSame('unique', $decision['invalidParams'][0]['code']);
		$this->assertSame(
			['document' => self::IO_URL, 'object' => self::CASE_URL, '_limit' => 1, '@schema' => 'document_link_schema'],
			$queries[0]
		);
		$this->assertSame(
			['document' => self::IO_URL, 'case' => self::CASE_URL, '_limit' => 1, '@schema' => 'case_document_schema'],
			$queries[3]
		);
		$this->assertSame(
			['document' => self::IO_URL, 'decision' => $decisionUrl, '_limit' => 1, '@schema' => 'decision_document_schema'],
			$queries[7]
		);

	}//end testRelationInZrcOrBrcIsADuplicate()

	/**
	 * Drc-003 fails closed: a duplicate search that cannot answer counts as a duplicate.
	 *
	 * @return void
	 */
	public function testFailedDuplicateSearchCountsAsADuplicate(): void {
		$this->searchHandler = static function (array $query): array {
			throw new \RuntimeException('down');
		};

		$result = $this->buildService(objects: [])->rulesObjectinformatieobjectenCreate(body: $this->caseLink());

		$this->assertSame('unique', $result['invalidParams'][0]['code']);

	}//end testFailedDuplicateSearchCountsAsADuplicate()

	/**
	 * Drc-004 fails open: a cross-register search that cannot answer lets the relation through.
	 *
	 * The cross-register search is the one that names the case before the document.
	 *
	 * @return void
	 */
	public function testFailedCrossRegisterSearchPasses(): void {
		$this->searchHandler = static function (array $query): array {
			if (array_key_first($query) === 'case') {
				throw new \RuntimeException('down');
			}

			return ['total' => 0];
		};

		$result = $this->buildService(objects: [])->rulesObjectinformatieobjectenCreate(body: $this->caseLink());

		$this->assertTrue($result['valid']);

	}//end testFailedCrossRegisterSearchPasses()

	/**
	 * Drc-003 and drc-004 read the same ZaakInformatieObject: present it is a duplicate, absent it is
	 * inconsistent. With a context and an objectType no body passes both.
	 *
	 * @return void
	 */
	public function testAKnownObjectTypeNeverPassesBothRelationRules(): void {
		$this->searchHandler = static fn (array $query): array => ['total' => (int)($query['@schema'] === 'case_document_schema')];
		$present = $this->buildService(objects: [])->rulesObjectinformatieobjectenCreate(body: $this->caseLink());

		$this->searchHandler = static fn (array $query): array => ['total' => 0];
		$absent = $this->buildService(objects: [])->rulesObjectinformatieobjectenCreate(body: $this->caseLink());

		$this->assertSame('unique', $present['invalidParams'][0]['code']);
		$this->assertSame('inconsistent-relation', $absent['invalidParams'][0]['code']);

	}//end testAKnownObjectTypeNeverPassesBothRelationRules()

	/**
	 * Without a register the relation rules look nothing up.
	 *
	 * @return void
	 */
	public function testRelationRulesNeedARegister(): void {
		$this->searchTotal = 1;

		$result = $this->buildService(objects: [], mappingConfig: [])->rulesObjectinformatieobjectenCreate(body: $this->caseLink());

		$this->assertTrue($result['valid']);

	}//end testRelationRulesNeedARegister()
}//end class
