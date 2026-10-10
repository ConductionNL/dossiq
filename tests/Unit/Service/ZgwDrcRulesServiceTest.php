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
	 * Build the service with an ObjectService answering from a uuid map.
	 *
	 * @param array<string, array>|null $objects Objects by uuid, or null for no context
	 *
	 * @return ZgwDrcRulesService
	 */
	private function buildService(?array $objects): ZgwDrcRulesService {
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
				static fn (array $requestParams, string $register, string $schema): array => $requestParams
			);
			$objectService->method('searchObjectsPaginated')->willReturnCallback(
				fn (array $query, bool $_rbac=true, bool $_multitenancy=true): array => [
					'total'   => $this->searchTotal,
					'results' => $this->searchResults,
				]
			);
			$service->setContext(
				objectService: $objectService,
				mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
			);
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
}//end class
