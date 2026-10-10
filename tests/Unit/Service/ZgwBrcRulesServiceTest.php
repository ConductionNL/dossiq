<?php

/**
 * Characterisation tests for ZgwBrcRulesService.
 *
 * Pins the besluit rules (brc-001, brc-002, brc-004, brc-007, brc-008) through the public
 * rules methods, so the decomposition of the cross-register checks (method-decomposition)
 * can be proven to change no outcome.
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
use OCA\Dossiq\Service\ZgwBrcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ObjectService shape the BRC rules call with named arguments.
 */
interface BrcObjectServiceStub {
	public function find(string $id, string $register, string $schema): ?array;

	public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

	public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * Characterisation tests for the BRC rules.
 *
 * @covers \OCA\Dossiq\Service\ZgwBrcRulesService
 * @covers \OCA\Dossiq\Service\ZgwRulesBase
 *
 * @uses \OCA\Dossiq\Service\FieldValidator
 */
class ZgwBrcRulesServiceTest extends TestCase {

	private const CASE_UUID = '10000000-1111-2222-3333-444455556666';
	private const ZT_UUID = '20000000-1111-2222-3333-444455556666';
	private const OTHER_ZT_UUID = '21000000-1111-2222-3333-444455556666';
	private const BT_UUID = '30000000-1111-2222-3333-444455556666';
	private const DECISION_UUID = '40000000-1111-2222-3333-444455556666';
	private const IO_UUID = '50000000-1111-2222-3333-444455556666';
	private const DT_UUID = '60000000-1111-2222-3333-444455556666';

	private const MISSING_IOT = 'Het informatieobjecttype van het informatieobject is niet gespecificeerd in het besluittype.informatieobjecttypen.';

	/**
	 * Total the uniqueness search reports.
	 *
	 * @var integer
	 */
	private int $searchTotal = 0;

	/**
	 * Build the service with an ObjectService answering from a uuid map.
	 *
	 * @param array<string, array>|null $objects Objects by uuid, or null for no context
	 *
	 * @return ZgwBrcRulesService
	 */
	private function buildService(?array $objects): ZgwBrcRulesService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('schema-x');

		$service = new ZgwBrcRulesService(
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settings,
			fieldValidator: new FieldValidator()
		);

		if ($objects !== null) {
			$objectService = $this->createMock(BrcObjectServiceStub::class);
			$objectService->method('find')->willReturnCallback(
				static function (string $id, string $register, string $schema) use ($objects): ?array {
					return $objects[$id] ?? null;
				}
			);
			$objectService->method('buildSearchQuery')->willReturn([]);
			$objectService->method('searchObjectsPaginated')->willReturnCallback(
				fn (array $query): array => ['total' => $this->searchTotal, 'results' => []]
			);
			$service->setContext(
				objectService: $objectService,
				mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
			);
		}

		return $service;

	}//end buildService()

	/**
	 * The objects of a zaak whose zaaktype is ZT, plus a published besluittype.
	 *
	 * @param array $besluittype Extra besluittype fields
	 * @param array $zaaktype    Extra zaaktype fields
	 *
	 * @return array<string, array>
	 */
	private function caseWorld(array $besluittype, array $zaaktype=[]): array {
		return [
			self::CASE_UUID => ['id' => self::CASE_UUID, 'caseType' => 'https://ztc.example.com/zaaktypen/'.self::ZT_UUID],
			self::ZT_UUID   => array_merge(['id' => self::ZT_UUID], $zaaktype),
			self::BT_UUID   => array_merge(['id' => self::BT_UUID, 'isDraft' => false, 'title' => 'Vergunning'], $besluittype),
		];

	}//end caseWorld()

	/**
	 * Create a besluit for the zaak against the besluittype.
	 *
	 * @param ZgwBrcRulesService $service The service
	 * @param array              $extra   Extra body fields
	 *
	 * @return array
	 */
	private function createBesluit(ZgwBrcRulesService $service, array $extra=[]): array {
		return $service->rulesBesluitenCreate(
			body: array_merge(
				[
					'besluittype' => 'https://ztc.example.com/besluittypen/'.self::BT_UUID,
					'case'        => 'https://zaken.example.com/zaken/'.self::CASE_UUID,
				],
				$extra
			)
		);

	}//end createBesluit()

	/**
	 * Brc-007: the besluittype listing the zaak's zaaktype passes.
	 *
	 * @return void
	 */
	public function testBesluittypeListingTheZaaktypePasses(): void {
		$service = $this->buildService(
			objects: $this->caseWorld(besluittype: ['caseTypes' => ['https://ztc.example.com/zaaktypen/'.self::ZT_UUID]])
		);

		$result = $this->createBesluit(service: $service);

		$this->assertTrue($result['valid']);
		$this->assertStringStartsWith('BESLUIT', $result['enrichedBody']['identificatie']);

	}//end testBesluittypeListingTheZaaktypePasses()

	/**
	 * Brc-007: the besluittype's zaaktypen may arrive as a JSON string.
	 *
	 * @return void
	 */
	public function testBesluittypeZaaktypenAsJsonStringPass(): void {
		$service = $this->buildService(
			objects: $this->caseWorld(besluittype: ['caseTypes' => json_encode(['https://ztc.example.com/zaaktypen/'.self::ZT_UUID])])
		);

		$this->assertTrue($this->createBesluit(service: $service)['valid']);

	}//end testBesluittypeZaaktypenAsJsonStringPass()

	/**
	 * Brc-007: the zaaktype listing the besluittype by uuid passes (the other direction).
	 *
	 * @return void
	 */
	public function testZaaktypeListingTheBesluittypeUuidPasses(): void {
		$service = $this->buildService(
			objects: $this->caseWorld(
				besluittype: ['caseTypes' => ['https://ztc.example.com/zaaktypen/'.self::OTHER_ZT_UUID]],
				zaaktype: ['decisionTypes' => json_encode(['https://ztc.example.com/besluittypen/'.self::BT_UUID])]
			)
		);

		$this->assertTrue($this->createBesluit(service: $service)['valid']);

	}//end testZaaktypeListingTheBesluittypeUuidPasses()

	/**
	 * Brc-007: the zaaktype listing the besluittype by its omschrijving passes.
	 *
	 * @return void
	 */
	public function testZaaktypeListingTheBesluittypeTitlePasses(): void {
		$service = $this->buildService(
			objects: $this->caseWorld(besluittype: [], zaaktype: ['decisionTypes' => ['Vergunning']])
		);

		$this->assertTrue($this->createBesluit(service: $service)['valid']);

	}//end testZaaktypeListingTheBesluittypeTitlePasses()

	/**
	 * Brc-007: neither direction relates them, so the besluit is refused.
	 *
	 * @return void
	 */
	public function testUnrelatedZaaktypeAndBesluittypeAreRefused(): void {
		$service = $this->buildService(
			objects: $this->caseWorld(
				besluittype: ['caseTypes' => 'not json', 'title' => ''],
				zaaktype: ['decisionTypes' => ['', 'Iets anders']]
			)
		);

		$result = $this->createBesluit(service: $service);

		$this->assertFalse($result['valid']);
		$this->assertSame(400, $result['status']);
		$this->assertSame('Het zaaktype van de zaak is niet gerelateerd aan het besluittype.', $result['detail']);
		$this->assertSame('nonFieldErrors', $result['invalidParams'][0]['name']);
		$this->assertSame('zaaktype-mismatch', $result['invalidParams'][0]['code']);

	}//end testUnrelatedZaaktypeAndBesluittypeAreRefused()

	/**
	 * Brc-007: an unknown zaak skips the relation check.
	 *
	 * @return void
	 */
	public function testUnknownZaakSkipsTheRelationCheck(): void {
		$world = $this->caseWorld(besluittype: []);
		unset($world[self::CASE_UUID]);

		$this->assertTrue($this->createBesluit(service: $this->buildService(objects: $world))['valid']);

	}//end testUnknownZaakSkipsTheRelationCheck()

	/**
	 * Brc-001: a draft besluittype is refused before the relation check runs.
	 *
	 * @return void
	 */
	public function testDraftBesluittypeIsRefused(): void {
		$service = $this->buildService(objects: $this->caseWorld(besluittype: ['isDraft' => true]));

		$result = $this->createBesluit(service: $service);

		$this->assertSame('not-published', $result['invalidParams'][0]['code']);

	}//end testDraftBesluittypeIsRefused()

	/**
	 * Brc-002: an identificatie that already exists is refused; a free one is kept.
	 *
	 * @return void
	 */
	public function testIdentificatieUniqueness(): void {
		$service = $this->buildService(
			objects: $this->caseWorld(besluittype: ['caseTypes' => ['https://ztc.example.com/zaaktypen/'.self::ZT_UUID]])
		);

		$free = $this->createBesluit(service: $service, extra: ['identificatie' => 'B-1']);
		$this->searchTotal = 1;
		$taken = $this->createBesluit(service: $service, extra: ['identificatie' => 'B-1']);

		$this->assertTrue($free['valid']);
		$this->assertSame('B-1', $free['enrichedBody']['identificatie']);
		$this->assertFalse($taken['valid']);
		$this->assertSame('identificatie-niet-uniek', $taken['invalidParams'][0]['code']);

	}//end testIdentificatieUniqueness()

	/**
	 * Without a context nothing is looked up and an identificatie is generated.
	 *
	 * @return void
	 */
	public function testWithoutContextOnlyTheIdentificatieIsGenerated(): void {
		$result = $this->createBesluit(service: $this->buildService(objects: null));

		$this->assertTrue($result['valid']);
		$this->assertStringStartsWith('BESLUIT', $result['enrichedBody']['identificatie']);

	}//end testWithoutContextOnlyTheIdentificatieIsGenerated()

	/**
	 * The objects for a besluit, its besluittype, a document and its documenttype.
	 *
	 * @param mixed  $documentTypes The besluittype's allowed document types
	 * @param string $docTypeName   The documenttype's name
	 *
	 * @return array<string, array>
	 */
	private function bioWorld(mixed $documentTypes, string $docTypeName): array {
		return [
			self::DECISION_UUID => ['id' => self::DECISION_UUID, 'decisionType' => 'https://ztc.example.com/besluittypen/'.self::BT_UUID],
			self::BT_UUID       => ['id' => self::BT_UUID, 'documentTypes' => $documentTypes],
			self::IO_UUID       => ['id' => self::IO_UUID, 'documentType' => 'https://ztc.example.com/informatieobjecttypen/'.self::DT_UUID],
			self::DT_UUID       => ['id' => self::DT_UUID, 'name' => $docTypeName],
		];

	}//end bioWorld()

	/**
	 * Link the document to the besluit.
	 *
	 * @param ZgwBrcRulesService $service The service
	 *
	 * @return array
	 */
	private function linkDocument(ZgwBrcRulesService $service): array {
		return $service->rulesBesluitinformatieobjectenCreate(
			body: [
				'decision'         => 'https://brc.example.com/besluiten/'.self::DECISION_UUID,
				'informatieobject' => 'https://drc.example.com/enkelvoudiginformatieobjecten/'.self::IO_UUID,
			]
		);

	}//end linkDocument()

	/**
	 * Brc-008: a documenttype the besluittype allows by name passes, and brc-004 fills the relation.
	 *
	 * @return void
	 */
	public function testAllowedDocumentTypeByNamePasses(): void {
		$result = $this->linkDocument(service: $this->buildService(objects: $this->bioWorld(documentTypes: ['Brief'], docTypeName: 'Brief')));

		$this->assertTrue($result['valid']);
		$this->assertSame('Legt vast, omgekeerd: wordt vastgelegd door', $result['enrichedBody']['natureRelationshipDisplay']);

	}//end testAllowedDocumentTypeByNamePasses()

	/**
	 * Brc-008: a documenttype the besluittype allows by uuid, listed as a JSON string, passes.
	 *
	 * @return void
	 */
	public function testAllowedDocumentTypeByUuidPasses(): void {
		$service = $this->buildService(objects: $this->bioWorld(documentTypes: json_encode([self::DT_UUID]), docTypeName: 'Brief'));

		$this->assertTrue($this->linkDocument(service: $service)['valid']);

	}//end testAllowedDocumentTypeByUuidPasses()

	/**
	 * Brc-008: a documenttype the besluittype does not allow is refused.
	 *
	 * @return void
	 */
	public function testDisallowedDocumentTypeIsRefused(): void {
		$result = $this->linkDocument(service: $this->buildService(objects: $this->bioWorld(documentTypes: ['Brief'], docTypeName: 'Notitie')));

		$this->assertFalse($result['valid']);
		$this->assertSame(self::MISSING_IOT, $result['detail']);
		$this->assertSame('missing-informatieobjecttype', $result['invalidParams'][0]['code']);
		$this->assertSame('nonFieldErrors', $result['invalidParams'][0]['name']);

	}//end testDisallowedDocumentTypeIsRefused()

	/**
	 * Brc-008: a besluittype allowing no documenttypes refuses every document, even an unknown one.
	 *
	 * @return void
	 */
	public function testBesluittypeWithoutDocumentTypesRefusesEverything(): void {
		$world = $this->bioWorld(documentTypes: [], docTypeName: 'Brief');
		unset($world[self::IO_UUID]);

		$result = $this->linkDocument(service: $this->buildService(objects: $world));

		$this->assertSame(self::MISSING_IOT, $result['detail']);

	}//end testBesluittypeWithoutDocumentTypesRefusesEverything()

	/**
	 * Brc-008: a besluittype without the field reads as an empty list, so it refuses too.
	 *
	 * @return void
	 */
	public function testBesluittypeWithoutTheFieldRefuses(): void {
		$world = $this->bioWorld(documentTypes: [], docTypeName: 'Brief');
		unset($world[self::BT_UUID]['documentTypes']);

		$this->assertSame('missing-informatieobjecttype', $this->linkDocument(service: $this->buildService(objects: $world))['invalidParams'][0]['code']);

	}//end testBesluittypeWithoutTheFieldRefuses()

	/**
	 * Brc-008: an unknown document or besluit skips the check.
	 *
	 * @return void
	 */
	public function testUnknownDocumentOrBesluitSkips(): void {
		$noDocument = $this->bioWorld(documentTypes: ['Brief'], docTypeName: 'Notitie');
		unset($noDocument[self::IO_UUID]);
		$noBesluit = $this->bioWorld(documentTypes: ['Brief'], docTypeName: 'Notitie');
		unset($noBesluit[self::DECISION_UUID]);
		$noDocType = $this->bioWorld(documentTypes: ['Brief'], docTypeName: 'Notitie');
		unset($noDocType[self::DT_UUID]);

		$this->assertTrue($this->linkDocument(service: $this->buildService(objects: $noDocument))['valid']);
		$this->assertTrue($this->linkDocument(service: $this->buildService(objects: $noBesluit))['valid']);
		$this->assertTrue($this->linkDocument(service: $this->buildService(objects: $noDocType))['valid']);

	}//end testUnknownDocumentOrBesluitSkips()
}//end class
